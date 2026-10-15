import copy
import csv
import json
from pathlib import Path
import tempfile
import unittest
from src.ai_service.text_review import (WORKSHEET_COLUMNS, audit, export_worksheet,
                                       freeze, import_worksheet, threshold_report, check_fresh_holdout)
from src.ai_service.text_training import _manifest, _save_reports, dataset_fingerprint, split_groups
from test_text_training import sample_rows


class ReviewWorkflowTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name)
        self.rows = sample_rows('draft')
        self.worksheet = self.root / 'worksheet.csv'

    def tearDown(self):
        self.temporary.cleanup()

    def sheet(self):
        export_worksheet(self.rows, self.worksheet)
        with self.worksheet.open(encoding='utf-8-sig', newline='') as handle:
            return list(csv.DictReader(handle))

    def save(self, records):
        with self.worksheet.open('w', encoding='utf-8-sig', newline='') as handle:
            writer = csv.DictWriter(handle, fieldnames=WORKSHEET_COLUMNS)
            writer.writeheader()
            writer.writerows(records)

    def completed(self, record):
        record.update(decision='approved', final_label=record['suggested_label'],
                      reviewer='unit-test-reviewer', reviewed_at='2020-01-01', rationale='Unit test only')

    def test_export_does_not_approve_and_cannot_overwrite(self):
        records = self.sheet()
        self.assertEqual(records[0]['suggested_label'], 'critical')
        self.assertTrue(all(not record['decision'] and not record['reviewer'] for record in records))
        self.assertEqual(audit(import_worksheet(self.rows, self.worksheet))['eligible_approved_rows'], 0)
        with self.assertRaises(FileExistsError):
            export_worksheet(self.rows, self.worksheet)

    def test_import_records_real_supplied_decisions_without_changing_original(self):
        records = self.sheet()
        self.completed(records[0])
        self.save(records)
        result = import_worksheet(self.rows, self.worksheet)
        self.assertEqual(audit(result)['eligible_approved_rows'], 1)
        self.assertEqual(audit(self.rows)['eligible_approved_rows'], 0)
        self.assertIn('Human review 2020-01-01', next(row['notes'] for row in result if row['review_status'] == 'approved'))

    def test_import_refuses_stale_text_missing_review_and_conflicting_translations(self):
        original = self.sheet()
        mutations = [dict(source_fingerprint='stale'), dict(text='changed text'),
                     dict(reviewer='incomplete'), dict(decision='approved', final_label='critical'),
                     dict(decision='approved', final_label='minor', reviewer='unit-test', reviewed_at='2020-01-01', rationale='test conflict')]
        for mutation in mutations:
            records = copy.deepcopy(original)
            records[0].update(mutation)
            self.save(records)
            with self.subTest(mutation=mutation), self.assertRaises(ValueError):
                import_worksheet(self.rows, self.worksheet)

    def test_duplicate_ids_and_future_dates_are_refused(self):
        records = self.sheet()
        self.save(records + [records[0]])
        with self.assertRaises(ValueError):
            import_worksheet(self.rows, self.worksheet)
        self.completed(records[0])
        records[0]['reviewed_at'] = '2999-01-01'
        self.save(records)
        with self.assertRaises(ValueError):
            import_worksheet(self.rows, self.worksheet)

    def test_freeze_is_review_gated_and_rejects_duplicate_examples(self):
        with self.assertRaisesRegex(ValueError, 'No approved'):
            freeze(self.rows, self.root / 'blocked', False, 42)
        self.assertFalse((self.root / 'blocked').exists())
        result = freeze(self.rows, self.root / 'diagnostic', True, 42)
        self.assertFalse(result['approved_for_deployment'])
        receipt = json.loads((self.root / 'diagnostic/dataset_receipt.json').read_text())
        self.assertFalse(receipt['all_data_reviewed'])
        with self.assertRaises(ValueError):
            freeze(self.rows, self.root / 'diagnostic', True, 42)
        duplicated = self.rows + [dict(self.rows[0], case_id='new-duplicate-id')]
        with self.assertRaisesRegex(ValueError, 'duplicated'):
            freeze(duplicated, self.root / 'duplicate', True, 42)

    def test_threshold_sweep_only_accepts_matching_validation_predictions(self):
        model = self.root / 'candidate'
        model.mkdir()
        metadata = dict(review_threshold=.65, all_data_reviewed=False, version='unit-test')
        _save_reports(model, split_groups(self.rows), metadata, lambda texts: [[.1, .2, .6, .1] for _ in texts])
        before = {file.name:file.read_bytes() for file in model.iterdir()}
        threshold_report(model, self.root / 'analysis')
        self.assertEqual(before, {file.name:file.read_bytes() for file in model.iterdir()})
        report = json.loads((self.root / 'analysis/threshold_analysis.json').read_text())
        self.assertFalse(report['confidence_calibrated'])
        self.assertFalse(report['approved_for_deployment'])
        self.assertEqual(report['source_split'], 'validation')
        file = model / 'validation_predictions.json'
        payload = json.loads(file.read_text())
        payload['split'] = 'test'
        file.write_text(json.dumps(payload))
        with self.assertRaisesRegex(ValueError, 'validation predictions'):
            threshold_report(model, self.root / 'invalid')

    def test_fresh_holdout_rejects_even_previous_test_cases_and_unreviewed_rows(self):
        rows = sample_rows()
        splits = split_groups(rows)
        model = self.root / 'past-model'
        model.mkdir()
        (model / 'split_manifest.json').write_text(json.dumps(_manifest(splits)))
        with self.assertRaisesRegex(ValueError, 'inspected test'):
            check_fresh_holdout(splits['test'], [model])
        with self.assertRaisesRegex(ValueError, 'approved'):
            check_fresh_holdout(self.rows, [model])
        fresh = [dict(row, case_id='fresh-' + row['case_id'], group_id='fresh-' + row['group_id'], text='Fresh independent text ' + row['text']) for row in rows]
        self.assertEqual(check_fresh_holdout(fresh, [model])['exact_and_declared_group_overlap'], 0)


if __name__ == '__main__':
    unittest.main()
