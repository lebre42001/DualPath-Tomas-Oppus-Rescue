"""Opt-in, local-only text classifier loading; no checkpoint is activated by training."""

from __future__ import annotations

import json
import math
import os
from pathlib import Path
from threading import RLock

from .operation_mode import ai_enabled, operation_mode

TEXT_LABELS = ("minor", "serious", "critical", "needs_review")
SERVICE_ROOT = Path(__file__).resolve().parents[2]


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


def read_metadata(directory: Path, allow_experimental: bool = False) -> dict:
    try:
        metadata = json.loads((directory / "metadata.json").read_text(encoding="utf-8"))
    except (OSError, ValueError) as exc:
        raise TextModelUnavailable("Text model metadata is missing or invalid.") from exc
    if not isinstance(metadata, dict) or metadata.get("schema_version") != 1:
        raise TextModelUnavailable("Unsupported text model metadata format.")
    if metadata.get("labels") != list(TEXT_LABELS):
        raise TextModelUnavailable("Text model label mapping does not match the service.")
    if metadata.get("model_type") != "transformers":
        raise TextModelUnavailable("This artifact is not a deployable transformer text model.")
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
        "model_name": metadata.get("base_model", "multilingual_text_classifier"),
        "model_version": metadata["version"],
        "status": "needs_review" if review else "complete",
        "summary": summary,
        "deployment_status": metadata["deployment_status"],
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

    def predict(self, description: str) -> dict:
        tokens = self.tokenizer(description, return_tensors="pt", truncation=False)
        if tokens["input_ids"].shape[1] > self.metadata["max_length"]:
            return prediction_payload({k: 0.25 for k in TEXT_LABELS}, self.metadata, truncated=True)
        tokens = {key: value.to(self.device) for key, value in tokens.items()}
        with self.torch.inference_mode():
            logits = self.model(**tokens).logits[0]
            scores = self.torch.softmax(logits.float(), dim=-1).cpu().tolist()
        return prediction_payload(dict(zip(TEXT_LABELS, scores, strict=True)), self.metadata)


_LOCK = RLock()
_PREDICTOR: TextSeverityPredictor | None = None
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
            "message": "Configuration checked; use /ready/text to verify checkpoint loading."}


def get_predictor() -> TextSeverityPredictor:
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
            _PREDICTOR = TextSeverityPredictor(directory, allow_experimental=allow)
            _CACHE_KEY = current_key
        return _PREDICTOR


def predict_text(description: str) -> dict:
    with _LOCK:
        return get_predictor().predict(description)
