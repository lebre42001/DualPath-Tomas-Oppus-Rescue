"""Offline, local-only comparison with independently labeled descriptions.

Never reads app reports, posts notifications or activates a candidate. External
evaluation excludes all source splits of the candidate, including its test set.
"""
import argparse
import json
from pathlib import Path
import time

from src.ai_service.text_training import (LABELS, _prepare_output, _write_json,
    evaluation_rows, load_dataset, prediction_metrics, select_rows)
from src.ai_service.text_review import check_fresh_holdout


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--model', type=Path, required=True)
    parser.add_argument('--data', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--allow-experimental', action='store_true')
    parser.add_argument('--historical-test', action='store_true', help='Explicit diagnostic on the known saved test set; never a fresh release evaluation')
    parser.add_argument('--allow-draft', action='store_true')
    args = parser.parse_args(argv)
    try:
        metadata = json.loads((args.model / 'metadata.json').read_text(encoding='utf-8'))
        manifest = json.loads((args.model / 'split_manifest.json').read_text(encoding='utf-8'))
        rows = select_rows(load_dataset(args.data), args.allow_draft)
        if args.historical_test:
            rows = evaluation_rows(rows, metadata, manifest, 'test')
        else:
            check_fresh_holdout(rows, [args.model])
        # Validate paths/data before loading one model. Reject occupied outputs early.
        if args.output.exists() and any(args.output.iterdir()):
            raise ValueError('Use a new output directory; prior comparisons are preserved')
        import torch
        from src.ai_service.text_inference import TextSeverityPredictor
        torch.set_num_threads(2)
        predictor = TextSeverityPredictor(args.model, allow_experimental=args.allow_experimental)
        vectors, predictions = [], []
        for row in rows:
            started = time.perf_counter()
            result = predictor.predict(row['text'])
            vectors.append([result['probabilities'][label] for label in LABELS] if result['probabilities'] else None)
            predictions.append({'case_id':row['case_id'], 'language':row['language'], 'reference_label':row['label'],
                                'review_status':row['review_status'], 'prediction':result, 'seconds':time.perf_counter() - started})
        metrics = prediction_metrics(rows, vectors, metadata['review_threshold'])
        for language in metrics['per_language']:
            indices = [index for index, row in enumerate(rows) if row['language'] == language]
            if indices:
                raw = prediction_metrics([rows[index] for index in indices], [vectors[index] for index in indices], metadata['review_threshold'])
                metrics['per_language'][language]['raw_classifier'] = raw['raw_classifier']
        output = _prepare_output(args.output)
        _write_json(output / 'shadow_comparison.json', {
            'model_version':metadata['version'], 'historical_test':args.historical_test,
            'all_reference_labels_reviewed':all(row['review_status'] == 'approved' for row in rows),
            'metrics':metrics, 'predictions':predictions, 'approved_for_deployment':False,
            'note':'Offline comparison only. Human operational acceptance, calibration and release are separate.'})
        print(json.dumps({'output':str(output), 'count':len(rows), 'raw_accuracy':metrics['raw_classifier']['accuracy'],
                          'critical_recall':metrics['raw_classifier']['per_class']['critical']['recall'],
                          'coverage':metrics['coverage'], 'approved_for_deployment':False}, indent=2))
        return 0
    except (ValueError, OSError, ImportError, KeyError, RuntimeError) as error:
        parser.exit(2, f'error: {error}\n')


if __name__ == '__main__':
    raise SystemExit(main())
