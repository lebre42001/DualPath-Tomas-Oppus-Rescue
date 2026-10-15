"""Text routes are independent of image checkpoints and optional ML dependencies."""

from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, ConfigDict, Field, field_validator

from .text_inference import TextModelUnavailable, get_predictor, predict_text, text_status
from .operation_mode import ai_enabled, operation_mode

router = APIRouter(tags=["text severity"])


class TextPredictionRequest(BaseModel):
    model_config = ConfigDict(extra="forbid", strict=True)
    description: str = Field(min_length=1, max_length=1000)

    @field_validator("description")
    @classmethod
    def non_blank(cls, value: str) -> str:
        if not value.strip():
            raise ValueError("Description must not be blank.")
        return value.strip()


@router.get("/health/text")
def health_text() -> dict:
    return text_status()


@router.get("/ready/text")
def ready_text() -> dict:
    if not ai_enabled():
        raise HTTPException(status_code=503, detail={"status": "disabled", "operation_mode": operation_mode()})
    try:
        predictor = get_predictor()
        return {"status": "ready", "deployment_status": predictor.metadata["deployment_status"],
                "model_version": predictor.metadata["version"]}
    except TextModelUnavailable as exc:
        raise HTTPException(status_code=503, detail=str(exc)) from exc


@router.post("/predict/text")
def classify_text(request: TextPredictionRequest) -> dict:
    if not ai_enabled():
        raise HTTPException(status_code=503, detail={"status": "disabled", "operation_mode": operation_mode()})
    try:
        return predict_text(request.description)
    except TextModelUnavailable as exc:
        raise HTTPException(status_code=503, detail=str(exc)) from exc
    except Exception as exc:
        # Do not return model internals or the user's description in an error.
        raise HTTPException(status_code=503, detail="Text prediction unavailable; responder review is required.") from exc
