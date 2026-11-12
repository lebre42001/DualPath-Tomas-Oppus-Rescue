"""One fixed CPU fit of train-only vocabulary drafts; never activates a model.

Preserves the 2026-11-09 validation/test partitions. Existing diagnostics are
exposed regression evidence, never fresh or independently reviewed holdouts.
No hyperparameter/threshold search, severity-policy changes, or approval.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from augment_multiincident_contexts import augment, vocabulary
from evaluate_text_cues import evaluate_rows
from src.ai_service.text_inference import TfidfSeverityPredictor
from src.ai_service.text_training import build_parser, dataset_fingerprint, experiment_splits, load_dataset, run_baseline

SOURCE = ROOT / 'datasets/emergency_text/experiments/multiincident_common_20261109/combined_plain'
EXPERIMENT = ROOT / 'datasets/emergency_text/experiments/local_vocabulary_20261112'
BASELINE = ROOT / 'releases/triage-multiincident-tfidf-20261109-v1'


def write_json(path: Path, value: dict) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2, allow_nan=False) + '\n', encoding='utf-8')


def refresh(source: Path, contexts: Path, prepared: Path, output: Path, baseline: Path) -> dict:
    source, contexts, prepared, output, baseline = (path.resolve() for path in (source, contexts, prepared, output, baseline))
    output.relative_to(ROOT)  # Reject an out-of-checkout destination before any work.
    if prepared.exists() or output.exists():
        raise ValueError('Use new prepared/output directories; preserve prior experiments')
    old = json.loads((baseline / 'metadata.json').read_text(encoding='utf-8'))
    if old['regularization_c'] != 16 or old['review_threshold'] != .5:
        raise ValueError('Expected the frozen C16 / 0.50 baseline; do not silently change the recipe')
    if (dataset_fingerprint(load_dataset(source / 'reports.csv')) != old['dataset_fingerprint']
            or json.loads((source / 'split_manifest.json').read_text(encoding='utf-8'))
            != json.loads((baseline / 'split_manifest.json').read_text(encoding='utf-8'))):
        raise ValueError('Baseline and frozen source corpus/partitions must match')
    counts = augment(source, prepared, contexts, prepend_context=True)
    args = build_parser().parse_args([
        'baseline', '--data', str(prepared / 'reports.csv'), '--output', str(output),
        '--split-manifest', str(prepared / 'split_manifest.json'), '--allow-draft',
        '--seed', '20261112', '--regularization-c', '16', '--review-threshold', '.5',
    ])
    run_baseline(args)
    metadata_path = output / 'metadata.json'
    metadata = json.loads(metadata_path.read_text(encoding='utf-8'))
    metadata.update({
        'training_run_version': metadata['version'],
        'version': 'multiincident-tfidf-20261112-vocabulary-v1',
        'display_name': 'AksyRes Multi-incident Local Vocabulary (Experimental)',
        'text_rule_profile': old['text_rule_profile'],
        'text_rule_incident_types': old['text_rule_incident_types'],
        'synthetic_training': True, 'human_reviewed': False,
        'approved_for_deployment': False, 'independent_validation': False,
        'deployment_status': 'experimental', 'pilot_only': True,
        'pilot_policy': 'Local advisory preview only. Every operational severity needs admin assessment.',
        'expansion_recipe': 'tools/refresh_local_text_vocabulary.py',
        'experimental_release_reason': 'User-requested train-only local vocabulary expansion; no independent incidents or approval.',
    })
    write_json(metadata_path, metadata)
    for name in ('validation_metrics.json', 'validation_predictions.json'):
        path = output / name
        report = json.loads(path.read_text(encoding='utf-8'))
        report['model_version'] = metadata['version']
        write_json(path, report)
    rows = load_dataset(prepared / 'reports.csv')
    splits = experiment_splits(rows, args)
    original = experiment_splits(load_dataset(source / 'reports.csv'),
                                 argparse.Namespace(split_manifest=str(source / 'split_manifest.json')))
    for name in ('validation', 'test'):
        if splits[name] != original[name]:
            raise AssertionError('Frozen held-out rows changed')
    candidate = TfidfSeverityPredictor(output, allow_experimental=True)
    prior = TfidfSeverityPredictor(baseline, allow_experimental=True)
    policy_sources = ['src/ai_service/text_inference.py', 'src/ai_service/multiincident_cues.py',
                      'src/ai_service/text_cues.py', 'evaluate_text_cues.py']
    evidence = {'counts': counts, 'independent_validation': False, 'human_reviewed': False,
                'approved_for_deployment': False, 'new_independent_scenarios': 0,
                'regularization_c': 16, 'review_threshold': .5,
                'evaluation_use': 'Exposed frozen synthetic regression evidence; no model tuning on test.',
                'model_directory': output.relative_to(ROOT).as_posix(),
                'policy_source_sha256': {p: hashlib.sha256((ROOT / p).read_bytes()).hexdigest() for p in policy_sources},
                'policy_source_lf_sha256': {p: hashlib.sha256((ROOT / p).read_bytes().replace(b'\r\n', b'\n')).hexdigest()
                                            for p in policy_sources}}
    # Validation comparison is recorded before diagnostic test evaluation.
    for split in ('validation', 'test'):
        for name, predictor in (('baseline', prior), ('candidate', candidate)):
            metrics, predictions = evaluate_rows(splits[split], predictor)
            write_json(output / f'{name}_{split}_policy_metrics.json', metrics)
            write_json(output / f'{name}_{split}_policy_predictions.json', {'rows': predictions})
            evidence[f'{name}_{split}'] = {key: metrics[key] for key in (
                'count', 'accuracy', 'macro_f1', 'critical_recall', 'critical_lower_assignment_count',
                'high_risk_underclassification_count', 'coverage')}
        if split == 'validation':
            before, after = evidence['baseline_validation'], evidence['candidate_validation']
            evidence['validation_non_regression'] = (
                after['accuracy'] >= before['accuracy'] and after['macro_f1'] >= before['macro_f1']
                and after['critical_recall'] >= before['critical_recall']
                and after['high_risk_underclassification_count'] <= before['high_risk_underclassification_count'])
            evidence['lab_selection_partition'] = 'validation'
            write_json(output / 'validation_selection.json', evidence)
    evidence['baseline_training_vocabulary'] = vocabulary(original['train'])
    evidence['candidate_training_vocabulary'] = vocabulary(splits['train'])
    write_json(output / 'expansion_report.json', evidence)
    write_json(output / 'tested_policy_context.json', {
        'metadata_sha256': hashlib.sha256(metadata_path.read_bytes()).hexdigest(),
        'artifact_sha256': metadata['artifact_sha256'],
        'policy_source_sha256': evidence['policy_source_sha256'],
        'policy_source_lf_sha256': evidence['policy_source_lf_sha256'],
        'independent_validation': False, 'approved_for_deployment': False,
    })
    return {key: value for key, value in evidence.items() if 'vocabulary' not in key}


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source', type=Path, default=SOURCE)
    parser.add_argument('--contexts', type=Path, default=EXPERIMENT / 'context_vocabulary.json')
    parser.add_argument('--prepared', type=Path, default=EXPERIMENT / 'prepared')
    parser.add_argument('--output', type=Path, default=ROOT / 'releases/triage-multiincident-tfidf-20261112-vocabulary-v1')
    parser.add_argument('--baseline', type=Path, default=BASELINE)
    args = parser.parse_args()
    print(json.dumps(refresh(args.source, args.contexts, args.prepared, args.output, args.baseline), allow_nan=False))


if __name__ == '__main__':
    main()
