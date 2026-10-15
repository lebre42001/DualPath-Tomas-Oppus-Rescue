"""CLI entry point for single-image inference."""

from __future__ import annotations

import argparse
import json

from src.ai_service.defaults import resolve_active_config_path
from src.ai_service.inference import SeverityPredictor



def main() -> None:
    parser = argparse.ArgumentParser(description="Run accident severity inference on one image.")
    parser.add_argument(
        "--config",
        default=resolve_active_config_path(),
        help="Path to the YAML configuration file.",
    )
    parser.add_argument("--image", required=True, help="Path to the image file.")
    args = parser.parse_args()

    predictor = SeverityPredictor.from_config(args.config)
    prediction = predictor.predict_path(args.image)
    print(
        json.dumps(
            {
                "severity": prediction.severity,
                "confidence": prediction.confidence,
                "probabilities": prediction.probabilities,
                "responder_review_required": prediction.responder_review_required,
                "responder_review_action": prediction.responder_review_action,
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
