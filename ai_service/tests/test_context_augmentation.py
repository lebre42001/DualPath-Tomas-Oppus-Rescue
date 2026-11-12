"""Vocabulary expansion must preserve negation/history and frozen evaluation rows."""
import argparse
import csv
import json
from pathlib import Path
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools'))
sys.path.insert(0, str(ROOT))

from augment_multiincident_contexts import augment
from src.ai_service.incident_types import INCIDENT_TYPES
from src.ai_service.text_training import CATEGORY_COLUMNS, LABELS, LANGUAGES, _manifest, experiment_splits, load_dataset


class ContextAugmentationTests(unittest.TestCase):
    def test_prepend_keeps_complete_description_and_frozen_holdouts(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source = root / 'source'
            source.mkdir()
            texts = [
                'Kaniadto ra to nga hitabo, wala pa masusi ang kahimtang karon.',
                'Wala pa mahibaloi unsay nahitabo ug kulang pa ang detalye',
                'Validation example retained unchanged', 'Test example retained unchanged',
            ]
            rows = [dict(zip(CATEGORY_COLUMNS, (
                f'case{i:02}', f'group{i}', 'ceb', texts[i % len(texts)] + f' Fixture {i}.', LABELS[i % 4], 'draft', '',
                'Fictional structural test only', 'other',
            ))) for i in range(16)]
            with (source / 'reports.csv').open('w', encoding='utf-8', newline='') as handle:
                writer = csv.DictWriter(handle, fieldnames=CATEGORY_COLUMNS)
                writer.writeheader()
                writer.writerows(rows)
            splits = {'train': rows[:8], 'validation': rows[8:12], 'test': rows[12:]}
            manifest = _manifest(splits)
            (source / 'split_manifest.json').write_text(json.dumps(manifest), encoding='utf-8')
            contexts = {category: {language: [f'Neutral context {i}' for i in range(4)]
                                   for language in LANGUAGES} for category in INCIDENT_TYPES}
            vocabulary = root / 'contexts.json'
            vocabulary.write_text(json.dumps({'schema_version': 1, 'variants_per_training_row': 4,
                                             'contexts': contexts}), encoding='utf-8')
            output = root / 'expanded'
            counts = augment(source, output, vocabulary, prepend_context=True)
            self.assertEqual(counts['added_correlated_train_rows'], 32)
            self.assertTrue((output / 'reports.csv').read_bytes().startswith((source / 'reports.csv').read_bytes()))
            expanded = experiment_splits(load_dataset(output / 'reports.csv'),
                                        argparse.Namespace(split_manifest=str(output / 'split_manifest.json')))
            for split in ('validation', 'test'):
                self.assertEqual(expanded[split], splits[split])
            for row in expanded['train']:
                if '_CTX' in row['case_id']:
                    original = next(item for item in rows if item['case_id'] == row['case_id'].split('_CTX')[0])
                    self.assertTrue(row['text'].endswith(', ' + original['text']))
                    for key in ('group_id', 'label', 'incident_type', 'language', 'review_status', 'reviewer'):
                        self.assertEqual(row[key], original[key])
            with self.assertRaises(ValueError):
                augment(source, output, vocabulary, prepend_context=True)


if __name__ == '__main__':
    unittest.main()
