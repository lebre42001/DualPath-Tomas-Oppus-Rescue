from __future__ import annotations

import argparse
import csv
import json
import sys
from collections import defaultdict
from datetime import datetime
from pathlib import Path


PROJECT_ROOT = Path(__file__).resolve().parent.parent
if str(PROJECT_ROOT) not in sys.path:
    sys.path.insert(0, str(PROJECT_ROOT))

from src.ai_service.config import load_config
from src.ai_service.dataset import read_manifest
from src.ai_service.defaults import resolve_photo_relevance_config_path
from src.ai_service.inference import PhotoRelevancePredictor


LABELS = ("related", "unrelated")


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Evaluate the accident-photo relevance gate against a labeled manifest."
    )
    parser.add_argument(
        "--config",
        default=resolve_photo_relevance_config_path(),
        help="Photo relevance YAML config. Defaults to the active photo relevance config.",
    )
    parser.add_argument(
        "--manifest",
        default="",
        help="Optional manifest CSV. Defaults to the config test manifest.",
    )
    parser.add_argument(
        "--output-prefix",
        default="",
        help="Optional report filename prefix.",
    )
    return parser.parse_args()


def safe_divide(numerator: int, denominator: int) -> float:
    return numerator / denominator if denominator else 0.0


def write_csv(rows: list[dict[str, object]], output_path: Path) -> None:
    output_path.parent.mkdir(parents=True, exist_ok=True)
    with output_path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(
            handle,
            fieldnames=[
                "image_relative_path",
                "true_label",
                "predicted_label",
                "accepted",
                "correct_label",
                "confidence",
                "related_probability",
                "unrelated_probability",
                "responder_review_required",
                "source_domain",
                "review_status",
            ],
        )
        writer.writeheader()
        writer.writerows(rows)


def write_markdown(
    output_path: Path,
    *,
    config_path: Path,
    experiment_name: str,
    checkpoint_path: Path,
    manifest_path: Path,
    total: int,
    missing_images: int,
    label_accuracy: float,
    false_accepts: int,
    false_rejects: int,
    review_required: int,
    confusion: dict[str, dict[str, int]],
    per_source: dict[str, dict[str, int]],
) -> None:
    output_path.parent.mkdir(parents=True, exist_ok=True)

    lines = [
        "# Photo Relevance Gate Evaluation",
        "",
        f"Date: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}",
        f"Config: `{config_path}`",
        f"Experiment: `{experiment_name}`",
        f"Checkpoint: `{checkpoint_path}`",
        f"Manifest: `{manifest_path}`",
        "",
        "## Operational Meaning",
        "",
        "- `false_accepts`: dummy or unrelated photos that still pass the gate",
        "- `false_rejects`: real accident/emergency photos that get blocked",
        "- both should be reviewed before promoting a checkpoint",
        "",
        "## Summary",
        "",
        f"- Total evaluated images: `{total}`",
        f"- Missing image paths: `{missing_images}`",
        f"- Label accuracy: `{label_accuracy:.4f}`",
        f"- False accepts: `{false_accepts}`",
        f"- False rejects: `{false_rejects}`",
        f"- Review-required predictions: `{review_required}`",
        "",
        "## Confusion Matrix",
        "",
        "| true \\ predicted | related | unrelated |",
        "| --- | ---: | ---: |",
    ]

    for true_label in LABELS:
        row = confusion[true_label]
        lines.append(f"| {true_label} | {row['related']} | {row['unrelated']} |")

    lines.extend(["", "## Source Domain Slices", ""])

    for source_domain, stats in sorted(per_source.items()):
        lines.extend(
            [
                f"- `{source_domain}`",
                f"  - total: `{stats['total']}`",
                f"  - correct: `{stats['correct']}`",
                f"  - accuracy: `{safe_divide(stats['correct'], stats['total']):.4f}`",
            ]
        )

    lines.extend(
        [
            "",
            "## Recommendation",
            "",
            "- add more unrelated negatives if false accepts are present",
            "- add more real accident scene examples if false rejects are present",
            "- keep this gate stricter than the severity model because dummy photos should not reach responder dispatch as evidence",
        ]
    )

    output_path.write_text("\n".join(lines) + "\n", encoding="utf-8")


def main() -> None:
    args = parse_args()
    config = load_config(args.config)
    manifest_path = Path(args.manifest).resolve() if args.manifest else config.dataset.test_manifest
    predictor = PhotoRelevancePredictor(config)
    records = read_manifest(manifest_path)
    timestamp = datetime.now().strftime("%Y_%m_%d_%H%M%S")
    prefix = args.output_prefix.strip() or f"photo_relevance_gate_eval_{timestamp}"
    csv_output = config.outputs.reports_dir / f"{prefix}.csv"
    md_output = config.outputs.reports_dir / f"{prefix}.md"

    rows: list[dict[str, object]] = []
    confusion = {true_label: {predicted: 0 for predicted in LABELS} for true_label in LABELS}
    per_source: dict[str, dict[str, int]] = defaultdict(lambda: {"total": 0, "correct": 0})
    correct = 0
    missing_images = 0
    false_accepts = 0
    false_rejects = 0
    review_required = 0

    for record in records:
        true_label = record.severity_label
        if true_label not in LABELS:
            continue

        image_path = (config.dataset.root / record.image_relative_path).resolve()
        if not image_path.exists():
            missing_images += 1
            continue

        prediction = predictor.predict_path(image_path)
        predicted_label = prediction.label.lower()
        accepted = prediction.accepted
        correct_label = predicted_label == true_label

        confusion[true_label][predicted_label] += 1
        per_source[record.source_domain]["total"] += 1
        if correct_label:
            correct += 1
            per_source[record.source_domain]["correct"] += 1

        if true_label == "unrelated" and accepted:
            false_accepts += 1
        if true_label == "related" and not accepted:
            false_rejects += 1
        if prediction.responder_review_required:
            review_required += 1

        rows.append(
            {
                "image_relative_path": record.image_relative_path,
                "true_label": true_label,
                "predicted_label": predicted_label,
                "accepted": accepted,
                "correct_label": correct_label,
                "confidence": round(prediction.confidence, 6),
                "related_probability": round(prediction.probabilities.get("related", 0.0), 6),
                "unrelated_probability": round(prediction.probabilities.get("unrelated", 0.0), 6),
                "responder_review_required": prediction.responder_review_required,
                "source_domain": record.source_domain,
                "review_status": record.review_status,
            }
        )

    total = len(rows)
    label_accuracy = safe_divide(correct, total)

    write_csv(rows, csv_output)
    write_markdown(
        md_output,
        config_path=config.config_path,
        experiment_name=config.experiment.name,
        checkpoint_path=config.outputs.best_checkpoint_path,
        manifest_path=manifest_path,
        total=total,
        missing_images=missing_images,
        label_accuracy=label_accuracy,
        false_accepts=false_accepts,
        false_rejects=false_rejects,
        review_required=review_required,
        confusion=confusion,
        per_source=per_source,
    )

    print(
        json.dumps(
            {
                "config_path": str(config.config_path),
                "experiment_name": config.experiment.name,
                "checkpoint_path": str(config.outputs.best_checkpoint_path),
                "manifest_path": str(manifest_path),
                "total_evaluated": total,
                "missing_images": missing_images,
                "label_accuracy": label_accuracy,
                "false_accepts": false_accepts,
                "false_rejects": false_rejects,
                "review_required_predictions": review_required,
                "csv_output": str(csv_output),
                "markdown_output": str(md_output),
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
