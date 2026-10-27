"""Description-only severity inference and an independent live-face check."""

from __future__ import annotations

from fastapi import FastAPI, HTTPException

from .operation_mode import ai_enabled, operation_mode
from .text_api import router as text_router
from .liveness_api import router as liveness_router
from .text_inference import TextModelUnavailable, get_predictor, text_status

app = FastAPI(title="Stitch Text Severity Service", version="0.4.0")
app.include_router(text_router)
app.include_router(liveness_router)


@app.get("/health")
def health() -> dict[str, object]:
    return {
        "status": "ok",
        "operation_mode": operation_mode(),
        "inference_enabled": ai_enabled(),
        "text_ai": text_status(),
    }


@app.get("/ready")
def ready() -> dict[str, object]:
    if not ai_enabled():
        raise HTTPException(status_code=503, detail={"status": "disabled", "operation_mode": operation_mode()})
    try:
        predictor = get_predictor()
    except TextModelUnavailable as exc:
        raise HTTPException(status_code=503, detail=str(exc)) from exc
    return {
        "status": "ready",
        "deployment_status": predictor.metadata["deployment_status"],
        "model_version": predictor.metadata["version"],
    }


@app.get("/warmup")
def warmup() -> dict[str, object]:
    return ready()
