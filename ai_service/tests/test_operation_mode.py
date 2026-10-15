"""Service-wide manual-only mode must block all model work by default."""

import os
import unittest
from types import SimpleNamespace
from unittest.mock import patch

from fastapi.testclient import TestClient

from src.ai_service import api
from src.ai_service.text_inference import TEXT_LABELS


class OperationModeTests(unittest.TestCase):
    def setUp(self):
        self.client = TestClient(api.app)

    def test_manual_only_blocks_image_text_readiness_and_warmup_even_with_checkpoints(self):
        env = {"AI_OPERATION_MODE": "MANUAL_ONLY", "AI_TEXT_ENABLED": "true",
               "AI_PRELOAD_MODELS": "true", "AI_CHECKPOINT_SYNC_ENABLED": "true"}
        with patch.dict(os.environ, env), \
                patch.object(api, "CONFIG", object()), \
                patch.object(api, "CHECKPOINT_EXISTS", True), \
                patch.object(api, "RELEVANCE_CONFIG", object()), \
                patch.object(api, "RELEVANCE_CHECKPOINT_EXISTS", True), \
                patch.object(api, "predict_relevance") as image_relevance, \
                patch.object(api, "predict_severity") as image_severity, \
                patch.object(api, "warm_predictors") as warm, \
                patch("src.ai_service.text_api.predict_text") as text_predict, \
                patch("src.ai_service.text_api.get_predictor") as text_ready, \
                patch("src.ai_service.text_inference.TextSeverityPredictor") as text_model:
            health = self.client.get("/health")
            self.assertEqual(health.status_code, 200)
            self.assertEqual(health.json()["operation_mode"], "MANUAL_ONLY")
            self.assertFalse(health.json()["inference_enabled"])
            self.assertFalse(health.json()["text_ai"]["model_loaded"])
            text_health = self.client.get("/health/text")
            self.assertEqual(text_health.status_code, 200)
            self.assertEqual(text_health.json()["status"], "disabled")
            self.assertEqual(text_health.json()["operation_mode"], "MANUAL_ONLY")

            self.assertEqual(self.client.get("/ready").status_code, 503)
            self.assertEqual(self.client.get("/ready/text").status_code, 503)
            self.assertEqual(self.client.get("/warmup").status_code, 503)
            self.assertEqual(self.client.post("/predict", files={"file": ("crash.jpg", b"image bytes")}).status_code, 503)
            self.assertEqual(self.client.post("/predict/text", json={"description": "May aksidente."}).status_code, 503)

            image_relevance.assert_not_called()
            image_severity.assert_not_called()
            warm.assert_not_called()
            text_predict.assert_not_called()
            text_ready.assert_not_called()
            text_model.assert_not_called()

    def test_manual_only_startup_does_not_preload_or_sync_even_when_flags_are_true(self):
        import serve_api

        with patch.dict(os.environ, {"AI_OPERATION_MODE": "MANUAL_ONLY", "AI_PRELOAD_MODELS": "true"}), \
                patch.object(api, "warm_predictors") as warm, \
                patch.object(serve_api, "sync_checkpoints_from_env") as sync, \
                patch.object(serve_api.uvicorn, "run") as run:
            api.preload_models()
            serve_api.main()
        warm.assert_not_called()
        sync.assert_not_called()
        run.assert_called_once()

    def test_explicit_ai_enabled_preserves_existing_routes(self):
        prediction = SimpleNamespace(
            severity="critical", confidence=0.91,
            probabilities={"minor": 0.01, "serious": 0.03, "critical": 0.91, "needs_review": 0.05},
            responder_review_required=True, responder_review_action="review",
        )
        text_result = {"severity": "serious", "predicted_label": "serious", "confidence": 0.8,
                       "confidence_calibrated": False, "probabilities": {label: 0.25 for label in TEXT_LABELS},
                       "responder_review_required": True, "model_name": "fixture", "model_version": "test",
                       "status": "complete", "summary": "fixture", "deployment_status": "validated"}
        with patch.dict(os.environ, {"AI_OPERATION_MODE": "AI_ENABLED", "AI_TEXT_ENABLED": "true"}), \
                patch.object(api, "CONFIG", object()), \
                patch.object(api, "CHECKPOINT_EXISTS", True), \
                patch.object(api, "RELEVANCE_CONFIG", None), \
                patch.object(api, "RELEVANCE_CHECKPOINT_EXISTS", False), \
                patch.object(api, "predict_relevance", return_value=None), \
                patch.object(api, "predict_severity", return_value=prediction) as image_predict, \
                patch.object(api, "warm_predictors", return_value={"status": "ready"}) as warm, \
                patch("src.ai_service.text_api.predict_text", return_value=text_result) as text_predict, \
                patch("src.ai_service.text_api.get_predictor", return_value=SimpleNamespace(
                    metadata={"deployment_status": "validated", "version": "test"})):
            self.assertEqual(self.client.get("/ready").status_code, 200)
            self.assertEqual(self.client.get("/ready/text").status_code, 200)
            self.assertEqual(self.client.get("/warmup").status_code, 200)
            image = self.client.post("/predict", files={"file": ("crash.jpg", b"image bytes")})
            self.assertEqual(image.status_code, 200)
            self.assertEqual(image.json()["severity"], "critical")
            text = self.client.post("/predict/text", json={"description": "May aksidente."})
            self.assertEqual(text.status_code, 200)
            self.assertEqual(text.json()["severity"], "serious")
        image_predict.assert_called_once()
        warm.assert_called_once()
        text_predict.assert_called_once_with("May aksidente.")

    def test_unrecognized_operation_mode_fails_closed(self):
        with patch.dict(os.environ, {"AI_OPERATION_MODE": "sometimes"}):
            self.assertEqual(self.client.get("/ready").status_code, 503)
            self.assertEqual(self.client.get("/health").json()["operation_mode"], "MANUAL_ONLY")


if __name__ == "__main__":
    unittest.main()
