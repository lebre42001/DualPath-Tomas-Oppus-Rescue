"""FastAPI wrapper for local model inference."""

from __future__ import annotations

import os
import sys
from gc import collect
from threading import RLock

from fastapi import FastAPI, File, HTTPException, UploadFile

from .checkpoint_sync import is_usable_checkpoint
from .config import load_config
from .defaults import resolve_active_config_path, resolve_photo_relevance_config_path
from .inference import PhotoRelevancePredictor, SeverityPredictor
from .operation_mode import ai_enabled, operation_mode
from .text_api import router as text_router
from .text_inference import text_status

CONFIG_PATH = resolve_active_config_path()
RELEVANCE_CONFIG_PATH = resolve_photo_relevance_config_path()

app = FastAPI(title="Stitch AI Severity Service", version="0.3.0")
app.include_router(text_router)

try:
    CONFIG = load_config(CONFIG_PATH)
    CHECKPOINT_EXISTS = is_usable_checkpoint(CONFIG.outputs.best_checkpoint_path)
except Exception:
    CONFIG = None
    CHECKPOINT_EXISTS = False

try:
    RELEVANCE_CONFIG = load_config(RELEVANCE_CONFIG_PATH)
    RELEVANCE_CHECKPOINT_EXISTS = is_usable_checkpoint(RELEVANCE_CONFIG.outputs.best_checkpoint_path)
except Exception:
    RELEVANCE_CONFIG = None
    RELEVANCE_CHECKPOINT_EXISTS = False

PREDICTOR: SeverityPredictor | None = None
RELEVANCE_PREDICTOR: PhotoRelevancePredictor | None = None
PREDICTOR_LOCK = RLock()


def truthy_env(name: str, default: bool = False) -> bool:
    value = os.getenv(name)
    if value is None:
        return default

    return value.strip().lower() in {"1", "true", "yes", "on"}


def float_env(name: str, default: float) -> float:
    value = os.getenv(name)
    if value is None:
        return default

    try:
        return float(value)
    except ValueError:
        return default


def single_model_cache_enabled() -> bool:
    return truthy_env("AI_SINGLE_MODEL_CACHE", default=True)


def unload_after_predict_enabled() -> bool:
    return truthy_env("AI_UNLOAD_AFTER_PREDICT", default=False)


def relevance_severity_override_enabled() -> bool:
    return truthy_env("AI_RELEVANCE_SEVERITY_OVERRIDE_ENABLED", default=False)


def relevance_severity_override_labels() -> set[str]:
    raw_labels = os.getenv("AI_RELEVANCE_SEVERITY_OVERRIDE_LABELS", "serious,fatal")

    return {
        label.strip().lower()
        for label in raw_labels.split(",")
        if label.strip()
    }


def release_unused_memory() -> None:
    collect()

    try:
        import torch

        if torch.cuda.is_available():
            torch.cuda.empty_cache()
    except Exception:
        # Memory cleanup is a best-effort safety valve for small hosted instances.
        pass


def unload_severity_predictor() -> None:
    global PREDICTOR

    if PREDICTOR is not None:
        PREDICTOR = None
        release_unused_memory()


def unload_relevance_predictor() -> None:
    global RELEVANCE_PREDICTOR

    if RELEVANCE_PREDICTOR is not None:
        RELEVANCE_PREDICTOR = None
        release_unused_memory()


def severity_predictor() -> SeverityPredictor:
    global PREDICTOR

    if not ai_enabled():
        raise HTTPException(status_code=503, detail={"status": "disabled", "operation_mode": operation_mode()})
    if CONFIG is None or not CHECKPOINT_EXISTS:
        raise HTTPException(status_code=503, detail="Model checkpoint not available yet. Train or sync the model first.")

    with PREDICTOR_LOCK:
        if single_model_cache_enabled():
            unload_relevance_predictor()

        if PREDICTOR is None:
            PREDICTOR = SeverityPredictor(CONFIG)

        return PREDICTOR


def relevance_predictor() -> PhotoRelevancePredictor | None:
    global RELEVANCE_PREDICTOR

    if not ai_enabled():
        raise HTTPException(status_code=503, detail={"status": "disabled", "operation_mode": operation_mode()})
    if RELEVANCE_CONFIG is None or not RELEVANCE_CHECKPOINT_EXISTS:
        return None

    with PREDICTOR_LOCK:
        if single_model_cache_enabled():
            unload_severity_predictor()

        if RELEVANCE_PREDICTOR is None:
            RELEVANCE_PREDICTOR = PhotoRelevancePredictor(RELEVANCE_CONFIG)

        return RELEVANCE_PREDICTOR


def predict_relevance(file_bytes: bytes):
    predictor = relevance_predictor()
    if predictor is None:
        return None

    try:
        return predictor.predict_bytes(file_bytes)
    finally:
        predictor = None
        if single_model_cache_enabled():
            unload_relevance_predictor()


def predict_severity(file_bytes: bytes):
    predictor = severity_predictor()

    try:
        return predictor.predict_bytes(file_bytes)
    finally:
        predictor = None
        if unload_after_predict_enabled():
            unload_severity_predictor()


def warm_predictors() -> dict[str, object]:
    if not ai_enabled():
        raise HTTPException(status_code=503, detail={"status": "disabled", "operation_mode": operation_mode()})
    with PREDICTOR_LOCK:
        photo_gate = relevance_predictor()
        if single_model_cache_enabled():
            photo_gate = None
            unload_relevance_predictor()

        predictor = severity_predictor()
        if unload_after_predict_enabled():
            predictor = None
            unload_severity_predictor()

    return {
        "status": "ready",
        "single_model_cache_enabled": single_model_cache_enabled(),
        "unload_after_predict_enabled": unload_after_predict_enabled(),
        "relevance_severity_override_enabled": relevance_severity_override_enabled(),
        "relevance_severity_override_labels": sorted(relevance_severity_override_labels()),
        "severity_predictor_loaded": PREDICTOR is not None,
        "photo_relevance_predictor_loaded": RELEVANCE_PREDICTOR is not None,
    }


@app.on_event("startup")
def preload_models() -> None:
    if not ai_enabled():
        return
    if not truthy_env("AI_PRELOAD_MODELS", default=False):
        return

    try:
        warm_predictors()
    except Exception as exc:
        print(f"[ai-service] model preload failed: {exc}", file=sys.stderr, flush=True)

        if truthy_env("AI_PRELOAD_MODELS_REQUIRED", default=False):
            raise


@app.get("/health")
def health() -> dict[str, object]:
    if not ai_enabled():
        return {
            "status": "ok",
            "operation_mode": operation_mode(),
            "inference_enabled": False,
            "text_ai": {"enabled": False, "status": "disabled", "model_loaded": False},
            "image_ai": {"enabled": False, "status": "disabled", "model_loaded": False},
            "message": "AI inference is disabled by service operation mode.",
        }
    return {
        "status": "ok",
        "operation_mode": operation_mode(),
        "inference_enabled": True,
        "text_ai": text_status(),
        "config_loaded": CONFIG is not None,
        "checkpoint_ready": CHECKPOINT_EXISTS,
        "config_path": str(CONFIG.config_path) if CONFIG is not None else None,
        "experiment_name": CONFIG.experiment.name if CONFIG is not None else None,
        "checkpoint_path": str(CONFIG.outputs.best_checkpoint_path) if CONFIG is not None else None,
        "photo_relevance_config_loaded": RELEVANCE_CONFIG is not None,
        "photo_relevance_checkpoint_ready": RELEVANCE_CHECKPOINT_EXISTS,
        "photo_relevance_config_path": str(RELEVANCE_CONFIG.config_path) if RELEVANCE_CONFIG is not None else None,
        "photo_relevance_experiment_name": RELEVANCE_CONFIG.experiment.name if RELEVANCE_CONFIG is not None else None,
        "photo_relevance_checkpoint_path": str(RELEVANCE_CONFIG.outputs.best_checkpoint_path) if RELEVANCE_CONFIG is not None else None,
        "single_model_cache_enabled": single_model_cache_enabled(),
        "unload_after_predict_enabled": unload_after_predict_enabled(),
        "relevance_severity_override_enabled": relevance_severity_override_enabled(),
        "relevance_severity_override_labels": sorted(relevance_severity_override_labels()),
        "severity_predictor_loaded": PREDICTOR is not None,
        "photo_relevance_predictor_loaded": RELEVANCE_PREDICTOR is not None,
        "message": "Upload a checkpoint before using /predict." if not CHECKPOINT_EXISTS else "Prediction service ready.",
    }


@app.get("/ready")
def ready() -> dict[str, object]:
    if not ai_enabled():
        raise HTTPException(status_code=503, detail={"status": "disabled", "operation_mode": operation_mode()})
    if CONFIG is None or not CHECKPOINT_EXISTS:
        raise HTTPException(
            status_code=503,
            detail={
                "status": "not_ready",
                "config_loaded": CONFIG is not None,
                "checkpoint_ready": CHECKPOINT_EXISTS,
                "message": "Severity model checkpoint is required before accepting AI prediction traffic.",
            },
        )

    return {
        "status": "ready",
        "config_loaded": True,
        "checkpoint_ready": True,
        "photo_relevance_config_loaded": RELEVANCE_CONFIG is not None,
        "photo_relevance_checkpoint_ready": RELEVANCE_CHECKPOINT_EXISTS,
        "message": "Prediction service ready.",
    }


@app.get("/warmup")
def warmup() -> dict[str, object]:
    if not ai_enabled():
        raise HTTPException(status_code=503, detail={"status": "disabled", "operation_mode": operation_mode()})
    return warm_predictors()


@app.post("/predict")
async def predict(file: UploadFile = File(...)) -> dict[str, object]:
    if not ai_enabled():
        raise HTTPException(status_code=503, detail={"status": "disabled", "operation_mode": operation_mode()})
    file_bytes = await file.read()
    if not file_bytes:
        raise HTTPException(status_code=400, detail="Uploaded file is empty.")

    with PREDICTOR_LOCK:
        relevance = predict_relevance(file_bytes)
        relevance_overridden = False

        if relevance is not None:
            if not relevance.accepted:
                relevance_overridden = False

                if relevance_severity_override_enabled():
                    prediction = predict_severity(file_bytes)
                    override_threshold = float_env("AI_RELEVANCE_SEVERITY_OVERRIDE_THRESHOLD", 0.70)
                    override_labels = relevance_severity_override_labels()

                    if (
                        prediction.severity.strip().lower() in override_labels
                        and prediction.confidence >= override_threshold
                        and not prediction.responder_review_required
                    ):
                        relevance_overridden = True

                if not relevance_overridden:
                    return {
                        "filename": file.filename,
                        "accepted": False,
                        "photo_relevance_label": relevance.label,
                        "photo_relevance_confidence": round(relevance.confidence, 6),
                        "photo_relevance_probabilities": relevance.probabilities,
                        "photo_relevance_overridden": False,
                        "rejection_message": relevance.rejection_message,
                        "responder_review_required": relevance.responder_review_required,
                        "responder_review_action": relevance.responder_review_action,
                        "severity": None,
                        "confidence": 0.0,
                        "probabilities": {},
                    }
            else:
                prediction = predict_severity(file_bytes)
        else:
            prediction = predict_severity(file_bytes)

    return {
        "filename": file.filename,
        "accepted": True,
        "photo_relevance_label": relevance.label if relevance is not None else "related",
        "photo_relevance_confidence": round(relevance.confidence, 6) if relevance is not None else None,
        "photo_relevance_probabilities": relevance.probabilities if relevance is not None else {},
        "photo_relevance_overridden": relevance_overridden,
        "rejection_message": None,
        "severity": prediction.severity,
        "confidence": round(prediction.confidence, 6),
        "probabilities": prediction.probabilities,
        "responder_review_required": (
            prediction.responder_review_required
            or (relevance.responder_review_required if relevance is not None else False)
            or relevance_overridden
        ),
        "responder_review_action": prediction.responder_review_action,
    }
