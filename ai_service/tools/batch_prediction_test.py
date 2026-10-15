from __future__ import annotations

import argparse
import csv
import json
import sys
from pathlib import Path


PROJECT_ROOT = Path(__file__).resolve().parent.parent
if str(PROJECT_ROOT) not in sys.path:
    sys.path.insert(0, str(PROJECT_ROOT))

from src.ai_service.config import load_config
from src.ai_service.defaults import resolve_active_config_path
from src.ai_service.inference import SeverityPredictor


IMAGE_EXTENSIONS = {".jpg", ".jpeg", ".png", ".webp", ".bmp"}


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Run batch severity predictions on one image folder or file set."
    )
    parser.add_argument(
        "--input",
        required=True,
        help="Path to an image file or a folder containing images.",
    )
    parser.add_argument(
        "--config",
        default=resolve_active_config_path(),
        help="Path to the YAML config file. Defaults to the active production-candidate config.",
    )
    parser.add_argument(
        "--pattern",
        default="*",
        help="Glob pattern used when --input is a folder.",
    )
    parser.add_argument(
        "--limit",
        type=int,
        default=0,
        help="Optional max number of images to evaluate. Use 0 for no limit.",
    )
    parser.add_argument(
        "--output-csv",
        default="",
        help="Optional path to write a CSV report.",
    )
    return parser.parse_args()


def resolve_images(input_path: Path, pattern: str, limit: int) -> list[Path]:
    if input_path.is_file():
        return [input_path]

    if not input_path.exists():
        raise FileNotFoundError(f"Input path was not found: {input_path}")

    candidates = [
        path
        for path in sorted(input_path.rglob(pattern))
        if path.is_file() and path.suffix.lower() in IMAGE_EXTENSIONS
    ]

    if limit > 0:
        return candidates[:limit]

    return candidates


def write_csv(rows: list[dict[str, object]], output_path: Path) -> None:
    output_path.parent.mkdir(parents=True, exist_ok=True)
    with output_path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(
            handle,
            fieldnames=[
                "image",
                "predicted_severity",
                "confidence",
                "minor_probability",
                "serious_probability",
                "fatal_probability",
                "responder_review_required",
                "responder_review_action",
            ],
        )
        writer.writeheader()
        writer.writerows(rows)


def main() -> None:
    args = parse_args()
    config = load_config(args.config)
    predictor = SeverityPredictor(config)
    input_path = Path(args.input).resolve()
    image_paths = resolve_images(input_path, args.pattern, args.limit)

    rows: list[dict[str, object]] = []
    counts: dict[str, int] = {}

    for image_path in image_paths:
        prediction = predictor.predict_path(image_path)
        counts[prediction.severity] = counts.get(prediction.severity, 0) + 1
        rows.append(
            {
                "image": str(image_path),
                "predicted_severity": prediction.severity,
                "confidence": round(prediction.confidence, 6),
                "minor_probability": round(prediction.probabilities.get("minor", 0.0), 6),
                "serious_probability": round(prediction.probabilities.get("serious", 0.0), 6),
                "fatal_probability": round(prediction.probabilities.get("fatal", 0.0), 6),
                "responder_review_required": prediction.responder_review_required,
                "responder_review_action": prediction.responder_review_action,
            }
        )

    if args.output_csv:
        write_csv(rows, Path(args.output_csv).resolve())

    print(
        json.dumps(
            {
                "config_path": str(config.config_path),
                "experiment_name": config.experiment.name,
                "checkpoint_path": str(config.outputs.best_checkpoint_path),
                "input_path": str(input_path),
                "images_scanned": len(rows),
                "prediction_counts": counts,
                "csv_output": str(Path(args.output_csv).resolve()) if args.output_csv else None,
                "results": rows,
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
