"""Diagnostic evaluation of the actual model + rules policy; never approves a release."""
import argparse
import json
from collections import Counter
from pathlib import Path

from src.ai_service.text_cues import RULE_VERSION, analyze_cues
from src.ai_service.text_inference import TfidfSeverityPredictor, combine_assessments
from src.ai_service.text_training import _classification_metrics, dataset_fingerprint, load_dataset, select_rows


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--data', required=True)
    parser.add_argument('--manifest', required=True)
    parser.add_argument('--model', required=True)
    parser.add_argument('--output', required=True)
    parser.add_argument('--allow-draft', action='store_true')
    parser.add_argument('--allow-experimental', action='store_true')
    args = parser.parse_args()
    predictor = TfidfSeverityPredictor(Path(args.model), allow_experimental=args.allow_experimental)
    all_rows = select_rows(load_dataset(args.data), args.allow_draft)
    if dataset_fingerprint(all_rows) != predictor.metadata['dataset_fingerprint']:
        raise ValueError('Diagnostic test requires the unchanged source dataset and labels.')
    ids = set(json.loads(Path(args.manifest).read_text())['test']['case_ids'])
    rows = [row for row in all_rows if row['case_id'] in ids]
    if len(rows) != len(ids) or not rows:
        raise ValueError('Test IDs are missing or empty.')
    output = Path(args.output)
    output.mkdir(parents=True, exist_ok=False)
    results = []
    for row in rows:
        model = predictor.predict(row['text'])
        decision = combine_assessments(model, analyze_cues(row['text']))
        results.append({'case_id': row['case_id'], 'language': row['language'], 'label': row['label'],
                        'suggestion': decision['severity'], 'routed_label': decision['severity'] or 'needs_review',
                        'method': decision.get('assessment_method', 'trained_model')})
    metrics = _classification_metrics([r['label'] for r in results], [r['routed_label'] for r in results])
    metrics['per_language'] = {language: _classification_metrics(
        [r['label'] for r in results if r['language'] == language],
        [r['routed_label'] for r in results if r['language'] == language])
        for language in ('en', 'tl', 'ceb', 'mixed')}
    accepted = [r for r in results if r['suggestion'] is not None]
    rank = {'minor': 0, 'serious': 1, 'critical': 2}
    urgent = [r for r in results if r['label'] in ('serious', 'critical')]
    metrics.update({'rule_version': RULE_VERSION, 'model_version': predictor.metadata['version'],
        'methods': dict(Counter(r['method'] for r in results)), 'coverage': len(accepted) / len(results),
        'accepted_accuracy': sum(r['suggestion'] == r['label'] for r in accepted) / len(accepted) if accepted else None,
        'high_risk_support': len(urgent),
        'high_risk_underclassification_count': sum(r['suggestion'] in rank and rank[r['suggestion']] < rank[r['label']] for r in urgent),
        'provenance': 'Historical synthetic test already exposed during development; diagnostic only, not independent validation.',
        'approved_for_deployment': False})
    for filename, payload in [('metrics.json', metrics), ('predictions.json', results)]:
        (output / filename).write_bytes((json.dumps(payload, indent=2) + '\n').encode('utf-8'))
    print(json.dumps({'count': metrics['count'], 'routed_accuracy': metrics['accuracy'],
        'per_language': {l: m['accuracy'] for l, m in metrics['per_language'].items()},
        'coverage': metrics['coverage'], 'underclassification': metrics['high_risk_underclassification_count']}))


if __name__ == '__main__':
    main()
