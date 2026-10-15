"""Inference boundaries, release gating and HTTP contract; no model download needed."""

import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

from fastapi import FastAPI
from fastapi.testclient import TestClient

from src.ai_service.text_api import router
from src.ai_service.text_inference import (
    TEXT_LABELS, TextModelUnavailable, prediction_payload, read_metadata, text_status,
)


def metadata(approved=False):
    return {
        "schema_version": 1, "labels": list(TEXT_LABELS), "model_type": "transformers",
        "version": "test-v1", "base_model": "test-only", "max_length": 128,
        "review_threshold": 0.65, "review_threshold_status": "validated" if approved else "provisional",
        "approved_for_deployment": approved, "all_data_reviewed": approved,
        "release_review": {"reviewer": "unit-test-fixture", "approved_at": "test", "validation_report": "test"},
        "deployment_status": "validated" if approved else "experimental",
    }


class MetadataTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.directory = Path(self.temp.name)
        self.addCleanup(self.temp.cleanup)

    def write(self, data):
        (self.directory / "metadata.json").write_text(json.dumps(data), encoding="utf-8")

    def test_draft_model_not_served_by_default(self):
        self.write(metadata())
        with self.assertRaises(TextModelUnavailable):
            read_metadata(self.directory)
        self.assertEqual(read_metadata(self.directory, True)["deployment_status"], "experimental")

    def test_approval_flag_alone_is_not_sufficient(self):
        data = metadata()
        data["approved_for_deployment"] = True
        self.write(data)
        with self.assertRaises(TextModelUnavailable):
            read_metadata(self.directory)

    def test_release_requires_threshold_validation_and_review(self):
        for invalid_field, invalid_value in (("review_threshold_status", "provisional"),
                                             ("release_review", {}), ("all_data_reviewed", False)):
            data = metadata(True)
            data[invalid_field] = invalid_value
            self.write(data)
            with self.assertRaises(TextModelUnavailable):
                read_metadata(self.directory)

    def test_reviewed_release_metadata_accepted(self):
        self.write(metadata(True))
        self.assertEqual(read_metadata(self.directory)["deployment_status"], "validated")

    def test_invalid_configuration_rejected_even_for_experiment(self):
        for field, value in (("labels", list(reversed(TEXT_LABELS))), ("review_threshold", float("nan")),
                             ("review_threshold", True), ("max_length", 0), ("model_type", "tfidf_logistic")):
            data = metadata()
            data[field] = value
            self.write(data)
            with self.assertRaises(TextModelUnavailable):
                read_metadata(self.directory, True)

    def test_disabled_health_does_not_load_transformers(self):
        with patch.dict(os.environ, {"AI_TEXT_ENABLED": "false"}):
            self.assertEqual(text_status()["status"], "disabled")

    def test_health_does_not_report_stale_checkpoint_as_loaded(self):
        self.write(metadata(True))
        with patch.dict(os.environ, {"AI_OPERATION_MODE": "AI_ENABLED", "AI_TEXT_ENABLED": "true", "AI_TEXT_MODEL_DIR": str(self.directory)}), \
                patch("src.ai_service.text_inference._PREDICTOR", object()), \
                patch("src.ai_service.text_inference._CACHE_KEY", ("different-model",)):
            self.assertFalse(text_status()["model_loaded"])


class PredictionTests(unittest.TestCase):
    def test_critical_is_urgency_not_fatality(self):
        result = prediction_payload(dict(zip(TEXT_LABELS, [0.02, 0.02, 0.94, 0.02])), metadata(True))
        self.assertEqual(result["severity"], "critical")
        self.assertIn("urgency", result["summary"])
        self.assertFalse(result["confidence_calibrated"])

    def test_uncertain_output_never_defaults_to_minor(self):
        result = prediction_payload({k: 0.25 for k in TEXT_LABELS}, metadata(True))
        self.assertIsNone(result["severity"])
        self.assertTrue(result["responder_review_required"])
        self.assertEqual(result["status"], "needs_review")

    def test_review_class_abstains_even_at_high_score(self):
        result = prediction_payload(dict(zip(TEXT_LABELS, [0.01, 0.01, 0.01, 0.97])), metadata(True))
        self.assertIsNone(result["severity"])

    def test_experimental_prediction_discloses_status(self):
        result = prediction_payload(dict(zip(TEXT_LABELS, [0.97, 0.01, 0.01, 0.01])), metadata())
        self.assertTrue(result["responder_review_required"])
        self.assertIn("Experimental", result["summary"])

    def test_long_input_abstains_without_fabricated_score(self):
        result = prediction_payload({k: 0.25 for k in TEXT_LABELS}, metadata(True), truncated=True)
        self.assertIsNone(result["severity"])
        self.assertIsNone(result["confidence"])
        self.assertEqual(result["probabilities"], {})

    def test_invalid_model_output_is_rejected(self):
        for scores in ({k: float("nan") for k in TEXT_LABELS}, {k: 0.9 for k in TEXT_LABELS}, {"minor": 1.0}):
            with self.assertRaises(ValueError):
                prediction_payload(scores, metadata(True))


class HttpContractTests(unittest.TestCase):
    def setUp(self):
        app = FastAPI()
        app.include_router(router)
        self.client = TestClient(app)

    def test_rejects_empty_long_or_nontext_input_and_extra_fields(self):
        for data in ({"description": " "}, {"description": "x" * 1001}, {"description": 23},
                     {"description": "test", "severity": "minor"}, {}):
            self.assertEqual(self.client.post("/predict/text", json=data).status_code, 422)

    def test_disabled_service_returns_503(self):
        with patch.dict(os.environ, {"AI_TEXT_ENABLED": "false"}):
            self.assertEqual(self.client.post("/predict/text", json={"description": "Nabangga ang motor."}).status_code, 503)
            self.assertEqual(self.client.get("/ready/text").status_code, 503)

    def test_unavailable_response_does_not_leak_exception_or_input(self):
        with patch.dict(os.environ, {"AI_OPERATION_MODE": "AI_ENABLED"}), \
                patch("src.ai_service.text_api.predict_text", side_effect=RuntimeError("private input or secret path")):
            response = self.client.post("/predict/text", json={"description": "test report"})
        self.assertEqual(response.status_code, 503)
        self.assertNotIn("private", response.text)
        self.assertNotIn("test report", response.text)

    def test_description_only_request_accepts_multilingual_text(self):
        expected = prediction_payload({k: 0.25 for k in TEXT_LABELS}, metadata(True))
        with patch.dict(os.environ, {"AI_OPERATION_MODE": "AI_ENABLED"}), \
                patch("src.ai_service.text_api.predict_text", return_value=expected) as classify:
            response = self.client.post("/predict/text", json={"description": "  Nabangga ang motor, may injured passenger.  "})
        self.assertEqual(response.status_code, 200)
        classify.assert_called_once_with("Nabangga ang motor, may injured passenger.")


if __name__ == "__main__":
    unittest.main()
