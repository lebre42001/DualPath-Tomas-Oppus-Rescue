"""Reproducible synthetic vocabulary experiment; never grants human approval.

Translations and shared semantic families stay together across all categories.
Model/threshold selection uses validation only. Final diagnostic outputs are
written separately, once, after selection; no command activates the model.
"""
from __future__ import annotations

import argparse
from collections import Counter
import csv
import hashlib
import json
from pathlib import Path
import random
import re
import shutil
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from src.ai_service.incident_types import INCIDENT_TYPES
from src.ai_service.text_training import (
    CATEGORY_COLUMNS, LABELS, LANGUAGES, _manifest, build_parser as training_parser,
    dataset_fingerprint, experiment_splits, load_dataset, normalized_text, run_baseline,
)

SEED = 20261109


def write_json(path, value):
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2, allow_nan=False) + '\n', encoding='utf-8')


def read_scenarios(path, *, allow_label_variants=False):
    payload = json.loads(path.read_text(encoding='utf-8-sig'))
    scenarios = payload['scenarios'] if isinstance(payload, dict) else payload
    if not isinstance(scenarios, list) or not scenarios:
        raise ValueError('Scenario source must be a nonempty list')
    ids, families, texts, rows, row_families = set(), {}, {}, [], {}
    for item in scenarios:
        identifier, family = item['id'], item['family_id']
        label, category = item['label'], item['incident_type']
        if (not re.fullmatch(r'[a-z0-9_]+', identifier) or identifier in ids
                or not re.fullmatch(r'[a-z0-9_]+', family)
                or label not in LABELS or category not in INCIDENT_TYPES):
            raise ValueError('Invalid or duplicate scenario identity/category/label')
        ids.add(identifier)
        if family in families and families[family] != label and not allow_label_variants:
            raise ValueError('A semantic family must not have conflicting labels')
        families[family] = label
        descriptions = item.get('descriptions', item)
        for language in LANGUAGES:
            text = descriptions[language].strip()
            if not 8 <= len(text.split()) <= 60 or len(text) > 1000:
                raise ValueError(f'Unexpected description length: {identifier}/{language}')
            key = normalized_text(text)
            # The external challenge contains severity-changing scene variants.
            # It is evaluated whole, never split; CSV groups must remain label-
            # homogeneous while row_families retains the broader scene family.
            group = category + '__' + family + ('__' + label if allow_label_variants else '')
            if key in texts and texts[key] != group:
                raise ValueError('Exact duplicate crosses semantic/category groups')
            texts[key] = group
            case = 'MIC_' + identifier.upper() + '_' + language.upper()
            rows.append(dict(zip(CATEGORY_COLUMNS, (
                case, group, language, text, label, 'draft', '',
                'AI-generated fictional example; provisional label, no human approval. ' + item['rationale'], category,
            ), strict=True)))
            row_families[case] = family
    return rows, row_families, families


def write_csv(path, rows):
    with path.open('w', encoding='utf-8', newline='') as handle:
        writer = csv.DictWriter(handle, fieldnames=CATEGORY_COLUMNS)
        writer.writeheader()
        writer.writerows(rows)


def prepare(source, output):
    if output.exists():
        raise ValueError('Prepared output must be a new directory')
    rows, row_families, families = read_scenarios(source)
    assigned, rng, balance_attempts = {}, random.Random(SEED), {}
    for label in LABELS:
        names = sorted(family for family, value in families.items() if value == label)
        if len(names) < 8:
            raise ValueError('Need at least eight distinct semantic families per label')
        n_holdout = max(2, int(len(names) * .2))
        category_families = {category: {row_families[row['case_id']] for row in rows
                                       if row['label'] == label and row['incident_type'] == category}
                             for category in INCIDENT_TYPES}
        found = False
        for quota in range(n_holdout, (len(names) - 1) // 2 + 1):
            for attempt in range(10000):
                proposal = names.copy()
                rng.shuffle(proposal)
                parts = {'validation': set(proposal[:quota]), 'test': set(proposal[quota:2 * quota]),
                         'train': set(proposal[2 * quota:])}
                # Balance from labels/category membership only, before fitting
                # or score inspection; never select by prediction quality.
                if all(part & members for part in parts.values() for members in category_families.values()):
                    for name, members in parts.items():
                        assigned.update({family: name for family in members})
                    balance_attempts[label] = {'attempts': attempt + 1, 'holdout_family_quota': quota}
                    found = True
                    break
            if found:
                break
        if not found:
            raise ValueError('Cannot balance category coverage while keeping complete semantic families together')
    splits = {name: sorted([row for row in rows if assigned[row_families[row['case_id']]] == name],
                           key=lambda row: row['case_id']) for name in ('train', 'validation', 'test')}
    for name, selected in splits.items():
        counts = Counter((row['incident_type'], row['language'], row['label']) for row in selected)
        if any(counts[(category, language, label)] < 1
               for category in INCIDENT_TYPES for language in LANGUAGES for label in LABELS):
            raise ValueError(f'{name} is missing a category/language/label cell')
    output.mkdir(parents=True)
    write_csv(output / 'reports.csv', rows)
    write_json(output / 'split_manifest.json', _manifest(splits))
    # Use the same loader/manifest validation as training, not a second schema.
    args = argparse.Namespace(split_manifest=str(output / 'split_manifest.json'))
    experiment_splits(load_dataset(output / 'reports.csv'), args)
    train_words = {}
    for language in LANGUAGES:
        counter = Counter(word.casefold() for row in splits['train'] if row['language'] == language
                          for word in re.findall(r"[^\W\d_]+(?:[-'][^\W\d_]+)*", row['text']))
        train_words[language] = {'unique_words': len(counter), 'common_words': counter.most_common(100)}
    write_json(output / 'training_vocabulary.json', {
        'scope': 'training partition only; not a medical dictionary', 'languages': train_words,
    })
    write_json(output / 'provenance.json', {
        'schema_version': 1, 'synthetic': True, 'human_reviewed': False,
        'source_sha256': hashlib.sha256(source.read_bytes()).hexdigest(), 'seed': SEED,
        'balance_attempts_by_label': balance_attempts,
        'rows': len(rows), 'global_semantic_families': len(families),
        'family_partitions': assigned, 'case_families': row_families,
        'split_counts': {name: len(items) for name, items in splits.items()},
        'family_split_policy': 'All category contexts and translations of a semantic family stay in one partition.',
        'independent_real_world_evaluation': False, 'approved_for_deployment': False,
    })
    return {'rows': len(rows), 'families': len(families), 'splits': {key: len(value) for key, value in splits.items()}}


def fit(prepared, output, *, expanded_profile=False, reuse_fits=None):
    from evaluate_text_cues import evaluate_rows
    from src.ai_service.text_inference import TfidfSeverityPredictor

    if output.exists():
        raise ValueError('Experiment output must be a new directory')
    output.mkdir(parents=True)
    # Freeze this search before inspecting test or external challenge results.
    thresholds = [.5, .6, .65, .7] if expanded_profile else [.65]
    policy_files = ['src/ai_service/text_cues.py', 'src/ai_service/multiincident_cues.py',
                    'src/ai_service/text_inference.py', 'evaluate_text_cues.py']
    policy_hashes = {name: hashlib.sha256((ROOT / name).read_bytes()).hexdigest()
                     for name in policy_files if (ROOT / name).exists()}
    plan = {'regularization_c': [1, 4, 16, 64], 'review_thresholds': thresholds,
            'text_rule_profile': 'multiincident_common_v1' if expanded_profile else 'legacy_road_v2',
            'selection': ('Validation only: prefer zero urgent lower assignments and Critical recall >=.95; '
                          'then fewer urgent lower assignments, higher Critical recall, policy macro-F1, raw macro-F1.'),
            'test_used_for_selection': False, 'human_reviewed': False, 'policy_source_sha256': policy_hashes,
            'reuse_unchanged_fitted_weights': reuse_fits is not None}
    write_json(output / 'experiment_plan.json', plan)
    trials = []
    for c_value in plan['regularization_c']:
        directory = output / ('c' + str(c_value))
        args = training_parser().parse_args([
            'baseline', '--data', str(prepared / 'reports.csv'), '--output', str(directory),
            '--split-manifest', str(prepared / 'split_manifest.json'), '--allow-draft',
            '--seed', str(SEED), '--regularization-c', str(c_value), '--review-threshold', '.65',
        ])
        if reuse_fits is None:
            run_baseline(args)
        else:
            source = reuse_fits / directory.name
            prior = json.loads((source / 'metadata.json').read_text())
            if (prior['dataset_fingerprint'] != dataset_fingerprint(load_dataset(prepared / 'reports.csv'))
                    or prior['regularization_c'] != c_value
                    or prior.get('test_evaluated_during_training') is not False):
                raise ValueError('Reusable fit has different training data/parameters or exposed test metrics')
            directory.mkdir()
            for name in ('baseline.json', 'metadata.json', 'split_manifest.json', 'validation_metrics.json'):
                shutil.copyfile(source / name, directory / name)
            if (json.loads((source / 'split_manifest.json').read_text())
                    != json.loads((prepared / 'split_manifest.json').read_text())):
                raise ValueError('Reusable fit partitions changed')
        value = json.loads((directory / 'validation_metrics.json').read_text())
        validation = experiment_splits(load_dataset(prepared / 'reports.csv'), args)['validation']
        if expanded_profile:
            metadata = json.loads((directory / 'metadata.json').read_text())
            metadata.update({'text_rule_profile': 'multiincident_common_v1',
                             'text_rule_incident_types': list(INCIDENT_TYPES)})
            write_json(directory / 'metadata.json', metadata)
        predictor = TfidfSeverityPredictor(directory, allow_experimental=True)
        for threshold in thresholds:
            predictor.metadata['review_threshold'] = threshold
            policy, _ = evaluate_rows(validation, predictor)
            write_json(directory / f'validation_policy_t{threshold}.json', policy)
            trials.append({'c': c_value, 'threshold': threshold, 'directory': directory.name,
                           'validation': value, 'validation_policy': policy})
    def key(trial):
        metric = trial['validation']
        policy = trial['validation_policy']
        lower = policy['high_risk_underclassification_count']
        critical = policy['critical_recall']
        return (lower == 0 and critical >= .95, -lower, critical, policy['macro_f1'],
                metric['raw_classifier']['macro_f1'])
    if any(hashlib.sha256((ROOT / name).read_bytes()).hexdigest() != value for name, value in policy_hashes.items()):
        raise ValueError('Policy code changed during selection; preserve these diagnostics and run a new version')
    chosen = max(trials, key=key)
    write_json(output / 'selection.json', {
        'selected_directory': chosen['directory'], 'regularization_c': chosen['c'],
        'review_threshold': chosen['threshold'],
        'selection_partition': 'validation', 'trials': trials,
        'approved_for_deployment': False, 'test_inspected': False,
    })
    # A new immutable selected candidate keeps the exact chosen runtime parameters.
    selected = output / 'selected'
    selected.mkdir()
    for name in ('baseline.json', 'split_manifest.json'):
        shutil.copyfile(output / chosen['directory'] / name, selected / name)
    metadata = json.loads((output / chosen['directory'] / 'metadata.json').read_text())
    metadata.update({'review_threshold': chosen['threshold'],
                     'display_name': 'AksyRes Multi-incident TF-IDF (experimental)',
                     'version': metadata['version'] + '-common-v1',
                     'synthetic_training': True, 'human_reviewed': False,
                     'experimental_release_reason': 'User-requested common multilingual vocabulary experiment; not validated for operational decisions.'})
    write_json(selected / 'metadata.json', metadata)
    write_json(selected / 'validation_policy_metrics.json', {**chosen['validation_policy'], 'model_version': metadata['version']})
    write_json(selected / 'selection_receipt.json', {
        'schema_version': 1, 'selected_from': chosen['directory'],
        'regularization_c': chosen['c'], 'review_threshold': chosen['threshold'],
        'selection_partition': 'validation', 'test_used_for_selection': False,
        'artifact_sha256': metadata['artifact_sha256'], 'human_reviewed': False,
        'approved_for_deployment': False,
    })
    return {'selected': chosen['directory'], 'validation_raw_accuracy': chosen['validation']['raw_classifier']['accuracy'],
            'review_threshold': chosen['threshold'],
            'validation_routed_accuracy': chosen['validation_policy']['accuracy'],
            'validation_critical_recall': chosen['validation_policy']['critical_recall'],
            'validation_urgent_lower_assignments': chosen['validation_policy']['high_risk_underclassification_count']}


def finalize(prepared, selected, challenge, output):
    from evaluate_text_cues import evaluate_rows
    from src.ai_service.text_inference import TfidfSeverityPredictor, text_rule_capabilities

    if output.exists():
        raise ValueError('Final diagnostic output must be new; do not overwrite an inspected test')
    plan = json.loads((selected.parent / 'experiment_plan.json').read_text())
    for name, expected in plan['policy_source_sha256'].items():
        if hashlib.sha256((ROOT / name).read_bytes()).hexdigest() != expected:
            raise ValueError('Policy changed after validation selection; freeze a new selection before final scoring')
    predictor = TfidfSeverityPredictor(selected, allow_experimental=True)
    rows = load_dataset(prepared / 'reports.csv')
    if dataset_fingerprint(rows) != predictor.metadata['dataset_fingerprint']:
        raise ValueError('Final source differs from the fitted corpus')
    splits = experiment_splits(rows, argparse.Namespace(split_manifest=selected / 'split_manifest.json'))
    # Freeze model, cutoff and policy BEFORE the challenge is loaded or scored.
    output.mkdir(parents=True)
    write_json(output / 'frozen_context.json', {
        'schema_version': 1, 'model_metadata_sha256': hashlib.sha256((selected / 'metadata.json').read_bytes()).hexdigest(),
        'artifact_sha256': predictor.metadata['artifact_sha256'],
        'policy_source_sha256': plan['policy_source_sha256'],
        'challenge_sha256': hashlib.sha256(challenge.read_bytes()).hexdigest(),
        'review_threshold': predictor.metadata['review_threshold'],
        'model_version': predictor.metadata['version'], 'text_rules': text_rule_capabilities(predictor.metadata, rules_enabled=True),
        'selection_partition': 'validation', 'independent_real_report_validation': False,
        'approved_for_deployment': False,
    })
    external, _, _ = read_scenarios(challenge, allow_label_variants=True)
    fitted_texts = {normalized_text(row['text']) for row in splits['train'] + splits['validation']}
    if any(normalized_text(row['text']) in fitted_texts for row in external):
        raise ValueError('External diagnostic contains exact training/validation text')
    write_csv(output / 'challenge.csv', external)
    evaluated = {}
    for name, subset in (('family_test', splits['test']), ('separate_challenge', external)):
        metrics, predictions = evaluate_rows(subset, predictor, external=True)
        metrics.update({'evaluation_kind': name + '_synthetic_diagnostic',
                        'provenance': 'AI-authored fictional descriptions with provisional labels; no independent human review.',
                        'semantic_overlap_note': 'Family test partitions are disjoint. Separately authored challenge can share clinical concepts; it is not external real-report evidence.'})
        write_json(output / (name + '_metrics.json'), metrics)
        write_json(output / (name + '_predictions.json'), predictions)
        evaluated[name] = metrics
    failures = []
    for name, metrics in evaluated.items():
        checks = [('overall', metrics)] + list(metrics['per_incident_type'].items()) + list(metrics['per_language'].items())
        for scope, value in checks:
            if value['accuracy'] is None or value['accuracy'] < .8:
                failures.append(name + ':' + scope + ':accuracy_below_80')
            if value['macro_f1'] is None or value['macro_f1'] < .8:
                failures.append(name + ':' + scope + ':macro_f1_below_80')
            if value['critical_recall'] is None or value['critical_recall'] < .95:
                failures.append(name + ':' + scope + ':critical_recall_below_95')
            if value['high_risk_underclassification_count']:
                failures.append(name + ':' + scope + ':urgent_lower_assignment')
    gate = {'schema_version': 1, 'engineering_target_met': not failures, 'failures': failures,
            'independent_validation': False, 'all_data_reviewed': False,
            'approved_for_deployment': False, 'activated': False,
            'interpretation': 'Synthetic engineering results only; passing does not grant a validated operational release.'}
    write_json(output / 'engineering_gate.json', gate)
    return {'results': {name: {'count': value['count'], 'raw_accuracy': value['raw_classifier']['accuracy'],
                             'routed_accuracy': value['accuracy'], 'critical_recall': value['critical_recall'],
                             'urgent_lower_assignments': value['high_risk_underclassification_count']}
                        for name, value in evaluated.items()}, 'engineering_target_met': not failures,
            'approved_for_deployment': False}


def combine(base, additional, output):
    if output.exists():
        raise ValueError('Combined data version must be new')
    def partitions(directory):
        return experiment_splits(load_dataset(directory / 'reports.csv'),
                                 argparse.Namespace(split_manifest=directory / 'split_manifest.json'))
    original, extra = partitions(base), partitions(additional)
    merged = {
        'train': original['train'] + extra['train'],
        'validation': original['validation'] + extra['validation'],
        # The earlier 208-case diagnostic has already influenced development.
        # Keep it out of this fit/version and use only new-source test families.
        'test': extra['test'],
    }
    for name in merged:
        merged[name] = sorted(merged[name], key=lambda row: row['case_id'])
    output.mkdir(parents=True)
    write_csv(output / 'reports.csv', [row for subset in merged.values() for row in subset])
    write_json(output / 'split_manifest.json', _manifest(merged))
    partitions(output)  # Reject duplicate IDs, conflicting groups, exact leakage.
    write_json(output / 'provenance.json', {
        'schema_version': 1, 'synthetic': True, 'human_reviewed': False,
        'approved_for_deployment': False,
        'base_source_sha256': hashlib.sha256((base / 'reports.csv').read_bytes()).hexdigest(),
        'additional_source_sha256': hashlib.sha256((additional / 'reports.csv').read_bytes()).hexdigest(),
        'omitted_previously_exposed_base_test_count': len(original['test']),
        'omitted_base_test_fitted': False,
        'split_counts': {name: len(rows) for name, rows in merged.items()},
        'limitations': ['Related clinical concepts can overlap across authored corpora despite distinct scenario IDs.',
                        'Synthetic translations and context variants are correlated; counts are not independent patient observations.'],
    })
    return {name: len(rows) for name, rows in merged.items()}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest='command', required=True)
    sub = commands.add_parser('prepare')
    sub.add_argument('--source', type=Path, required=True)
    sub.add_argument('--output', type=Path, required=True)
    sub = commands.add_parser('combine')
    sub.add_argument('--base', type=Path, required=True)
    sub.add_argument('--additional', type=Path, required=True)
    sub.add_argument('--output', type=Path, required=True)
    sub = commands.add_parser('fit')
    sub.add_argument('--prepared', type=Path, required=True)
    sub.add_argument('--output', type=Path, required=True)
    sub.add_argument('--expanded-profile', action='store_true', help='Explicit synthetic multi-incident cue experiment; never approval')
    sub.add_argument('--reuse-fits', type=Path, help='Re-evaluate unchanged fitted C-grid weights after a validation-only policy fix')
    sub = commands.add_parser('finalize')
    sub.add_argument('--prepared', type=Path, required=True)
    sub.add_argument('--selected', type=Path, required=True)
    sub.add_argument('--challenge', type=Path, required=True)
    sub.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    if args.command == 'prepare':
        result = prepare(args.source, args.output)
    elif args.command == 'fit':
        result = fit(args.prepared, args.output, expanded_profile=args.expanded_profile, reuse_fits=args.reuse_fits)
    elif args.command == 'combine':
        result = combine(args.base, args.additional, args.output)
    else:
        result = finalize(args.prepared, args.selected, args.challenge, args.output)
    print(json.dumps(result))


if __name__ == '__main__':
    main()
