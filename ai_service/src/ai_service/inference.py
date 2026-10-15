"""Prediction helpers for the trained severity model."""

from __future__ import annotations

from dataclasses import dataclass
from io import BytesIO
import os
from pathlib import Path

import torch
from PIL import Image

from .config import AppConfig, load_config
from .dataset import build_transforms
from .model import build_model


@dataclass
class ClassificationPrediction:
    label: str
    confidence: float
    probabilities: dict[str, float]


@dataclass
class SeverityPrediction:
    severity: str
    confidence: float
    responder_review_required: bool
    responder_review_action: str
    probabilities: dict[str, float]


@dataclass
class PhotoRelevancePrediction:
    accepted: bool
    label: str
    confidence: float
    rejection_message: str | None
    responder_review_required: bool
    responder_review_action: str
    probabilities: dict[str, float]


def float_env(name: str, default: float) -> float:
    value = os.getenv(name)
    if value is None:
        return default

    try:
        return float(value)
    except ValueError:
        return default


class BaseImagePredictor:
    def __init__(self, config: AppConfig, checkpoint_path: Path | None = None) -> None:
        self.config = config
        self.device = torch.device("cuda" if torch.cuda.is_available() else "cpu")
        self.checkpoint_path = checkpoint_path or config.outputs.best_checkpoint_path
        checkpoint = torch.load(self.checkpoint_path, map_location=self.device, weights_only=False)

        self.labels = tuple(checkpoint["labels"])
        checkpoint_training_config = checkpoint.get("config", {}).get("training", {})
        self.architecture = checkpoint_training_config.get("architecture", config.training.architecture)
        self.model = build_model(
            num_classes=len(self.labels),
            pretrained=False,
            architecture=self.architecture,
        ).to(self.device)
        self.model.load_state_dict(checkpoint["model_state_dict"])
        self.model.eval()
        self.transform = build_transforms(image_size=config.dataset.image_size, training=False)

    def _classify_image(self, image: Image.Image) -> ClassificationPrediction:
        tensor = self.transform(image.convert("RGB")).unsqueeze(0).to(self.device)

        with torch.no_grad():
            logits = self.model(tensor)
            probabilities_tensor = torch.softmax(logits, dim=1).squeeze(0).cpu()

        probabilities = {
            label: float(probabilities_tensor[index].item())
            for index, label in enumerate(self.labels)
        }
        best_index = int(torch.argmax(probabilities_tensor).item())
        best_label = self.labels[best_index]

        return ClassificationPrediction(
            label=best_label,
            confidence=probabilities[best_label],
            probabilities=probabilities,
        )


class SeverityPredictor(BaseImagePredictor):
    @classmethod
    def from_config(cls, config_path: str | Path) -> "SeverityPredictor":
        return cls(load_config(config_path))

    def predict_image(self, image: Image.Image) -> SeverityPrediction:
        prediction = self._classify_image(image)
        review_required = prediction.confidence < self.config.inference.low_confidence_threshold

        return SeverityPrediction(
            severity=prediction.label,
            confidence=prediction.confidence,
            responder_review_required=review_required,
            responder_review_action=self.config.inference.responder_review_action,
            probabilities=prediction.probabilities,
        )

    def predict_path(self, image_path: str | Path) -> SeverityPrediction:
        image = Image.open(image_path).convert("RGB")
        return self.predict_image(image)

    def predict_bytes(self, image_bytes: bytes) -> SeverityPrediction:
        image = Image.open(BytesIO(image_bytes)).convert("RGB")
        return self.predict_image(image)


class PhotoRelevancePredictor(BaseImagePredictor):
    @classmethod
    def from_config(cls, config_path: str | Path) -> "PhotoRelevancePredictor":
        return cls(load_config(config_path))

    def predict_image(self, image: Image.Image) -> PhotoRelevancePrediction:
        prediction = self._classify_image(image)
        reject_label = (self.config.inference.reject_label or "unrelated").strip().lower()
        reject_threshold = float_env(
            "AI_PHOTO_RELEVANCE_REJECT_THRESHOLD",
            self.config.inference.reject_threshold or 0.72,
        )
        low_confidence_threshold = float_env(
            "AI_PHOTO_RELEVANCE_LOW_CONFIDENCE_THRESHOLD",
            self.config.inference.low_confidence_threshold,
        )
        is_rejected = prediction.label.lower() == reject_label and prediction.confidence >= reject_threshold
        review_required = (
            prediction.confidence < low_confidence_threshold
            or (prediction.label.lower() == reject_label and not is_rejected)
        )
        rejection_message = (
            "Upload a real accident or emergency scene photo. Dummy, UI, selfie-only, or unrelated images are not accepted as evidence."
            if is_rejected
            else None
        )

        return PhotoRelevancePrediction(
            accepted=not is_rejected,
            label=prediction.label,
            confidence=prediction.confidence,
            rejection_message=rejection_message,
            responder_review_required=review_required,
            responder_review_action=self.config.inference.responder_review_action,
            probabilities=prediction.probabilities,
        )

    def predict_path(self, image_path: str | Path) -> PhotoRelevancePrediction:
        image = Image.open(image_path).convert("RGB")
        return self.predict_image(image)

    def predict_bytes(self, image_bytes: bytes) -> PhotoRelevancePrediction:
        image = Image.open(BytesIO(image_bytes)).convert("RGB")
        return self.predict_image(image)
