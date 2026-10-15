"""Leakage, approval gates and safety metrics; no model downloads required."""
from __future__ import annotations

import csv
import json
from pathlib import Path
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from src.ai_service.text_training import (COLUMNS, LABELS, _manifest, dataset_fingerprint,
    evaluation_rows, load_dataset, prediction_metrics, select_rows, split_groups)


def sample_rows(status="approved"):
    rows = []
    for label in LABELS:
        for group in range(5):
            for language in ("en", "tl", "ceb", "mixed"):
                rows.append(dict(zip(COLUMNS, (f"{label}-{group}-{language}", f"{label}-{group}", language,
                    f"Independent scenario {label} {group} {language}", label, status,
                    "reviewer-01" if status == "approved" else "", "unit test"))))
    return rows


class DatasetTests(unittest.TestCase):
    def write_csv(self, rows, directory):
        path = Path(directory) / "examples.csv"
        with path.open("w", encoding="utf-8", newline="") as handle:
            writer = csv.DictWriter(handle, fieldnames=COLUMNS)
            writer.writeheader()
            writer.writerows(rows)
        return path

    def test_drafts_never_train_by_default_and_rejections_are_excluded(self):
        rows = sample_rows("draft")
        with self.assertRaisesRegex(ValueError, "No approved"):
            select_rows(rows)
        rows[0]["review_status"] = "rejected"
        self.assertEqual(len(select_rows(rows, allow_draft=True)), len(rows) - 1)

    def test_approved_examples_require_reviewer(self):
        rows = sample_rows()
        rows[0]["reviewer"] = ""
        with tempfile.TemporaryDirectory() as directory:
            with self.assertRaisesRegex(ValueError, "accountable reviewer"):
                load_dataset(self.write_csv(rows, directory))

    def test_duplicate_text_cannot_cross_groups(self):
        rows = sample_rows()
        rows[4]["text"] = "  " + rows[0]["text"].upper() + "  "
        with tempfile.TemporaryDirectory() as directory:
            with self.assertRaisesRegex(ValueError, "different groups"):
                load_dataset(self.write_csv(rows, directory))

    def test_translations_cannot_have_conflicting_labels(self):
        rows = sample_rows()
        rows[1]["label"] = "critical"
        with tempfile.TemporaryDirectory() as directory:
            with self.assertRaisesRegex(ValueError, "share the reviewed label"):
                load_dataset(self.write_csv(rows, directory))

    def test_split_is_reproducible_and_keeps_all_translations_together(self):
        rows = sample_rows()
        splits = split_groups(rows)
        self.assertEqual(splits, split_groups(list(reversed(rows))))
        seen = set()
        for items in splits.values():
            groups = {row["group_id"] for row in items}
            self.assertFalse(seen & groups)
            seen |= groups
            self.assertEqual({row["label"] for row in items}, set(LABELS))
            self.assertEqual(len(items), len(groups) * 4)
        self.assertEqual(sum(map(len, splits.values())), len(rows))

    def test_requires_real_held_out_groups_for_every_class(self):
        rows = [row for row in sample_rows() if row["group_id"].endswith(("-0", "-1"))]
        with self.assertRaisesRegex(ValueError, "at least 3"):
            split_groups(rows)

    def test_fingerprint_ignores_order_but_tracks_text_and_review_changes(self):
        rows = sample_rows()
        fingerprint = dataset_fingerprint(rows)
        self.assertEqual(fingerprint, dataset_fingerprint(list(reversed(rows))))
        rows[0]["reviewer"] = "reviewer-02"
        self.assertNotEqual(fingerprint, dataset_fingerprint(rows))

    def test_external_evaluation_rejects_group_or_normalized_text_leakage(self):
        rows = sample_rows()
        splits = split_groups(rows)
        metadata = {"dataset_fingerprint": dataset_fingerprint(rows)}
        manifest = _manifest(splits)
        self.assertEqual(evaluation_rows(rows, metadata, manifest, "test"), splits["test"])
        external = dict(splits["train"][0], case_id="new-id", group_id="new-group")
        with self.assertRaisesRegex(ValueError, "overlaps"):
            evaluation_rows([external], metadata, manifest, "external")
        external["text"] = "A completely independent held out description."
        self.assertEqual(evaluation_rows([external], metadata, manifest, "external"), [external])
        external["group_id"] = splits["validation"][0]["group_id"]
        with self.assertRaisesRegex(ValueError, "overlaps"):
            evaluation_rows([external], metadata, manifest, "external")

    def test_saved_test_requires_matching_source_and_review_state(self):
        rows = sample_rows()
        metadata = {"dataset_fingerprint": "changed"}
        with self.assertRaisesRegex(ValueError, "unchanged source"):
            evaluation_rows(rows, metadata, _manifest(split_groups(rows)), "test")

    def test_metrics_count_urgent_undertriage_separately_from_abstention(self):
        rows = sample_rows()[:4]
        for row, label in zip(rows, ("critical", "serious", "critical", "minor")):
            row["label"] = label
        metrics = prediction_metrics(rows, [[0.9, 0.05, 0.03, 0.02], [0.4, 0.3, 0.2, 0.1], [0.02, 0.03, 0.9, 0.05], [0.02, 0.03, 0.05, 0.9]])
        self.assertEqual(metrics["high_risk_undertriage_count"], 1)
        self.assertEqual(metrics["raw_high_risk_undertriage_count"], 2)
        self.assertEqual(metrics["high_risk_review_rate"], 1 / 3)
        self.assertEqual(metrics["coverage"], 0.5)
        self.assertEqual(metrics["per_language"]["en"]["high_risk_undertriage_count"], 1)
        self.assertEqual(metrics["per_language"]["tl"]["high_risk_review_rate"], 1)
        self.assertEqual(metrics["per_language"]["en"]["raw_classifier"]["count"], 1)
        self.assertEqual(metrics["per_language"]["en"]["raw_classifier"]["macro_f1"], 0.0)
        self.assertFalse(metrics["approved_for_deployment"])
        self.assertFalse(metrics["confidence_calibrated"])
        serialized = json.dumps(metrics)
        self.assertNotIn(rows[0]["text"], serialized)
        self.assertNotIn(rows[0]["reviewer"], serialized)

    def test_unscored_long_input_requires_review_without_fake_confidence(self):
        rows = sample_rows()[:2]
        rows[0]["label"] = "critical"
        metrics = prediction_metrics(rows, [None, [0.9, 0.05, 0.03, 0.02]])
        self.assertEqual(metrics["unscored_review_count"], 1)
        self.assertEqual(metrics["raw_classifier"]["count"], 1)
        self.assertEqual(metrics["review_routed"]["count"], 2)
        self.assertEqual(metrics["high_risk_review_rate"], 1)
        self.assertEqual(metrics["raw_high_risk_support"], 0)
        self.assertIsNone(metrics["raw_high_risk_undertriage_rate"])
        self.assertEqual(metrics["per_language"]["en"]["unscored_review_count"], 1)
        self.assertIsNone(metrics["per_language"]["ceb"]["macro_f1"])

    def test_metrics_reject_nonfinite_and_incomplete_predictions(self):
        rows = sample_rows()[:1]
        for probabilities in ([], [[0.5, 0.5]], [[float("nan"), 0, 0, 0]], [[1, 1, 0, 0]]):
            with self.assertRaises(ValueError):
                prediction_metrics(rows, probabilities)


if __name__ == "__main__":
    unittest.main()
