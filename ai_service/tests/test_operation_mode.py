"""The runtime exposes description inference without an image model."""

import os
import unittest
from types import SimpleNamespace
from unittest.mock import patch

from fastapi import HTTPException

from src.ai_service import api


class OperationModeTests(unittest.TestCase):
    def test_manual_only_blocks_text_and_has_no_image_endpoint(self):
        with patch.dict(os.environ, {"AI_OPERATION_MODE": "MANUAL_ONLY", "AI_TEXT_ENABLED": "true"}), \
                patch.object(api, "get_predictor") as predictor:
            health = api.health()
            self.assertFalse(health["inference_enabled"])
            self.assertFalse(health["text_ai"]["model_loaded"])
            self.assertNotIn("image_ai", health)
            with self.assertRaises(HTTPException) as ready_error:
                api.ready()
            self.assertEqual(ready_error.exception.status_code, 503)
            with self.assertRaises(HTTPException):
                api.warmup()
            predictor.assert_not_called()
        self.assertNotIn("/predict", api.app.openapi()["paths"])
        self.assertIn("/predict/text", api.app.openapi()["paths"])

    def test_text_readiness_does_not_require_image_checkpoint(self):
        with patch.dict(os.environ, {"AI_OPERATION_MODE": "AI_ENABLED", "AI_TEXT_ENABLED": "true"}), \
                patch.object(api, "get_predictor", return_value=SimpleNamespace(
                    metadata={"deployment_status": "validated", "version": "test"})) as predictor:
            self.assertEqual(api.ready()["model_version"], "test")
            self.assertEqual(api.warmup()["status"], "ready")
        self.assertEqual(predictor.call_count, 2)

    def test_unrecognized_operation_mode_fails_closed(self):
        with patch.dict(os.environ, {"AI_OPERATION_MODE": "sometimes"}):
            with self.assertRaises(HTTPException):
                api.ready()
            self.assertEqual(api.health()["operation_mode"], "MANUAL_ONLY")


if __name__ == "__main__":
    unittest.main()
