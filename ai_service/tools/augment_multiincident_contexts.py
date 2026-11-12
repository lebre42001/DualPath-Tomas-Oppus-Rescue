"""Append correlated context variants to TRAIN ONLY; preserve frozen holdouts.

By default only the formal first clause is replaced. --prepend-context instead
keeps the complete description, including history and negation. Labels, group
IDs, category and language remain unchanged. This is synthetic draft
augmentation, never human approval or a new independent observation.
"""
from __future__ import annotations

import argparse
from collections import Counter
import csv
import hashlib
import io
import json
from pathlib import Path
import re
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from src.ai_service.incident_types import INCIDENT_TYPES
from src.ai_service.text_training import (
    CATEGORY_COLUMNS, LANGUAGES, _manifest, experiment_splits, load_dataset,
)

EXPERIMENT = ROOT / 'datasets/emergency_text/experiments/multiincident_common_20261109'


def write_json(path: Path, payload: dict) -> None:
    path.write_bytes((json.dumps(payload, ensure_ascii=False, indent=2, allow_nan=False) + '\n').encode('utf-8'))


def sha256(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


def load_contexts(path: Path) -> dict:
    payload = json.loads(path.read_text(encoding='utf-8-sig'))
    if payload.get('schema_version') != 1 or payload.get('variants_per_training_row') != 4:
        raise ValueError('Expected schema version 1 and exactly four context variants')
    contexts = payload.get('contexts')
    if not isinstance(contexts, dict) or set(contexts) != set(INCIDENT_TYPES):
        raise ValueError('Contexts must contain the six canonical incident categories')
    for category in INCIDENT_TYPES:
        if set(contexts[category]) != set(LANGUAGES):
            raise ValueError('Every category needs all four language context lists')
        for language in LANGUAGES:
            items = contexts[category][language]
            if (not isinstance(items, list) or len(items) != 4 or len(set(items)) != 4
                    or any(not isinstance(text, str) or text != text.strip() or not text
                           or ',' in text or '\n' in text or '\r' in text
                           or re.search(r'\b(?:minor|serious|critical|needs_review)\b', text, re.I)
                           for text in items)):
                raise ValueError('Every context list needs four distinct neutral clauses without labels or commas')
    return contexts


def vocabulary(rows: list[dict]) -> dict:
    result = {}
    for language in LANGUAGES:
        counts = Counter(word.casefold() for row in rows if row['language'] == language
                         for word in re.findall(r"[^\W\d_]+(?:[-'][^\W\d_]+)*", row['text']))
        result[language] = {'unique_words': len(counts), 'common_words': counts.most_common(100)}
    return {'scope': 'augmented training partition only; not a medical dictionary', 'languages': result}


def augment(prepared: Path, output: Path, vocabulary_path: Path, *, prepend_context: bool = False) -> dict:
    if output.exists():
        raise ValueError('Augmented output must be a new directory; frozen data is never overwritten')
    if output.resolve() == prepared.resolve():
        raise ValueError('Source and augmented directories must differ')
    contexts = load_contexts(vocabulary_path)
    source_csv = prepared / 'reports.csv'
    source_manifest = prepared / 'split_manifest.json'
    source_bytes = source_csv.read_bytes()
    if not source_bytes.endswith(b'\n'):
        raise ValueError('Source CSV needs its existing final newline to append without changing source bytes')
    manifest = json.loads(source_manifest.read_text(encoding='utf-8-sig'))
    rows = load_dataset(source_csv)
    splits = experiment_splits(rows, argparse.Namespace(split_manifest=str(source_manifest)))
    if any(row['review_status'] != 'draft' or row['reviewer'] for row in rows):
        raise ValueError('This synthetic experiment accepts only unreviewed draft rows with blank reviewers')
    added, source_for_variant = [], {}
    for original in splits['train']:
        if prepend_context:
            # Supplementary descriptions have no replaceable formal clause.
            # Keep their complete original meaning, including negation/history.
            condition_body = original['text']
        else:
            _formal_context, separator, condition_body = original['text'].partition(', ')
            if not separator or not condition_body:
                raise ValueError('Training row is missing its formal first clause and intact condition body')
        for index, context in enumerate(contexts[original['incident_type']][original['language']], 1):
            candidate = dict(original)
            candidate['case_id'] = original['case_id'] + f'_CTX{index:02d}'
            candidate['text'] = context + ', ' + condition_body
            candidate['review_status'], candidate['reviewer'] = 'draft', ''
            candidate['notes'] = original['notes'] + ' Correlated train-only context variant; condition body unchanged; no human approval.'
            if candidate['text'].partition(', ')[2] != condition_body:
                raise AssertionError('Condition body changed')
            if any(candidate[key] != original[key] for key in ('group_id', 'incident_type', 'language', 'label')):
                raise AssertionError('Context augmentation changed identity, language, category, or label')
            added.append(candidate)
            source_for_variant[candidate['case_id']] = original['case_id']
    existing_ids = {row['case_id'] for row in rows}
    if existing_ids.intersection(source_for_variant) or len(source_for_variant) != len(added):
        raise ValueError('Augmentation IDs overlap source IDs or one another')
    augmented_train = sorted(splits['train'] + added, key=lambda row: row['case_id'])
    augmented_manifest = dict(manifest)
    augmented_manifest['train'] = _manifest({'train': augmented_train})['train']
    if any(augmented_manifest[name] != manifest[name] for name in ('validation', 'test')):
        raise AssertionError('Frozen evaluation manifest changed')

    # Original CSV bytes remain an exact prefix, including all held-out records.
    # Only newly authored training records are serialized and appended.
    appended = io.StringIO(newline='')
    writer = csv.DictWriter(appended, fieldnames=CATEGORY_COLUMNS)
    writer.writerows(added)
    combined_csv = source_bytes + appended.getvalue().encode('utf-8')
    result = {
        'source_rows': len(rows), 'source_train_rows': len(splits['train']),
        'added_correlated_train_rows': len(added), 'augmented_train_rows': len(augmented_train),
        'validation_rows_unchanged': len(splits['validation']), 'test_rows_unchanged': len(splits['test']),
        'total_rows': len(rows) + len(added),
    }
    prior_provenance_path = prepared / 'provenance.json'
    prior_provenance = json.loads(prior_provenance_path.read_text(encoding='utf-8-sig')) if prior_provenance_path.exists() else {}
    provenance = {
        'schema_version': 1, 'synthetic': True, 'human_reviewed': False,
        'approved_for_deployment': False, 'independent_real_world_evaluation': False,
        'source_csv_sha256': sha256(source_bytes),
        'source_manifest_sha256': sha256(source_manifest.read_bytes()),
        'context_vocabulary_sha256': sha256(vocabulary_path.read_bytes()),
        'augmented_csv_sha256': sha256(combined_csv), 'counts': result,
        'augmentation_policy': 'Append four context variants to each train row only; unchanged condition body, label, language, category and group. Preserve every source CSV byte as prefix and retain frozen validation/test manifest entries.',
        'context_mode': 'prepend_to_complete_description' if prepend_context else 'replace_formal_clause',
        'new_independent_scenarios': 0, 'new_independent_semantic_families': 0,
        'source_case_for_variant': source_for_variant,
        'source_provenance': prior_provenance,
    }
    output.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='context_augmentation_', dir=output.parent) as temporary:
        candidate_directory = Path(temporary) / 'prepared_augmented'
        candidate_directory.mkdir()
        candidate_csv = candidate_directory / 'reports.csv'
        candidate_csv.write_bytes(combined_csv)
        candidate_manifest = candidate_directory / 'split_manifest.json'
        write_json(candidate_manifest, augmented_manifest)
        write_json(candidate_directory / 'provenance.json', provenance)
        write_json(candidate_directory / 'training_vocabulary.json', vocabulary(augmented_train))
        verified_rows = load_dataset(candidate_csv)
        verified = experiment_splits(verified_rows, argparse.Namespace(split_manifest=str(candidate_manifest)))
        if not candidate_csv.read_bytes().startswith(source_bytes):
            raise AssertionError('Source byte preservation failed')
        for name in ('validation', 'test'):
            if verified[name] != splits[name]:
                raise AssertionError('Held-out record values changed')
        if len(verified['train']) != len(augmented_train):
            raise AssertionError('Unexpected augmented training count')
        candidate_directory.rename(output)
    return result


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--prepared', type=Path, default=EXPERIMENT / 'prepared')
    parser.add_argument('--output', type=Path, default=EXPERIMENT / 'prepared_augmented')
    parser.add_argument('--vocabulary', type=Path, default=EXPERIMENT / 'context_vocabulary.json')
    parser.add_argument('--prepend-context', action='store_true',
                        help='Preserve the complete source description instead of replacing a formal first clause')
    args = parser.parse_args()
    print(json.dumps(augment(args.prepared, args.output, args.vocabulary, prepend_context=args.prepend_context), allow_nan=False))


if __name__ == '__main__':
    main()
