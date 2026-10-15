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
from src.ai_service.defaults import resolve_active_config_path, resolve_photo_relevance_config_path
from src.ai_service.inference import PhotoRelevancePredictor, SeverityPredictor


IMAGE_EXTENSIONS = {".jpg", ".jpeg", ".png", ".webp", ".bmp"}


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Run the same two-stage AI flow used by the API: photo relevance first, severity second."
    )
    parser.add_argument("--input", required=True, help="Image file or folder to scan.")
    parser.add_argument("--severity-config", default=resolve_active_config_path())
    parser.add_argument("--relevance-config", default=resolve_photo_relevance_config_path())
    parser.add_argument("--pattern", default="*", help="Glob pattern when --input is a folder.")
    parser.add_argument("--limit", type=int, default=0, help="Max images to scan. Use 0 for no limit.")
    parser.add_argument("--output-csv", default="", help="Optional CSV report output path.")
    parser.add_argument(
        "--enable-severity-override",
        action="store_true",
        help="Allow a confident severity model to override a rejected relevance gate result.",
    )
    parser.add_argument(
        "--severity-override-threshold",
        type=float,
        default=0.70,
        help="If relevance rejects but severity is confidently emergency, mark as overridden.",
    )
    return parser.parse_args()


def resolve_images(input_path: Path, pattern: str, limit: int) -> list[Path]:
    if input_path.is_file():
        return [input_path]

    if not input_path.exists():
        raise FileNotFoundError(f"Input path was not found: {input_path}")

    images = [
        path
        for path in sorted(input_path.rglob(pattern))
        if path.is_file() and path.suffix.lower() in IMAGE_EXTENSIONS
    ]

    return images[:limit] if limit > 0 else images


def write_csv(rows: list[dict[str, object]], output_path: Path) -> None:
    output_path.parent.mkdir(parents=True, exist_ok=True)
    with output_path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(
            handle,
            fieldnames=[
                "image",
                "accepted",
                "relevance_label",
                "relevance_confidence",
                "relevance_overridden",
                "severity",
                "severity_confidence",
                "responder_review_required",
                "action",
            ],
        )
        writer.writeheader()
        writer.writerows(rows)


def main() -> None:
    args = parse_args()
    severity_config = load_config(args.severity_config)
    relevance_config = load_config(args.relevance_config)
    severity_predictor = SeverityPredictor(severity_config)
    relevance_predictor = PhotoRelevancePredictor(relevance_config)
    images = resolve_images(Path(args.input).resolve(), args.pattern, args.limit)

    rows: list[dict[str, object]] = []
    accepted_count = 0
    rejected_count = 0
    overridden_count = 0

    for image_path in images:
        relevance = relevance_predictor.predict_path(image_path)
        severity = None
        accepted = relevance.accepted
        overridden = False

        if accepted:
            severity = severity_predictor.predict_path(image_path)
        elif args.enable_severity_override:
            candidate = severity_predictor.predict_path(image_path)
            if (
                candidate.confidence >= args.severity_override_threshold
                and not candidate.responder_review_required
            ):
                severity = candidate
                accepted = True
                overridden = True

        if accepted:
            accepted_count += 1
        else:
            rejected_count += 1
        if overridden:
            overridden_count += 1

        rows.append(
            {
                "image": str(image_path),
                "accepted": accepted,
                "relevance_label": relevance.label,
                "relevance_confidence": round(relevance.confidence, 6),
                "relevance_overridden": overridden,
                "severity": severity.severity if severity is not None else None,
                "severity_confidence": round(severity.confidence, 6) if severity is not None else 0.0,
                "responder_review_required": (
                    severity.responder_review_required if severity is not None else relevance.responder_review_required
                ),
                "action": (
                    severity.responder_review_action if severity is not None else relevance.responder_review_action
                ),
            }
        )

    if args.output_csv:
        write_csv(rows, Path(args.output_csv).resolve())

    print(
        json.dumps(
            {
                "severity_config": str(severity_config.config_path),
                "relevance_config": str(relevance_config.config_path),
                "severity_override_enabled": args.enable_severity_override,
                "images_scanned": len(rows),
                "accepted": accepted_count,
                "rejected": rejected_count,
                "relevance_overridden": overridden_count,
                "output_csv": str(Path(args.output_csv).resolve()) if args.output_csv else None,
                "results": rows,
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
