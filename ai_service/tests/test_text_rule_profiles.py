"""Explicit model-bound rule profiles and evaluation parity; no training."""
import json
import os
from pathlib import Path
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch

from evaluate_text_cues import evaluate_rows
from src.ai_service import api, text_api
from src.ai_service.incident_types import CATEGORY_INPUT_FORMAT, INCIDENT_TYPES
from src.ai_service.multiincident_cues import RULE_VERSION as MULTI_RULE_VERSION
from src.ai_service.text_inference import (LEGACY_RULE_PROFILE, MULTIINCIDENT_RULE_PROFILE,
    TEXT_LABELS, TextModelUnavailable, apply_text_rule_profile, predict_text,
    prediction_payload, read_metadata, text_rule_capabilities, text_rule_contract)


def metadata(*, profile=False, supported=None):
    value = {"schema_version": 1, "version": "profile-fixture", "labels": list(TEXT_LABELS),
             "model_type": "transformers", "max_length": 128, "review_threshold": .65,
             "input_format": CATEGORY_INPUT_FORMAT,
             "supported_incident_types": list(INCIDENT_TYPES) if supported is None else supported,
             "deployment_status": "experimental", "approved_for_deployment": False,
             "all_data_reviewed": False}
    if profile:
        value.update({"text_rule_profile": MULTIINCIDENT_RULE_PROFILE,
                      "text_rule_incident_types": list(value["supported_incident_types"])})
    return value


def model_payload():
    return prediction_payload(dict(zip(TEXT_LABELS, [.94, .02, .02, .02], strict=True)), metadata())


def rule_payload():
    return {"severity": "critical", "predicted_label": "critical", "confidence": None,
            "probabilities": None, "responder_review_required": True,
            "status": "complete", "assessment_method": "text_rules", "rule_priority": "override"}


class RuleProfileContractTests(unittest.TestCase):
    def test_missing_profile_preserves_road_only_rules_even_on_category_model(self):
        self.assertEqual(text_rule_contract(metadata()), (LEGACY_RULE_PROFILE, ["road_accident"]))
        self.assertEqual(text_rule_contract(metadata(supported=["fire_incident"])), (LEGACY_RULE_PROFILE, []))
        self.assertEqual(text_rule_contract({}), (LEGACY_RULE_PROFILE, ["road_accident"]))

    def test_valid_explicit_profile_can_scope_only_to_trained_categories(self):
        value = metadata(profile=True, supported=["road_accident", "fire_incident"])
        value["text_rule_incident_types"] = ["fire_incident"]
        self.assertEqual(text_rule_contract(value), (MULTIINCIDENT_RULE_PROFILE, ["fire_incident"]))
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory)
            (path / "metadata.json").write_text(json.dumps(value), encoding="utf-8")
            loaded = read_metadata(path, allow_experimental=True)
        self.assertEqual(loaded["text_rule_profile"], MULTIINCIDENT_RULE_PROFILE)
        self.assertFalse(loaded["approved_for_deployment"])

    def test_unknown_or_unsafe_profile_metadata_fails_closed_during_loading(self):
        invalid = []
        for profile in (None, True, "invented_profile"):
            invalid.append({**metadata(profile=True), "text_rule_profile": profile})
        for scope in (None, [], "fire_incident", ["Fire"], ["unknown"], ["fire_incident", "fire_incident"], [["fire_incident"]]):
            invalid.append({**metadata(profile=True), "text_rule_incident_types": scope})
        missing_scope = metadata(profile=True)
        missing_scope.pop("text_rule_incident_types")
        invalid.append(missing_scope)
        invalid.append({**metadata(), "text_rule_incident_types": ["fire_incident"]})
        invalid.append({**metadata(profile=True, supported=["road_accident"]), "text_rule_incident_types": ["fire_incident"]})
        invalid.append({**metadata(profile=True, supported=["road_accident"]), "input_format": "description_v1"})
        invalid.append({**metadata(), "text_rule_profile": LEGACY_RULE_PROFILE, "text_rule_incident_types": ["fire_incident"]})
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory)
            for value in invalid:
                with self.subTest(value=value):
                    (path / "metadata.json").write_text(json.dumps(value), encoding="utf-8")
                    with self.assertRaises(TextModelUnavailable):
                        read_metadata(path, allow_experimental=True)


class RuleProfileDispatchTests(unittest.TestCase):
    def test_legacy_profile_never_calls_new_rules_for_nonroad_category(self):
        value, model = metadata(), model_payload()
        with patch("src.ai_service.multiincident_cues.analyze_multiincident_cues", side_effect=AssertionError("not opted in")), \
                patch("src.ai_service.text_cues.analyze_cues", side_effect=AssertionError("road only")):
            self.assertIs(apply_text_rule_profile("A fire report.", "fire_incident", model, value, rules_enabled=True), model)

    def test_new_profile_dispatches_each_explicit_category_and_disabled_skips_rules(self):
        value, model = metadata(profile=True), model_payload()
        with patch("src.ai_service.multiincident_cues.analyze_multiincident_cues", return_value=rule_payload()) as analyze, \
                patch("src.ai_service.text_cues.analyze_cues", side_effect=AssertionError("legacy must not run")):
            for category in INCIDENT_TYPES:
                result = apply_text_rule_profile("A current danger.", category, model, value, rules_enabled=True)
                self.assertEqual(result["severity"], "critical")
                self.assertIsNone(result["confidence"])
                self.assertIs(result["model_assessment"], model)
                analyze.assert_called_with("A current danger.", category)
            analyze.reset_mock()
            self.assertIs(apply_text_rule_profile("A current danger.", "fire_incident", model, value, rules_enabled=False), model)
            analyze.assert_not_called()

    def test_new_profile_subset_does_not_apply_rules_outside_explicit_scope(self):
        value, model = metadata(profile=True), model_payload()
        value["text_rule_incident_types"] = ["fire_incident"]
        with patch("src.ai_service.multiincident_cues.analyze_multiincident_cues", side_effect=AssertionError("outside explicit scope")), \
                patch("src.ai_service.text_cues.analyze_cues", side_effect=AssertionError("no implicit legacy fallback")):
            self.assertIs(apply_text_rule_profile("A road report.", "road_accident", model, value, rules_enabled=True), model)

    def test_unsupported_category_gates_before_classifier_and_either_profile(self):
        value = metadata(profile=True, supported=["road_accident"])
        predictor = SimpleNamespace(metadata=value, predict=Mock(side_effect=AssertionError("outside model scope")))
        with patch("src.ai_service.text_inference.get_predictor", return_value=predictor), \
                patch.dict(os.environ, {"AI_TEXT_RULES_ENABLED": "true"}), \
                patch("src.ai_service.multiincident_cues.analyze_multiincident_cues", side_effect=AssertionError("no rules")):
            result = predict_text("A fire patient is unconscious.", "fire_incident")
        self.assertEqual(result["reason"], "unsupported_incident_type")
        self.assertIsNone(result["severity"])

    def test_unscored_model_output_cannot_be_filled_by_new_profile(self):
        value = metadata(profile=True)
        model = {"confidence": None, "probabilities": {}, "severity": None, "predicted_label": "needs_review"}
        with patch("src.ai_service.multiincident_cues.analyze_multiincident_cues", return_value=rule_payload()):
            result = apply_text_rule_profile("Long input.", "fire_incident", model, value, rules_enabled=True)
        self.assertEqual(result["severity"], None)
        self.assertEqual(result["probabilities"], {})

    def test_evaluator_and_runtime_share_new_profile_dispatch(self):
        value = metadata(profile=True)
        predictor = SimpleNamespace(metadata=value, predict=Mock(side_effect=lambda *args: model_payload()))
        row = {"case_id": "fixture", "language": "en", "incident_type": "medical_emergency",
               "text": "A patient needs help.", "label": "critical"}
        with patch("src.ai_service.text_inference.get_predictor", return_value=predictor), \
                patch.dict(os.environ, {"AI_TEXT_RULES_ENABLED": "true"}), \
                patch("src.ai_service.multiincident_cues.analyze_multiincident_cues", return_value=rule_payload()):
            served = predict_text(row["text"], row["incident_type"])
            metrics, records = evaluate_rows([row], predictor)
        self.assertEqual(served["severity"], records[0]["suggestion"])
        self.assertEqual(metrics["accuracy"], 1)
        self.assertEqual(metrics["text_rule_profile"], MULTIINCIDENT_RULE_PROFILE)
        self.assertEqual(metrics["rules_scope"], list(INCIDENT_TYPES))
        self.assertEqual(metrics["rule_version"], MULTI_RULE_VERSION)

    def test_readiness_reports_actual_profile_version_and_effective_scope(self):
        value = metadata(profile=True)
        predictor = SimpleNamespace(metadata=value)
        with patch.dict(os.environ, {"AI_OPERATION_MODE": "AI_ENABLED", "AI_TEXT_RULES_ENABLED": "true"}), \
                patch("src.ai_service.text_api.get_predictor", return_value=predictor), \
                patch("src.ai_service.api.get_predictor", return_value=predictor):
            for ready in (text_api.ready_text, api.ready, api.warmup):
                status = ready()
                self.assertEqual(status["text_rule_profile"], MULTIINCIDENT_RULE_PROFILE)
                self.assertEqual(status["text_rule_incident_types"], list(INCIDENT_TYPES))
                self.assertEqual(status["text_rules_scope"], list(INCIDENT_TYPES))
                self.assertEqual(status["text_rules_version"], MULTI_RULE_VERSION)
        disabled = text_rule_capabilities(value, rules_enabled=False)
        self.assertFalse(disabled["text_rules_enabled"])
        self.assertEqual(disabled["text_rules_scope"], [])
        self.assertIsNone(disabled["text_rules_version"])


if __name__ == "__main__":
    unittest.main()
