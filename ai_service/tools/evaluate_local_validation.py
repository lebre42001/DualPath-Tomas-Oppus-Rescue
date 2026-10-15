from __future__ import annotations

import argparse
import csv
import json
import sys
from collections import defaultdict
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path


PROJECT_ROOT = Path(__file__).resolve().parent.parent
if str(PROJECT_ROOT) not in sys.path:
    sys.path.insert(0, str(PROJECT_ROOT))

from src.ai_service.config import load_config
from src.ai_service.defaults import resolve_active_config_path
from src.ai_service.inference import SeverityPredictor


LABELS = ("minor", "serious", "fatal")


@dataclass
class ValidationRow:
    image_relative_path: str
    image_path: Path
    true_label: str
    source_domain: str
    municipality: str
    province: str
    barangay: str
    incident_type: str
    road_type: str
    weather: str
    lighting_condition: str
    vehicle_types: str
    motorcycle_present: str
    multi_vehicle: str
    rural_scene: str
    reviewer_name: str
    review_notes: str


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Evaluate the active severity model against the Bontoc local validation-only set."
    )
    parser.add_argument(
        "--config",
        default=resolve_active_config_path(),
        help="Path to the YAML config file. Defaults to the active production-candidate config.",
    )
    parser.add_argument(
        "--manifest",
        default="datasets/bontoc_southern_leyte/local_validation/manifests/local_validation_manifest_template.csv",
        help="Path to the local validation manifest CSV.",
    )
    parser.add_argument(
        "--output-prefix",
        default="",
        help="Optional custom prefix for generated report files.",
    )
    return parser.parse_args()


def parse_bool_flag(value: str) -> bool:
    return value.strip().lower() in {"yes", "true", "1", "y"}


def resolve_image_path(dataset_root: Path, image_relative_path: str) -> Path:
    candidates = [
        (dataset_root / image_relative_path).resolve(),
        (dataset_root / "local_validation" / image_relative_path).resolve(),
        (PROJECT_ROOT / image_relative_path).resolve(),
    ]

    for candidate in candidates:
        if candidate.exists():
            return candidate

    return candidates[0]


def load_validation_rows(manifest_path: Path, dataset_root: Path) -> list[ValidationRow]:
    rows: list[ValidationRow] = []

    with manifest_path.open("r", encoding="utf-8", newline="") as handle:
        reader = csv.DictReader(handle)
        for raw in reader:
            true_label = (raw.get("severity_label") or "").strip().lower()
            image_relative_path = (raw.get("image_relative_path") or "").strip()

            if not image_relative_path or true_label not in LABELS:
                continue

            rows.append(
                ValidationRow(
                    image_relative_path=image_relative_path,
                    image_path=resolve_image_path(dataset_root, image_relative_path),
                    true_label=true_label,
                    source_domain=(raw.get("source_domain") or "").strip(),
                    municipality=(raw.get("municipality") or "").strip(),
                    province=(raw.get("province") or "").strip(),
                    barangay=(raw.get("barangay") or "").strip(),
                    incident_type=(raw.get("incident_type") or "").strip(),
                    road_type=(raw.get("road_type") or "").strip(),
                    weather=(raw.get("weather") or "").strip(),
                    lighting_condition=(raw.get("lighting_condition") or "").strip(),
                    vehicle_types=(raw.get("vehicle_types") or "").strip(),
                    motorcycle_present=(raw.get("motorcycle_present") or "").strip(),
                    multi_vehicle=(raw.get("multi_vehicle") or "").strip(),
                    rural_scene=(raw.get("rural_scene") or "").strip(),
                    reviewer_name=(raw.get("reviewer_name") or "").strip(),
                    review_notes=(raw.get("review_notes") or "").strip(),
                )
            )

    return rows


def scenario_tags(row: ValidationRow) -> list[str]:
    tags: list[str] = []

    if parse_bool_flag(row.motorcycle_present):
        tags.append("motorcycle")
    if parse_bool_flag(row.multi_vehicle):
        tags.append("multi_vehicle")
    if parse_bool_flag(row.rural_scene):
        tags.append("rural_scene")

    weather = row.weather.lower()
    if weather in {"rain", "rainy", "wet", "wet_road", "wet-road"}:
        tags.append("rain_or_wet_road")

    lighting = row.lighting_condition.lower()
    if lighting in {"night", "low_light", "low-light", "dark"}:
        tags.append("night_or_low_light")

    road = row.road_type.lower()
    if road in {"barangay_road", "provincial_road", "mountain_road", "rural_road"}:
        tags.append("rural_or_barangay_road")

    return tags


def safe_divide(numerator: int, denominator: int) -> float:
    if denominator == 0:
        return 0.0
    return numerator / denominator


def write_csv(rows: list[dict[str, object]], output_path: Path) -> None:
    output_path.parent.mkdir(parents=True, exist_ok=True)
    with output_path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(
            handle,
            fieldnames=[
                "image_relative_path",
                "true_label",
                "predicted_label",
                "correct",
                "confidence",
                "review_required",
                "minor_probability",
                "serious_probability",
                "fatal_probability",
                "source_domain",
                "municipality",
                "province",
                "barangay",
                "incident_type",
                "road_type",
                "weather",
                "lighting_condition",
                "vehicle_types",
                "motorcycle_present",
                "multi_vehicle",
                "rural_scene",
                "scenario_tags",
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
    overall_accuracy: float,
    low_confidence_count: int,
    per_class_stats: dict[str, dict[str, float | int]],
    scenario_stats: dict[str, dict[str, float | int]],
    confusion: dict[str, dict[str, int]],
) -> None:
    output_path.parent.mkdir(parents=True, exist_ok=True)

    lines = [
        "# Local Validation Evaluation",
        "",
        f"Date: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}",
        f"Config: `{config_path}`",
        f"Experiment: `{experiment_name}`",
        f"Checkpoint: `{checkpoint_path}`",
        f"Manifest: `{manifest_path}`",
        "",
        "## Overall",
        "",
        f"- Total evaluated images: `{total}`",
        f"- Missing image paths: `{missing_images}`",
        f"- Overall accuracy: `{overall_accuracy:.4f}`",
        f"- Review-required predictions: `{low_confidence_count}`",
        "",
        "## Per-Class Accuracy",
        "",
    ]

    for label in LABELS:
        stats = per_class_stats[label]
        lines.extend(
            [
                f"- `{label}`",
                f"  - total: `{stats['total']}`",
                f"  - correct: `{stats['correct']}`",
                f"  - accuracy: `{stats['accuracy']:.4f}`",
            ]
        )

    lines.extend(["", "## Scenario Slices", ""])

    for name, stats in scenario_stats.items():
        lines.extend(
            [
                f"- `{name}`",
                f"  - total: `{stats['total']}`",
                f"  - correct: `{stats['correct']}`",
                f"  - accuracy: `{stats['accuracy']:.4f}`",
            ]
        )

    lines.extend(["", "## Confusion Matrix", ""])

    header = "| true \\ predicted | minor | serious | fatal |"
    divider = "| --- | ---: | ---: | ---: |"
    lines.extend([header, divider])

    for true_label in LABELS:
        row = confusion[true_label]
        lines.append(
            f"| {true_label} | {row['minor']} | {row['serious']} | {row['fatal']} |"
        )

    lines.extend(
        [
            "",
            "## Interpretation Notes",
            "",
            "- use this report to find where the current external-only model is weak on local conditions",
            "- focus on motorcycle, night/rain, rural, and multi-vehicle slices first",
            "- do not move these images into training until the validation-only review is complete",
        ]
    )

    output_path.write_text("\n".join(lines) + "\n", encoding="utf-8")


def main() -> None:
    args = parse_args()
    config = load_config(args.config)
    predictor = SeverityPredictor(config)
    manifest_path = Path(args.manifest).resolve()
    dataset_root = config.dataset.root
    rows = load_validation_rows(manifest_path, dataset_root)

    timestamp = datetime.now().strftime("%Y_%m_%d_%H%M%S")
    prefix = args.output_prefix.strip() or f"local_validation_eval_{timestamp}"
    reports_dir = (dataset_root / "local_validation" / "reports").resolve()
    csv_output = reports_dir / f"{prefix}.csv"
    md_output = reports_dir / f"{prefix}.md"

    predictions: list[dict[str, object]] = []
    confusion: dict[str, dict[str, int]] = {
        true_label: {predicted: 0 for predicted in LABELS} for true_label in LABELS
    }
    per_class_correct = {label: 0 for label in LABELS}
    per_class_total = {label: 0 for label in LABELS}
    scenario_totals: dict[str, int] = defaultdict(int)
    scenario_correct: dict[str, int] = defaultdict(int)
    total_correct = 0
    missing_images = 0
    low_confidence_count = 0

    for row in rows:
        if not row.image_path.exists():
            missing_images += 1
            continue

        prediction = predictor.predict_path(row.image_path)
        predicted_label = prediction.severity.lower()
        is_correct = predicted_label == row.true_label

        per_class_total[row.true_label] += 1
        confusion[row.true_label][predicted_label] += 1

        if is_correct:
            total_correct += 1
            per_class_correct[row.true_label] += 1

        if prediction.responder_review_required:
            low_confidence_count += 1

        tags = scenario_tags(row)
        for tag in tags:
            scenario_totals[tag] += 1
            if is_correct:
                scenario_correct[tag] += 1

        predictions.append(
            {
                "image_relative_path": row.image_relative_path,
                "true_label": row.true_label,
                "predicted_label": predicted_label,
                "correct": is_correct,
                "confidence": round(prediction.confidence, 6),
                "review_required": prediction.responder_review_required,
                "minor_probability": round(prediction.probabilities.get("minor", 0.0), 6),
                "serious_probability": round(prediction.probabilities.get("serious", 0.0), 6),
                "fatal_probability": round(prediction.probabilities.get("fatal", 0.0), 6),
                "source_domain": row.source_domain,
                "municipality": row.municipality,
                "province": row.province,
                "barangay": row.barangay,
                "incident_type": row.incident_type,
                "road_type": row.road_type,
                "weather": row.weather,
                "lighting_condition": row.lighting_condition,
                "vehicle_types": row.vehicle_types,
                "motorcycle_present": row.motorcycle_present,
                "multi_vehicle": row.multi_vehicle,
                "rural_scene": row.rural_scene,
                "scenario_tags": "|".join(tags),
            }
        )

    total = len(predictions)
    overall_accuracy = safe_divide(total_correct, total)
    per_class_stats = {
        label: {
            "total": per_class_total[label],
            "correct": per_class_correct[label],
            "accuracy": safe_divide(per_class_correct[label], per_class_total[label]),
        }
        for label in LABELS
    }
    scenario_stats = {
        name: {
            "total": scenario_totals[name],
            "correct": scenario_correct[name],
            "accuracy": safe_divide(scenario_correct[name], scenario_totals[name]),
        }
        for name in sorted(scenario_totals)
    }

    write_csv(predictions, csv_output)
    write_markdown(
        md_output,
        config_path=config.config_path,
        experiment_name=config.experiment.name,
        checkpoint_path=config.outputs.best_checkpoint_path,
        manifest_path=manifest_path,
        total=total,
        missing_images=missing_images,
        overall_accuracy=overall_accuracy,
        low_confidence_count=low_confidence_count,
        per_class_stats=per_class_stats,
        scenario_stats=scenario_stats,
        confusion=confusion,
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
                "overall_accuracy": overall_accuracy,
                "review_required_predictions": low_confidence_count,
                "per_class_stats": per_class_stats,
                "scenario_stats": scenario_stats,
                "csv_output": str(csv_output),
                "markdown_output": str(md_output),
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
