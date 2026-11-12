"""Fit six strictly isolated category classifiers; never grants human approval.

Fixed C16, road cutoff .65 / other cutoffs .50. All authored scenarios and
context variants are train-only. Preserve the two existing source partitions;
evaluate their exposed synthetic diagnostics without tuning on test results.
"""
from __future__ import annotations

import argparse
from collections import Counter
import csv
import hashlib
import json
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from augment_multiincident_contexts import augment, vocabulary
from evaluate_text_cues import evaluate_rows
from src.ai_service.incident_types import INCIDENT_TYPES
from src.ai_service.text_inference import CategoryTfidfSeverityPredictor
from src.ai_service.text_training import (CATEGORY_COLUMNS, LABELS, LANGUAGES, _manifest, _metadata,
                                         build_parser, experiment_splits, load_dataset, run_baseline)

EXPERIMENT = ROOT / 'datasets/emergency_text/experiments/category_experts_20261112'
VERSION = 'category-experts-20261112-v1'


def write_json(path: Path, value) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open('w', encoding='utf-8', newline='') as handle:
        handle.write(json.dumps(value, ensure_ascii=False, indent=2, allow_nan=False) + '\n')


def write_csv(path: Path, rows: list[dict]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open('w', encoding='utf-8', newline='') as handle:
        writer = csv.DictWriter(handle, fieldnames=CATEGORY_COLUMNS)
        writer.writeheader()
        writer.writerows(rows)


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def draft_rows(path: Path) -> list[dict]:
    payload = json.loads(path.read_text(encoding='utf-8'))
    expected = ['incident_type', 'label', 'family_id', *LANGUAGES]
    if payload.get('schema_version') != 1 or payload.get('columns') != expected:
        raise ValueError('Authored draft schema does not match the training contract')
    rows, families = [], set()
    for entry in payload['rows']:
        if len(entry) != len(expected):
            raise ValueError('Incomplete authored scenario')
        item = dict(zip(expected, entry, strict=True))
        category, label, family = item['incident_type'], item['label'], item['family_id']
        if category not in INCIDENT_TYPES or label not in LABELS or family in families:
            raise ValueError('Invalid authored category, label or repeated family')
        families.add(family)
        for language in LANGUAGES:
            text = item[language]
            if not 8 <= len(text.split()) <= 60 or len(text) > 1000:
                raise ValueError('Unexpected authored text length')
            rows.append(dict(zip(CATEGORY_COLUMNS, (
                f'NEW_{family}_{language}', f'NEW_{family}', language, text, label, 'draft', '',
                'AI-authored fictional training-only scenario. Provisional label; no human or language approval.', category,
            ), strict=True)))
    balance = Counter((row['incident_type'], row['label'], row['language']) for row in rows)
    if any(balance[(category, label, language)] < 2
           for category in INCIDENT_TYPES for label in LABELS for language in LANGUAGES):
        raise ValueError('Need at least two authored drafts for every category/label/language cell')
    return rows


def prepare(experiment: Path) -> tuple[Path, dict]:
    source = experiment / 'source'
    prepared = experiment / 'prepared'
    if source.exists() or prepared.exists():
        raise ValueError('Use a new experiment directory; source data is immutable')
    multi = ROOT / 'datasets/emergency_text/experiments/multiincident_common_20261109/combined_plain'
    road_csv = ROOT / 'datasets/emergency_text/experiments/full_pool_20261011/reports.csv'
    road_manifest = ROOT / 'artifacts/reports/text_tfidf_20261016/split_manifest.json'
    multi_splits = experiment_splits(load_dataset(multi / 'reports.csv'),
                                   argparse.Namespace(split_manifest=str(multi / 'split_manifest.json')))
    road_splits = experiment_splits(load_dataset(road_csv), argparse.Namespace(split_manifest=str(road_manifest)))
    joined = {name: list(rows) for name, rows in multi_splits.items()}
    for name, rows in road_splits.items():
        for original in rows:
            row = {**original, 'case_id': 'ROADBASE_' + original['case_id'],
                   'group_id': 'ROADBASE_' + original['group_id'], 'incident_type': 'road_accident',
                   'review_status': 'draft', 'reviewer': '',
                   'notes': 'Derived copy of historical road corpus; original approval fields independently unverified. ' + original['notes']}
            joined[name].append(row)
    additions = draft_rows(experiment / 'new_scenarios.json')
    joined['train'].extend(additions)
    joined = {name: sorted(rows, key=lambda row: row['case_id']) for name, rows in joined.items()}
    write_csv(source / 'reports.csv', [row for rows in joined.values() for row in rows])
    write_json(source / 'split_manifest.json', _manifest(joined))
    # Validate source identity/groups/exact-text leakage before augmentation or fitting.
    experiment_splits(load_dataset(source / 'reports.csv'), argparse.Namespace(split_manifest=str(source / 'split_manifest.json')))
    provenance = {
        'synthetic': True, 'human_reviewed': False, 'approved_for_deployment': False,
        'new_authored_scenarios': len(additions) // 4, 'new_authored_descriptions': len(additions),
        'source_split_counts': {name: len(rows) for name, rows in joined.items()},
        'source_files_sha256': {str(path.relative_to(ROOT)): sha(path) for path in
                                (multi / 'reports.csv', multi / 'split_manifest.json', road_csv, road_manifest,
                                 experiment / 'new_scenarios.json')},
        'partition_policy': 'Preserve both old source partitions; new scenarios/variants only enter train. Original files and labels are unchanged.',
        'review_policy': 'Historical road approval fields are not independent validation; derived copies are explicitly drafts.',
        'limitations': 'Scene and language variants are correlated; not independent clinical observations or real-report validation.',
    }
    write_json(source / 'provenance.json', provenance)
    counts = augment(source, prepared,
                     ROOT / 'datasets/emergency_text/experiments/local_vocabulary_20261112/context_vocabulary.json',
                     prepend_context=True)
    return prepared, {**provenance, 'augmentation': counts}


def train(experiment: Path, output: Path) -> dict:
    if output.exists():
        raise ValueError('Release must be a new directory')
    prepared, provenance = prepare(experiment)
    rows = load_dataset(prepared / 'reports.csv')
    parent_args = build_parser().parse_args([
        'baseline', '--data', str(prepared / 'reports.csv'), '--output', str(output),
        '--split-manifest', str(prepared / 'split_manifest.json'), '--allow-draft',
        '--seed', '20261112', '--regularization-c', '16', '--review-threshold', '.5',
    ])
    splits = experiment_splits(rows, parent_args)
    output.mkdir(parents=True)
    write_json(output / 'training_plan.json', {
        'model_strategy': 'six classifiers; each vectorizer and logistic regression fitted only on its own incident type',
        'regularization_c': 16, 'review_thresholds': {cat: .65 if cat == 'road_accident' else .5 for cat in INCIDENT_TYPES},
        'seed': 20261112, 'test_for_selection': False, 'human_reviewed': False,
        'activation': 'separate explicit user-authorized experimental advisory selector; no autonomous dispatch',
    })
    bindings, counts = {}, {}
    for category in INCIDENT_TYPES:
        selected = {name: [row for row in items if row['incident_type'] == category]
                    for name, items in splits.items()}
        if set(row['label'] for row in selected['train']) != set(LABELS):
            raise ValueError('Category training partition must contain all four labels')
        data = experiment / 'by_category' / category
        write_csv(data / 'reports.csv', [row for items in selected.values() for row in items])
        write_json(data / 'split_manifest.json', _manifest(selected))
        child = output / 'experts' / category
        args = build_parser().parse_args([
            'baseline', '--data', str(data / 'reports.csv'), '--output', str(child),
            '--split-manifest', str(data / 'split_manifest.json'), '--allow-draft',
            '--seed', '20261112', '--regularization-c', '16',
            '--review-threshold', '.65' if category == 'road_accident' else '.5',
        ])
        run_baseline(args)
        metadata = json.loads((child / 'metadata.json').read_text(encoding='utf-8'))
        if metadata['supported_incident_types'] != [category]:
            raise AssertionError('A category expert received another category during fitting')
        metadata.update({'training_run_version': metadata['version'], 'version': VERSION + '/' + category,
                         'display_name': 'AksyRes ' + category.replace('_', ' ').title() + ' Text AI (Experimental)',
                         'text_rule_profile': 'multiincident_common_v1', 'text_rule_incident_types': [category],
                         'human_reviewed': False, 'independent_validation': False, 'pilot_only': True,
                         'training_incident_type': category, 'training_category_isolation': True})
        write_json(child / 'metadata.json', metadata)
        for filename in ('validation_metrics.json', 'validation_predictions.json'):
            report = json.loads((child / filename).read_text(encoding='utf-8'))
            report['model_version'] = metadata['version']
            write_json(child / filename, report)
        bindings[category] = {'directory': 'experts/' + category,
                              'metadata_sha256': sha(child / 'metadata.json'),
                              'artifact_sha256': metadata['artifact_sha256']}
        counts[category] = {name: len(items) for name, items in selected.items()}
    write_json(output / 'category_models.json', {'schema_version': 1, 'models': bindings})
    metadata = _metadata(rows, parent_args, 'tfidf_category_router', splits['train'])
    metadata.update({'version': VERSION, 'display_name': 'AksyRes Incident-Specific Text AI (Experimental)',
                     'base_model': 'six-isolated-tfidf-logistic-regression-classifiers',
                     'artifact_sha256': sha(output / 'category_models.json'),
                     'text_rule_profile': 'multiincident_common_v1', 'text_rule_incident_types': list(INCIDENT_TYPES),
                     'category_model_counts': counts, 'human_reviewed': False, 'independent_validation': False,
                     'pilot_only': True, 'admin_confirmation_required': True,
                     'experimental_release_reason': 'Owner explicitly requested category-isolated multi-incident advisory activation; no validated real-report accuracy or clinical approval.'})
    write_json(output / 'metadata.json', metadata)
    write_json(output / 'split_manifest.json', _manifest(splits))
    predictor = CategoryTfidfSeverityPredictor(output, allow_experimental=True)
    report = {'provenance': provenance, 'counts_by_category': counts,
              'counts': {name: len(items) for name, items in splits.items()},
              'human_reviewed': False, 'independent_validation': False, 'approved_for_deployment': False,
              'model_selection': 'No search. One fixed C16 fit per category; all test outputs are exposed synthetic diagnostics.'}
    for name in ('validation', 'test'):
        metrics, predictions = evaluate_rows(splits[name], predictor)
        write_json(output / f'{name}_policy_metrics.json', metrics)
        write_json(output / f'{name}_policy_predictions.json', {'rows': predictions})
        report[name] = {key: metrics[key] for key in ('count', 'accuracy', 'macro_f1', 'critical_recall',
                                                    'high_risk_underclassification_count', 'coverage')}
        report[name]['by_category'] = {cat: {key: metric[key] for key in ('count', 'accuracy', 'critical_recall', 'high_risk_underclassification_count')}
                                       for cat, metric in metrics['per_incident_type'].items()}
    write_json(output / 'evaluation_report.json', report)
    write_json(output / 'training_vocabulary.json', vocabulary(splits['train']))
    policies = ['src/ai_service/text_inference.py', 'src/ai_service/multiincident_cues.py',
                'src/ai_service/text_cues.py', 'src/ai_service/incident_types.py', 'evaluate_text_cues.py']
    write_json(output / 'tested_policy_context.json', {
        'metadata_sha256': sha(output / 'metadata.json'), 'artifact_sha256': metadata['artifact_sha256'],
        'policy_source_lf_sha256': {name: hashlib.sha256((ROOT / name).read_bytes().replace(b'\r\n', b'\n')).hexdigest() for name in policies},
        'independent_validation': False, 'approved_for_deployment': False,
    })
    return report


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--experiment', type=Path, default=EXPERIMENT)
    parser.add_argument('--output', type=Path, default=ROOT / 'releases' / VERSION)
    args = parser.parse_args()
    print(json.dumps(train(args.experiment, args.output), ensure_ascii=True, allow_nan=False))


if __name__ == '__main__':
    main()
