"""Opt-in text classifier loading; training alone never activates a checkpoint."""

from __future__ import annotations

import json
import hashlib
import math
import os
from pathlib import Path
from threading import RLock

from .operation_mode import ai_enabled, operation_mode
from .incident_types import (CATEGORY_INPUT_FORMAT, INCIDENT_TYPES, metadata_contract,
                             model_input, normalize_incident_type)

TEXT_LABELS = ("minor", "serious", "critical", "needs_review")
SERVICE_ROOT = Path(__file__).resolve().parents[2]
LEGACY_RULE_PROFILE = "road_text_cues_v2"
MULTIINCIDENT_RULE_PROFILE = "multiincident_common_v1"


class TextModelUnavailable(RuntimeError):
    """Safe, user-facing reason why the classifier cannot serve a prediction."""


def enabled(name: str) -> bool:
    return os.getenv(name, "false").strip().lower() in {"1", "true", "yes", "on"}


def configured_directory() -> Path | None:
    value = os.getenv("AI_TEXT_MODEL_DIR", "").strip()
    if not value:
        return None
    directory = Path(value)
    return directory if directory.is_absolute() else SERVICE_ROOT / directory


def text_rule_contract(metadata: dict) -> tuple[str, list[str]]:
    """Only an explicit, trained category scope can opt into expanded rules.

    Missing profile metadata preserves the historical road-only policy. Declared
    profile scopes are canonical capabilities, never aliases or inferred labels.
    """
    input_format, supported = metadata_contract(metadata)
    if "text_rule_profile" not in metadata:
        if "text_rule_incident_types" in metadata:
            raise ValueError("Text rule scope requires an explicit profile.")
        return LEGACY_RULE_PROFILE, ["road_accident"] if "road_accident" in supported else []
    profile = metadata["text_rule_profile"]
    if not isinstance(profile, str) or profile not in {LEGACY_RULE_PROFILE, MULTIINCIDENT_RULE_PROFILE}:
        raise ValueError("Unsupported text rule profile.")
    if profile == LEGACY_RULE_PROFILE:
        scope = ["road_accident"] if "road_accident" in supported else []
        if metadata.get("text_rule_incident_types", scope) != scope:
            raise ValueError("Legacy text rules are restricted to trained road accidents.")
        return profile, scope
    scope = metadata.get("text_rule_incident_types")
    if (input_format != CATEGORY_INPUT_FORMAT or not isinstance(scope, list) or not scope
            or any(not isinstance(category, str) or category not in INCIDENT_TYPES for category in scope)
            or len(scope) != len(set(scope)) or not set(scope) <= set(supported)):
        raise ValueError("Multi-incident text rules require a nonempty canonical scope within trained category-aware capabilities.")
    return profile, list(scope)


def text_rule_capabilities(metadata: dict, *, rules_enabled: bool | None = None) -> dict:
    """Report the actual configured profile and enabled category scope."""
    profile, scope = text_rule_contract(metadata)
    active = enabled("AI_TEXT_RULES_ENABLED") if rules_enabled is None else rules_enabled
    version = None
    if active:
        if profile == MULTIINCIDENT_RULE_PROFILE:
            from .multiincident_cues import RULE_VERSION
        else:
            from .text_cues import RULE_VERSION
        version = RULE_VERSION
    return {"text_rules_enabled": active, "text_rule_profile": profile,
            "text_rule_incident_types": scope, "text_rules_scope": scope if active else [],
            "text_rules_version": version}


def read_metadata(directory: Path, allow_experimental: bool = False) -> dict:
    try:
        metadata = json.loads((directory / "metadata.json").read_text(encoding="utf-8"))
    except (OSError, ValueError) as exc:
        raise TextModelUnavailable("Text model metadata is missing or invalid.") from exc
    if not isinstance(metadata, dict) or metadata.get("schema_version") != 1:
        raise TextModelUnavailable("Unsupported text model metadata format.")
    try:
        input_format, supported_types = metadata_contract(metadata)
        text_rule_contract(metadata)
    except ValueError as exc:
        raise TextModelUnavailable(str(exc)) from exc
    metadata["input_format"] = input_format
    metadata["supported_incident_types"] = supported_types
    if metadata.get("labels") != list(TEXT_LABELS):
        raise TextModelUnavailable("Text model label mapping does not match the service.")
    if metadata.get("model_type") not in {"transformers", "tfidf_logistic"}:
        raise TextModelUnavailable("Unsupported text model type.")
    if metadata["model_type"] == "tfidf_logistic" and (
        not isinstance(metadata.get("artifact_sha256"), str) or len(metadata["artifact_sha256"]) != 64
    ):
        raise TextModelUnavailable("Text model artifact fingerprint is missing.")
    threshold = metadata.get("review_threshold")
    max_length = metadata.get("max_length")
    if (isinstance(threshold, bool) or not isinstance(threshold, (int, float))
            or not math.isfinite(threshold) or not 0 < threshold <= 1):
        raise TextModelUnavailable("Text model review threshold is invalid.")
    if isinstance(max_length, bool) or not isinstance(max_length, int) or not 8 <= max_length <= 512:
        raise TextModelUnavailable("Text model input length is invalid.")
    if not isinstance(metadata.get("version"), str) or not metadata["version"].strip():
        raise TextModelUnavailable("Text model version is missing.")
    approved = (metadata.get("approved_for_deployment") is True
                and metadata.get("all_data_reviewed") is True
                and metadata.get("review_threshold_status") == "validated")
    release = metadata.get("release_review")
    approved = approved and isinstance(release, dict) and all(
        isinstance(release.get(field), str) and release[field].strip()
        for field in ("reviewer", "approved_at", "validation_report")
    )
    if not approved and not allow_experimental:
        raise TextModelUnavailable("Text checkpoint is experimental; reviewed data and release validation are required.")
    metadata["deployment_status"] = "validated" if approved else "experimental"
    return metadata


def category_review_payload(metadata: dict, incident_type: str) -> dict:
    """Abstain before either classifier or road-only rules process a new domain."""
    return {
        "severity": None, "predicted_label": "needs_review", "status": "needs_review",
        "confidence": None, "confidence_calibrated": False, "probabilities": {},
        "responder_review_required": True, "incident_type": incident_type,
        "reason": "unsupported_incident_type", "assessment_method": "category_scope_gate",
        "model_name": metadata.get("display_name", metadata.get("base_model", "multilingual_text_classifier")),
        "model_version": metadata["version"], "deployment_status": metadata["deployment_status"],
        "summary": "This model has not been trained for the selected incident category. Responder review is required.",
    }


def category_capabilities(metadata: dict) -> dict:
    input_format, supported_types = metadata_contract(metadata)
    return {"input_format": input_format, "supported_incident_types": supported_types,
            "category_support_status": "trained_scope_only_requires_separate_category_evaluation"}


def prediction_payload(probabilities: dict[str, float], metadata: dict, *, truncated: bool = False) -> dict:
    if set(probabilities) != set(TEXT_LABELS):
        raise ValueError("Prediction label mapping is invalid.")
    if any(isinstance(p, bool) or not isinstance(p, (int, float))
           or not math.isfinite(p) or not 0 <= p <= 1 for p in probabilities.values()):
        raise ValueError("Prediction probabilities are invalid.")
    if not math.isclose(sum(probabilities.values()), 1, abs_tol=1e-4):
        raise ValueError("Prediction probabilities must sum to one.")
    label = max(TEXT_LABELS, key=lambda item: probabilities[item])
    confidence = probabilities[label]
    review = truncated or label == "needs_review" or confidence < metadata["review_threshold"]
    severity = None if review else label
    experimental = metadata["deployment_status"] == "experimental"
    if truncated:
        summary = "The description exceeds this model's token limit. Responder review is required; no text was silently discarded."
    elif review:
        summary = "The description could not be assigned a reliable severity. Responder review is required."
    else:
        summary = f"Text model suggests {label.title()} urgency from the submitted description. A responder must verify this suggestion."
    if experimental:
        summary = "Experimental model; not validated for dispatch. " + summary
    return {
        "severity": severity,
        "predicted_label": "needs_review" if truncated else label,
        "confidence": None if truncated else round(confidence, 6),
        "confidence_calibrated": False,
        "probabilities": {} if truncated else {k: round(v, 6) for k, v in probabilities.items()},
        "responder_review_required": review or experimental,
        "model_name": metadata.get("display_name", metadata.get("base_model", "multilingual_text_classifier")),
        "model_version": metadata["version"],
        "status": "needs_review" if review else "complete",
        "summary": summary,
        "deployment_status": metadata["deployment_status"],
        "assessment_method": "trained_model",
    }


class TextSeverityPredictor:
    def __init__(self, directory: Path, *, allow_experimental: bool = False):
        self.metadata = read_metadata(directory, allow_experimental)
        try:
            import torch
            from transformers import AutoModelForSequenceClassification, AutoTokenizer

            self.torch = torch
            self.tokenizer = AutoTokenizer.from_pretrained(directory, local_files_only=True, trust_remote_code=False)
            self.model = AutoModelForSequenceClassification.from_pretrained(
                directory, local_files_only=True, trust_remote_code=False, use_safetensors=True,
            )
            actual_labels = [self.model.config.id2label.get(i) for i in range(len(TEXT_LABELS))]
            if actual_labels != list(TEXT_LABELS) or self.model.config.num_labels != len(TEXT_LABELS):
                raise TextModelUnavailable("Checkpoint classifier labels differ from its metadata.")
            limits = [getattr(self.model.config, "max_position_embeddings", 512), self.tokenizer.model_max_length]
            if self.metadata["max_length"] > min(limits):
                raise TextModelUnavailable("Text metadata length exceeds the checkpoint or tokenizer context limit.")
            self.device = os.getenv("AI_TEXT_DEVICE", "cpu")
            if self.device not in {"cpu", "cuda"}:
                raise TextModelUnavailable("Text device must be cpu or cuda.")
            self.model.to(self.device).eval()
        except TextModelUnavailable:
            raise
        except Exception as exc:
            raise TextModelUnavailable("Text checkpoint could not load. Check its local files and optional dependencies.") from exc

    def predict(self, description: str, incident_type: str = "road_accident") -> dict:
        incident_type = normalize_incident_type(incident_type)
        if incident_type not in self.metadata["supported_incident_types"]:
            return category_review_payload(self.metadata, incident_type)
        text = model_input(description, incident_type, self.metadata["input_format"])
        tokens = self.tokenizer(text, return_tensors="pt", truncation=False)
        if tokens["input_ids"].shape[1] > self.metadata["max_length"]:
            return prediction_payload({k: 0.25 for k in TEXT_LABELS}, self.metadata, truncated=True)
        tokens = {key: value.to(self.device) for key, value in tokens.items()}
        with self.torch.inference_mode():
            logits = self.model(**tokens).logits[0]
            scores = self.torch.softmax(logits.float(), dim=-1).cpu().tolist()
        return prediction_payload(dict(zip(TEXT_LABELS, scores, strict=True)), self.metadata)


class TfidfSeverityPredictor:
    """Load the numeric JSON artifact; never unpickle an executable checkpoint."""

    def __init__(self, directory: Path, *, allow_experimental: bool = False):
        self.metadata = read_metadata(directory, allow_experimental)
        try:
            import numpy as np
            from sklearn.feature_extraction.text import TfidfVectorizer

            path = directory / "baseline.json"
            if path.stat().st_size > 16_000_000:
                raise ValueError("Oversized text artifact")
            content = path.read_bytes()
            if hashlib.sha256(content).hexdigest() != self.metadata["artifact_sha256"]:
                raise ValueError("Text artifact checksum mismatch")
            payload = json.loads(content)
            vocabulary = payload["vocabulary"]
            size = len(vocabulary)
            if (payload.get("schema_version") != 1 or payload.get("labels") != list(TEXT_LABELS)
                    or not 1 <= size <= 50_000
                    or not all(isinstance(k, str) and isinstance(v, int) and not isinstance(v, bool)
                               for k, v in vocabulary.items())
                    or set(vocabulary.values()) != set(range(size))):
                raise ValueError("Invalid vocabulary or labels")
            self.coefficients = np.asarray(payload["coefficients"], dtype=float)
            self.intercept = np.asarray(payload["intercept"], dtype=float)
            idf = np.asarray(payload["idf"], dtype=float)
            if (self.coefficients.shape != (4, size) or self.intercept.shape != (4,) or idf.shape != (size,)
                    or not all(np.isfinite(a).all() for a in (self.coefficients, self.intercept, idf))
                    or not (idf > 0).all()):
                raise ValueError("Invalid numeric model weights")
            self.np = np
            self.vectorizer = TfidfVectorizer(analyzer="char_wb", ngram_range=(3, 5),
                                              sublinear_tf=True, vocabulary=vocabulary)
            self.vectorizer.fit(["_"])
            self.vectorizer.idf_ = idf
        except Exception as exc:
            raise TextModelUnavailable("Text classifier could not load. Check its artifact and inference dependencies.") from exc

    def predict(self, description: str, incident_type: str = "road_accident") -> dict:
        incident_type = normalize_incident_type(incident_type)
        if incident_type not in self.metadata["supported_incident_types"]:
            return category_review_payload(self.metadata, incident_type)
        text = model_input(description, incident_type, self.metadata["input_format"])
        if self.vectorizer.transform([description]).nnz == 0:
            return prediction_payload({label: 0.25 for label in TEXT_LABELS}, self.metadata)
        features = self.vectorizer.transform([text])
        if features.nnz == 0:
            return prediction_payload({label: 0.25 for label in TEXT_LABELS}, self.metadata)
        logits = self.np.asarray(features @ self.coefficients.T + self.intercept)[0]
        scores = self.np.exp(logits - logits.max())
        scores /= scores.sum()
        return prediction_payload(dict(zip(TEXT_LABELS, scores.tolist(), strict=True)), self.metadata)


_LOCK = RLock()
_PREDICTOR: TextSeverityPredictor | TfidfSeverityPredictor | None = None
_CACHE_KEY: tuple | None = None


def cache_key(directory: Path, metadata: dict) -> tuple:
    return (str(directory.resolve()), json.dumps(metadata, sort_keys=True), os.getenv("AI_TEXT_DEVICE", "cpu"))


def text_status() -> dict:
    if not ai_enabled():
        return {"enabled": False, "status": "disabled", "model_loaded": False,
                "operation_mode": operation_mode()}
    if not enabled("AI_TEXT_ENABLED"):
        return {"enabled": False, "status": "disabled", "model_loaded": False,
                "operation_mode": operation_mode()}
    directory = configured_directory()
    if directory is None:
        return {"enabled": True, "status": "unavailable", "model_loaded": False,
                "operation_mode": operation_mode(),
                "message": "No text checkpoint is configured."}
    try:
        metadata = read_metadata(directory, enabled("AI_TEXT_ALLOW_EXPERIMENTAL"))
    except TextModelUnavailable as exc:
        return {"enabled": True, "status": "unavailable", "model_loaded": False,
                "operation_mode": operation_mode(), "message": str(exc)}
    with _LOCK:
        loaded = _PREDICTOR is not None and _CACHE_KEY == cache_key(directory, metadata)
    return {"enabled": True, "status": "configured", "model_loaded": loaded,
            "operation_mode": operation_mode(),
            "deployment_status": metadata["deployment_status"], "model_version": metadata["version"],
            **category_capabilities(metadata),
            **text_rule_capabilities(metadata),
            "message": "Configuration checked; use /ready/text to verify checkpoint loading."}


def get_predictor() -> TextSeverityPredictor | TfidfSeverityPredictor:
    global _PREDICTOR, _CACHE_KEY
    if not ai_enabled():
        raise TextModelUnavailable("AI inference is disabled by service operation mode.")
    if not enabled("AI_TEXT_ENABLED"):
        raise TextModelUnavailable("Text AI is disabled.")
    directory = configured_directory()
    if directory is None:
        raise TextModelUnavailable("No text checkpoint is configured.")
    allow = enabled("AI_TEXT_ALLOW_EXPERIMENTAL")
    # Recheck release metadata on every request, including revocation of approval.
    metadata = read_metadata(directory, allow)
    current_key = cache_key(directory, metadata)
    with _LOCK:
        if current_key != _CACHE_KEY:
            _PREDICTOR = None
            _CACHE_KEY = None
            predictor_type = TfidfSeverityPredictor if metadata["model_type"] == "tfidf_logistic" else TextSeverityPredictor
            _PREDICTOR = predictor_type(directory, allow_experimental=allow)
            _CACHE_KEY = current_key
        return _PREDICTOR


def predict_text(description: str, incident_type: str = "road_accident") -> dict:
    incident_type = normalize_incident_type(incident_type)
    with _LOCK:
        predictor = get_predictor()
        _, supported_types = metadata_contract(predictor.metadata)
        if incident_type not in supported_types:
            return category_review_payload(predictor.metadata, incident_type)
        # Preserve the call contract for existing road-only prediction adapters.
        if predictor.metadata.get("input_format", "description_v1") == "description_v1":
            model_result = predictor.predict(description)
        else:
            model_result = predictor.predict(description, incident_type)
        model_result = {**model_result, "incident_type": incident_type}
        return apply_text_rule_profile(description, incident_type, model_result, predictor.metadata,
                                       rules_enabled=enabled("AI_TEXT_RULES_ENABLED"))


def apply_text_rule_profile(description: str, incident_type: str, model_result: dict,
                            metadata: dict, *, rules_enabled: bool) -> dict:
    """Shared serving/evaluation dispatch; never broaden the checkpoint scope."""
    category = normalize_incident_type(incident_type)
    _, supported = metadata_contract(metadata)
    if category not in supported:
        return category_review_payload(metadata, category)
    profile, scope = text_rule_contract(metadata)
    if not rules_enabled or category not in scope:
        return model_result
    if profile == MULTIINCIDENT_RULE_PROFILE:
        from .multiincident_cues import analyze_multiincident_cues
        rules = analyze_multiincident_cues(description, category)
    else:
        from .text_cues import analyze_cues
        rules = analyze_cues(description)
    return {**combine_assessments(model_result, rules), "incident_type": category}


def combine_assessments(model_result: dict, rule_result: dict | None) -> dict:
    if rule_result is None:
        return model_result
    if (model_result.get("reason") == "unsupported_incident_type"
            or model_result.get("assessment_method") == "category_scope_gate"
            or (model_result.get("confidence") is None and model_result.get("probabilities") == {})):
        return model_result
    # Preserve the advisory fallback for a present injury/damage cue only when
    # the classifier abstains without a Critical leading signal. Context blocks
    # (uncertainty, history, negation or contradiction) arrive as Needs-review
    # overrides from analyze_cues and cannot be filled by this fallback.
    fallback = (rule_result["rule_priority"] == "fallback"
                and rule_result.get("severity") in {"minor", "serious"}
                and model_result.get("severity") is None
                and model_result.get("predicted_label") != "critical")
    minimum = (rule_result["rule_priority"] == "minimum"
               and rule_result.get("severity") == "serious"
               and model_result.get("severity") not in {"serious", "critical"}
               and model_result.get("predicted_label") != "critical")
    use_rules = rule_result["rule_priority"] == "override" or fallback or minimum
    return {**rule_result, "model_assessment": model_result} if use_rules else model_result
