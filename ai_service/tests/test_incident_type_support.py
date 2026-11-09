"""Category scope gates and feature parity; no model training or downloads."""
import argparse
import csv
import hashlib
import json
import os
from pathlib import Path
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch

from fastapi import FastAPI
from fastapi.testclient import TestClient

from src.ai_service.incident_types import (CATEGORY_INPUT_FORMAT, INCIDENT_TYPES,
    LEGACY_INPUT_FORMAT, metadata_contract, model_input, normalize_incident_type)
from src.ai_service.text_api import router
from src.ai_service.text_dataset_views import export_views
from src.ai_service.text_inference import (TfidfSeverityPredictor, TextModelUnavailable,
    combine_assessments, predict_text, read_metadata)
from src.ai_service.text_training import (CATEGORY_COLUMNS, COLUMNS, _baseline_predictor,
    _metadata, _scoped_probabilities, dataset_fingerprint, load_dataset,
    prediction_metrics, split_groups)


def examples():
    return [{"case_id": f"{category}-{label}-{group}", "group_id": f"{category}-{label}-{group}",
             "language": "en", "text": f"Independent {category} scenario {label} {group}.",
             "label": label, "review_status": "approved", "reviewer": "test-reviewer", "notes": "fixture",
             "incident_type": category}
            for category in ("road_accident", "fire_incident")
            for label in ("minor", "serious", "critical", "needs_review") for group in range(3)]


class RegistryTests(unittest.TestCase):
    def test_existing_ui_and_firmware_aliases_map_to_exact_categories(self):
        for alias, expected in ((None, "road_accident"), ("Road Accident", "road_accident"),
                                ("ROAD_ACCIDENT", "road_accident"), ("vehicle accident", "road_accident"),
                                ("Fire", "fire_incident"), ("Flood / Landslide", "flood_landslide"),
                                ("FLOOD", "flood_landslide"), ("landslide", "flood_landslide"),
                                ("Medical Emergency", "medical_emergency"), ("SECURITY", "security_incident"),
                                ("Other", "other")):
            self.assertEqual(normalize_incident_type(alias), expected)
        for invalid in ("invented incident", 42, "  "):
            with self.assertRaises(ValueError):
                normalize_incident_type(invalid)

    def test_legacy_artifacts_are_road_only_and_new_features_require_scope(self):
        self.assertEqual(metadata_contract({}), (LEGACY_INPUT_FORMAT, ["road_accident"]))
        for metadata in ({"supported_incident_types": ["fire_incident"]},
                         {"input_format": CATEGORY_INPUT_FORMAT},
                         {"input_format": CATEGORY_INPUT_FORMAT, "supported_incident_types": []},
                         {"input_format": CATEGORY_INPUT_FORMAT, "supported_incident_types": ["fire", "road_accident"]}):
            with self.assertRaises(ValueError):
                metadata_contract(metadata)

    def test_input_format_never_guesses_a_category_from_description(self):
        self.assertEqual(model_input("A fire happened.", "road_accident"),
                         "[incident_type=road_accident]\nDescription: A fire happened.")
        self.assertEqual(model_input("A fire happened.", "road_accident", LEGACY_INPUT_FORMAT), "A fire happened.")


class DatasetCategoryTests(unittest.TestCase):
    def write(self, directory, rows, fields=CATEGORY_COLUMNS):
        path = Path(directory) / "examples.csv"
        with path.open("w", encoding="utf-8", newline="") as handle:
            writer = csv.DictWriter(handle, fieldnames=fields)
            writer.writeheader()
            writer.writerows(rows)
        return path

    def test_eight_column_legacy_data_and_road_fingerprints_remain_compatible(self):
        rows = [{key: value for key, value in row.items() if key != "incident_type"} for row in examples()[:12]]
        with tempfile.TemporaryDirectory() as directory:
            loaded = load_dataset(self.write(directory, rows, COLUMNS))
        self.assertEqual(loaded, rows)
        explicit = [dict(row, incident_type="road_accident") for row in rows]
        self.assertEqual(dataset_fingerprint(rows), dataset_fingerprint(explicit))
        explicit[0]["incident_type"] = "fire_incident"
        self.assertNotEqual(dataset_fingerprint(rows), dataset_fingerprint(explicit))

    def test_nine_column_categories_are_normalized_and_unknown_or_blank_rejected(self):
        rows = examples()
        rows[0]["incident_type"] = "Road Accident"
        with tempfile.TemporaryDirectory() as directory:
            self.assertEqual(load_dataset(self.write(directory, rows))[0]["incident_type"], "road_accident")
            for value in ("", "fake category"):
                rows[0]["incident_type"] = value
                with self.assertRaisesRegex(ValueError, "incident_type"):
                    load_dataset(self.write(directory, rows))

    def test_generated_review_views_preserve_category_column_and_empty_headers(self):
        with tempfile.TemporaryDirectory() as directory:
            rows = [examples()[12]]
            source = self.write(directory, rows)
            output = Path(directory) / "views"
            export_views(source, output)
            with (output / "minor/examples.csv").open(encoding="utf-8-sig", newline="") as handle:
                reader = csv.DictReader(handle)
                self.assertEqual(reader.fieldnames, list(CATEGORY_COLUMNS))
                self.assertEqual(list(reader), rows)
            with (output / "critical/examples.csv").open(encoding="utf-8-sig", newline="") as handle:
                reader = csv.DictReader(handle)
                self.assertEqual(reader.fieldnames, list(CATEGORY_COLUMNS))
                self.assertEqual(list(reader), [])

    def test_related_rows_cannot_cross_categories_even_with_same_label(self):
        rows = examples()
        rows[1]["group_id"] = rows[0]["group_id"]
        rows[1]["incident_type"] = "fire_incident"
        with tempfile.TemporaryDirectory() as directory:
            with self.assertRaisesRegex(ValueError, "share the incident_type"):
                load_dataset(self.write(directory, rows))

    def test_splits_keep_category_label_coverage_without_group_leakage(self):
        splits = split_groups(examples())
        self.assertEqual(splits, split_groups(list(reversed(examples()))))
        seen = set()
        expected = {(category, label) for category in ("road_accident", "fire_incident")
                    for label in ("minor", "serious", "critical", "needs_review")}
        for rows in splits.values():
            groups = {row["group_id"] for row in rows}
            self.assertFalse(seen.intersection(groups))
            seen.update(groups)
            self.assertEqual({(row["incident_type"], row["label"]) for row in rows}, expected)

    def test_supported_categories_come_from_training_rows_only(self):
        args = argparse.Namespace(base_model="fixture", allow_draft=False, seed=42, review_threshold=.65,
                                  validation_fraction=.15, test_fraction=.15)
        metadata = _metadata(examples(), args, "tfidf_logistic", examples()[:12])
        self.assertEqual(metadata["supported_incident_types"], ["road_accident"])
        self.assertEqual(metadata["observed_incident_types"], ["fire_incident", "road_accident"])
        self.assertEqual(metadata["input_format"], CATEGORY_INPUT_FORMAT)
        self.assertFalse(metadata["approved_for_deployment"])

    def test_evaluation_matches_serving_features_and_abstains_outside_training_scope(self):
        rows = [examples()[0], examples()[12]]
        metadata = {"input_format": CATEGORY_INPUT_FORMAT, "supported_incident_types": ["fire_incident"]}
        predictor = Mock(return_value=[[.01, .01, .97, .01]])
        probabilities = _scoped_probabilities(rows, metadata, predictor)
        predictor.assert_called_once_with([model_input(rows[1]["text"], "fire_incident")])
        self.assertIsNone(probabilities[0])
        metrics = prediction_metrics(rows, probabilities)
        self.assertEqual(set(metrics["per_incident_type"]), set(INCIDENT_TYPES))
        self.assertEqual(metrics["per_incident_type"]["road_accident"]["coverage"], 0)
        self.assertEqual(metrics["per_incident_type"]["fire_incident"]["count"], 1)


class RuntimeCategoryTests(unittest.TestCase):
    def setUp(self):
        root = Path(__file__).resolve().parents[1]
        config = json.loads((root / "active_text_config.json").read_text(encoding="utf-8"))
        self.directory = root / config["model_directory"]

    def test_legacy_runtime_abstains_for_every_untrained_category(self):
        predictor = TfidfSeverityPredictor(self.directory, allow_experimental=True)
        for category in INCIDENT_TYPES[1:]:
            with patch.object(predictor.vectorizer, "transform", side_effect=AssertionError("must not infer")):
                result = predictor.predict("A person is injured.", category)
            self.assertIsNone(result["severity"])
            self.assertIsNone(result["confidence"])
            self.assertEqual(result["probabilities"], {})
            self.assertEqual(result["incident_type"], category)
            self.assertEqual(result["reason"], "unsupported_incident_type")

    def test_new_artifact_feature_parity_between_evaluation_and_runtime(self):
        # Reuse numeric weights solely as a parity fixture; this grants no new model capability.
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory)
            content = (self.directory / "baseline.json").read_bytes()
            metadata = json.loads((self.directory / "metadata.json").read_text(encoding="utf-8"))
            metadata.update({"input_format": CATEGORY_INPUT_FORMAT, "supported_incident_types": ["fire_incident"],
                             "artifact_sha256": hashlib.sha256(content).hexdigest()})
            (path / "baseline.json").write_bytes(content)
            (path / "metadata.json").write_text(json.dumps(metadata), encoding="utf-8")
            runtime = TfidfSeverityPredictor(path, allow_experimental=True)
            evaluator = _baseline_predictor(json.loads(content), CATEGORY_INPUT_FORMAT)
            for text in ("The person has a small wound.", "May sunog ug dili makaginhawa."):
                expected = evaluator([model_input(text, "fire_incident")])[0]
                actual = runtime.predict(text, "fire_incident")
                for label, probability in zip(runtime.metadata["labels"], expected, strict=True):
                    self.assertAlmostEqual(actual["probabilities"][label], probability, places=5)
            unknown = "漢字漢字漢字"
            self.assertEqual(evaluator([model_input(unknown, "fire_incident")]), [[.25] * 4])
            result = runtime.predict(unknown, "fire_incident")
            self.assertIsNone(result["severity"])
            self.assertEqual(result["status"], "needs_review")

    def test_category_gate_runs_before_road_rules_or_classifier(self):
        predictor = SimpleNamespace(metadata={"version": "fixture", "deployment_status": "experimental"},
                                    predict=Mock(side_effect=AssertionError("must not infer")))
        with patch("src.ai_service.text_inference.get_predictor", return_value=predictor), \
                patch.dict(os.environ, {"AI_TEXT_RULES_ENABLED": "true"}), \
                patch("src.ai_service.text_cues.analyze_cues", side_effect=AssertionError("road rules must not run")):
            result = predict_text("Heavy bleeding in a fire.", "fire_incident")
        self.assertEqual(result["status"], "needs_review")

    def test_rules_cannot_bypass_unsupported_scope_or_unscored_truncation(self):
        from src.ai_service.text_cues import analyze_cues
        critical_rule = analyze_cues("The rider is unconscious.")
        for model in ({"reason": "unsupported_incident_type", "severity": None},
                      {"assessment_method": "category_scope_gate", "severity": None},
                      {"confidence": None, "probabilities": {}, "severity": None, "predicted_label": "needs_review"}):
            self.assertIs(combine_assessments(model, critical_rule), model)

    def test_category_metadata_tampering_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            metadata = json.loads((self.directory / "metadata.json").read_text(encoding="utf-8"))
            metadata["supported_incident_types"] = ["fire_incident"]
            (Path(directory) / "metadata.json").write_text(json.dumps(metadata), encoding="utf-8")
            with self.assertRaises(TextModelUnavailable):
                read_metadata(Path(directory), allow_experimental=True)

    def test_http_accepts_category_aliases_and_rejects_unknown_categories(self):
        app = FastAPI()
        app.include_router(router)
        client = TestClient(app)
        with patch.dict(os.environ, {"AI_OPERATION_MODE": "AI_ENABLED"}), \
                patch("src.ai_service.text_api.predict_text", return_value={"status": "needs_review"}) as classify:
            response = client.post("/predict/text", json={"description": "A staged fire report.", "incident_type": "Fire Incident"})
            self.assertEqual(response.status_code, 200)
            classify.assert_called_once_with("A staged fire report.", "fire_incident")
            for category in ("unknown", "", None, 123):
                self.assertEqual(client.post("/predict/text", json={"description": "Report.", "incident_type": category}).status_code, 422)


if __name__ == "__main__":
    unittest.main()
