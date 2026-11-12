"""Serving-policy parity and honest diagnostic metrics; no fitting or downloads."""
import csv
import io
import json
from pathlib import Path
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch

from evaluate_text_cues import evaluate_rows, main, policy_prediction
from src.ai_service.incident_types import CATEGORY_INPUT_FORMAT, INCIDENT_TYPES
from src.ai_service.text_inference import prediction_payload
from src.ai_service.text_training import (CATEGORY_COLUMNS, dataset_fingerprint,
                                         digest, normalized_text)


def metadata(supported=None):
    return {"input_format": CATEGORY_INPUT_FORMAT,
            "supported_incident_types": supported or list(INCIDENT_TYPES),
            "version": "fixture-only", "review_threshold": .65,
            "deployment_status": "experimental"}


def payload(label="minor", confidence=.94):
    probabilities = {key: (confidence if key == label else (1 - confidence) / 3)
                     for key in ("minor", "serious", "critical", "needs_review")}
    return prediction_payload(probabilities, metadata())


def example(number, category="road_accident", label="critical", language="en"):
    return {"case_id": f"fixture-{number}", "group_id": f"family-{number}",
            "incident_type": category, "language": language,
            "text": "A patient is unconscious.", "label": label,
            "review_status": "draft", "reviewer": "", "notes": "Synthetic test fixture."}


class PolicyEvaluationTests(unittest.TestCase):
    def test_selected_category_reaches_model_and_nonroad_never_runs_road_rules(self):
        predictor = SimpleNamespace(metadata=metadata(), predict=Mock(return_value=payload()))
        with patch("src.ai_service.text_cues.analyze_cues", side_effect=AssertionError("road rules must not run")):
            raw, decision = policy_prediction("A patient is unconscious.", "medical_emergency", predictor)
        predictor.predict.assert_called_once_with("A patient is unconscious.", "medical_emergency")
        self.assertEqual(decision["severity"], "minor")
        self.assertEqual(raw, decision)

    def test_unsupported_category_abstains_before_model_and_rules(self):
        predictor = SimpleNamespace(metadata=metadata(["road_accident"]), predict=Mock(side_effect=AssertionError("must not infer")))
        with patch("src.ai_service.text_cues.analyze_cues", side_effect=AssertionError("must not run")):
            metrics, rows = evaluate_rows([example(1, "fire_incident")], predictor)
        self.assertEqual(rows[0]["routed_label"], "needs_review")
        self.assertEqual(rows[0]["model_reason"], "unsupported_incident_type")
        self.assertEqual(metrics["accuracy"], 0)
        self.assertEqual(metrics["coverage"], 0)
        self.assertEqual(metrics["raw_classifier"]["count"], 0)
        self.assertEqual(metrics["unscored_review_count"], 1)
        self.assertEqual(metrics["critical_recall"], 0)
        self.assertEqual(metrics["high_risk_underclassification_count"], 0)
        self.assertEqual(metrics["high_risk_review_count"], 1)

    def test_road_override_preserves_actual_model_scores_and_legacy_call_contract(self):
        legacy = metadata(["road_accident"])
        legacy.pop("input_format")
        predictor = SimpleNamespace(metadata=legacy, predict=Mock(return_value=payload()))
        metrics, rows = evaluate_rows([example(1)], predictor)
        predictor.predict.assert_called_once_with("A patient is unconscious.")
        row = rows[0]
        self.assertEqual(row["suggestion"], "critical")
        self.assertIsNone(row["confidence"])
        self.assertIsNone(row["probabilities"])
        self.assertEqual(row["model_confidence"], .94)
        self.assertEqual(row["model_predicted_label"], "minor")
        self.assertEqual(metrics["raw_classifier"]["accuracy"], 0)
        self.assertEqual(metrics["accuracy"], 1)
        self.assertEqual(metrics["raw_high_risk_undertriage_count"], 1)
        self.assertEqual(metrics["high_risk_underclassification_count"], 0)

    def test_routed_metrics_count_abstentions_and_each_category_language_cell(self):
        selected = [example(1, "medical_emergency", "critical", "ceb"),
                    example(2, "medical_emergency", "serious", "tl"),
                    example(3, "other", "critical", "mixed")]
        predictor = SimpleNamespace(metadata=metadata(), predict=Mock(side_effect=[payload("serious"), payload("minor"), payload("minor", .4)]))
        metrics, _ = evaluate_rows(selected, predictor, external=True)
        self.assertEqual(metrics["count"], 3)
        self.assertEqual(metrics["accuracy"], 0)
        self.assertEqual(metrics["coverage"], 2 / 3)
        self.assertEqual(metrics["high_risk_underclassification_count"], 2)
        self.assertEqual(metrics["serious_lower_assignment_count"], 1)
        self.assertEqual(metrics["critical_lower_assignment_count"], 1)
        self.assertEqual(metrics["high_risk_review_count"], 1)
        self.assertEqual(metrics["per_incident_type"]["medical_emergency"]["count"], 2)
        self.assertEqual(metrics["per_incident_type_language"]["medical_emergency"]["ceb"]["critical_lower_assignment_count"], 1)
        self.assertIsNone(metrics["per_incident_type_language"]["fire_incident"]["en"]["accuracy"])
        self.assertEqual(metrics["evaluation_kind"], "external_synthetic_diagnostic")
        self.assertFalse(metrics["independent_validation"])
        self.assertFalse(metrics["approved_for_deployment"])

    def test_rules_disabled_matches_model_only_configuration(self):
        predictor = SimpleNamespace(metadata=metadata(), predict=Mock(return_value=payload()))
        with patch("src.ai_service.text_cues.analyze_cues", side_effect=AssertionError("rules disabled")):
            metrics, rows = evaluate_rows([example(1)], predictor, rules_enabled=False)
        self.assertEqual(rows[0]["suggestion"], "minor")
        self.assertIsNone(metrics["rule_version"])

    def test_external_cli_is_explicit_and_preserves_synthetic_status(self):
        rows = [example(1, "medical_emergency", "minor")]
        predictor = SimpleNamespace(metadata=metadata(), predict=Mock(return_value=payload()))
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / "fixture.csv"
            with source.open("w", encoding="utf-8", newline="") as handle:
                writer = csv.DictWriter(handle, CATEGORY_COLUMNS)
                writer.writeheader()
                writer.writerows(rows)
            output = root / "result"
            with patch("evaluate_text_cues.load_predictor", return_value=predictor), patch("sys.stdout", new_callable=io.StringIO):
                main(["--data", str(source), "--model", str(root / "missing-artifact"), "--output", str(output), "--external", "--allow-draft", "--allow-experimental"])
            metrics = json.loads((output / "metrics.json").read_text(encoding="utf-8"))
            self.assertEqual(metrics["accuracy"], 1)
            self.assertFalse(metrics["all_data_reviewed"])
            self.assertFalse(metrics["independent_validation"])
            self.assertFalse(metrics["exact_train_validation_overlap_checked"])

    def test_historical_cli_still_requires_exact_source_and_saved_test_ids(self):
        rows = [example(1), example(2, "medical_emergency", "minor")]
        rows[1]["text"] = "A small superficial scratch was reported."
        predictor = SimpleNamespace(metadata={**metadata(), "dataset_fingerprint": dataset_fingerprint(rows)}, predict=Mock(return_value=payload()))
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source, manifest, output = root / "fixture.csv", root / "split.json", root / "result"
            with source.open("w", encoding="utf-8", newline="") as handle:
                writer = csv.DictWriter(handle, CATEGORY_COLUMNS)
                writer.writeheader()
                writer.writerows(rows)
            manifest.write_text(json.dumps({"test": {"case_ids": [rows[1]["case_id"]]}}), encoding="utf-8")
            with patch("evaluate_text_cues.load_predictor", return_value=predictor), patch("sys.stdout", new_callable=io.StringIO):
                main(["--data", str(source), "--manifest", str(manifest), "--model", str(root), "--output", str(output), "--allow-draft", "--allow-experimental"])
            self.assertEqual(json.loads((output / "metrics.json").read_text())["count"], 1)
            predictor.metadata["dataset_fingerprint"] = "changed"
            with patch("evaluate_text_cues.load_predictor", return_value=predictor), self.assertRaisesRegex(ValueError, "unchanged source"):
                main(["--data", str(source), "--manifest", str(manifest), "--model", str(root), "--output", str(root / "other"), "--allow-draft"])

    def test_external_cli_rejects_known_training_overlap_when_manifest_available(self):
        rows = [example(1, "medical_emergency", "minor")]
        predictor = SimpleNamespace(metadata=metadata(), predict=Mock(return_value=payload()))
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / "fixture.csv"
            with source.open("w", encoding="utf-8", newline="") as handle:
                writer = csv.DictWriter(handle, CATEGORY_COLUMNS)
                writer.writeheader()
                writer.writerows(rows)
            (root / "split_manifest.json").write_text(json.dumps({
                "train": {"group_hashes": [digest(rows[0]["group_id"])],
                          "text_hashes": [digest(normalized_text(rows[0]["text"]))]},
                "validation": {"group_hashes": [], "text_hashes": []}}), encoding="utf-8")
            with patch("evaluate_text_cues.load_predictor", return_value=predictor), self.assertRaisesRegex(ValueError, "overlaps training/validation"):
                main(["--data", str(source), "--model", str(root), "--output", str(root / "result"), "--external", "--allow-draft"])
            predictor.predict.assert_not_called()


if __name__ == "__main__":
    unittest.main()
