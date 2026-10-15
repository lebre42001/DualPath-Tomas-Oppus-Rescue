from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path


PROJECT_ROOT = Path(__file__).resolve().parent.parent
if str(PROJECT_ROOT) not in sys.path:
    sys.path.insert(0, str(PROJECT_ROOT))

from src.ai_service.config import load_config
from src.ai_service.defaults import resolve_active_config_path
from src.ai_service.inference import SeverityPredictor


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Run a manual accident severity prediction test on one unseen image."
    )
    parser.add_argument(
        "--image",
        required=True,
        help="Path to the image file to test.",
    )
    parser.add_argument(
        "--config",
        default=resolve_active_config_path(),
        help="Path to the YAML config file. Defaults to the active production-candidate config.",
    )
    return parser.parse_args()


def main() -> None:
    args = parse_args()
    config = load_config(args.config)
    predictor = SeverityPredictor(config)
    prediction = predictor.predict_path(args.image)

    print(
        json.dumps(
            {
                "image": str(Path(args.image).resolve()),
                "config_path": str(config.config_path),
                "experiment_name": config.experiment.name,
                "checkpoint_path": str(config.outputs.best_checkpoint_path),
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
