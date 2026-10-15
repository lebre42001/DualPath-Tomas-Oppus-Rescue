from __future__ import annotations

import argparse
import csv
import hashlib
import sys
from collections import Counter, defaultdict
from pathlib import Path

PROJECT_ROOT = Path(__file__).resolve().parent.parent
if str(PROJECT_ROOT) not in sys.path:
    sys.path.insert(0, str(PROJECT_ROOT))

from src.ai_service.config import load_config
from src.ai_service.dataset import read_manifest
from src.ai_service.labels import SEVERITY_LABELS


DATASET_ROOT = PROJECT_ROOT / "datasets" / "bontoc_southern_leyte"
ARTIFACTS_REPORTS = PROJECT_ROOT / "artifacts" / "reports"


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Prepare a non-destructive retraining plan from staged import images.")
    parser.add_argument(
        "--config",
        default="configs/bontoc_southern_leyte_severity.yaml",
        help="Path to the training config.",
    )
    parser.add_argument(
        "--staging-subdir",
        default="import_staging/accident_images_analysis_dataset",
        help="Relative staging folder under the dataset root.",
    )
    parser.add_argument(
        "--source-domain",
        default="road_accident",
        help="Source domain label to use for the staging import.",
    )
    return parser.parse_args()


def suggested_split(relative_path: str) -> str:
    hashed = int(hashlib.sha1(relative_path.encode("utf-8")).hexdigest(), 16) % 100
    if hashed < 70:
        return "train"
    if hashed < 85:
        return "val"
    return "test"


def main() -> None:
    args = parse_args()
    config = load_config(args.config)
    dataset_root = config.dataset.root
    staging_root = (dataset_root / args.staging_subdir).resolve()
    if not staging_root.exists():
        raise FileNotFoundError(f"Staging folder was not found: {staging_root}")

    existing_records = {
        "train": read_manifest(config.dataset.train_manifest),
        "val": read_manifest(config.dataset.val_manifest),
        "test": read_manifest(config.dataset.test_manifest),
    }

    existing_counts = {
        split: Counter(record.severity_label for record in records)
        for split, records in existing_records.items()
    }

    staged_rows: list[list[str]] = []
    staged_counts: defaultdict[str, Counter[str]] = defaultdict(Counter)

    for severity_label in SEVERITY_LABELS:
        class_dir = staging_root / severity_label
        if not class_dir.exists():
            continue

        for image_path in sorted(class_dir.glob("*.jpg")):
            relative_path = image_path.relative_to(dataset_root).as_posix()
            split = suggested_split(relative_path)
            staged_counts[split][severity_label] += 1
            description = f"Staged external import candidate for {severity_label} review."

            staged_rows.append(
                [
                    relative_path,
                    severity_label,
                    args.source_domain,
                    "",
                    "",
                    "",
                    "",
                    "",
                    "vehicular_accident",
                    description,
                    "unknown",
                    "needs_review",
                    split,
                    "external_import",
                ]
            )

    manifest_path = dataset_root / "metadata" / "retraining_candidate_split_plan_accident_images_analysis_dataset.csv"
    with manifest_path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.writer(handle)
        writer.writerow(
            [
                "image_relative_path",
                "severity_label",
                "source_domain",
                "municipality",
                "province",
                "barangay",
                "latitude",
                "longitude",
                "incident_type",
                "description",
                "weather",
                "review_status",
                "suggested_split",
                "merge_stage",
            ]
        )
        writer.writerows(staged_rows)

    summary_path = ARTIFACTS_REPORTS / "retraining_prep_accident_images_analysis_dataset.md"
    summary_lines = [
        "# Retraining Prep Summary",
        "",
        f"- config: `{config.config_path.as_posix()}`",
        f"- staging root: `{staging_root.as_posix()}`",
        f"- candidate split plan: `{manifest_path.as_posix()}`",
        "",
        "## Existing curated counts",
        "",
    ]

    for split in ("train", "val", "test"):
        summary_lines.append(f"### {split}")
        for label in SEVERITY_LABELS:
            summary_lines.append(f"- {label}: `{existing_counts[split][label]}`")
        summary_lines.append("")

    summary_lines.extend(["## Suggested staged counts", ""])
    for split in ("train", "val", "test"):
        summary_lines.append(f"### {split}")
        for label in SEVERITY_LABELS:
            summary_lines.append(f"- {label}: `{staged_counts[split][label]}`")
        summary_lines.append("")

    summary_lines.extend(["## Projected counts after approved merge", ""])
    for split in ("train", "val", "test"):
        summary_lines.append(f"### {split}")
        for label in SEVERITY_LABELS:
            projected = existing_counts[split][label] + staged_counts[split][label]
            summary_lines.append(f"- {label}: `{projected}`")
        summary_lines.append("")

    summary_lines.extend(
        [
            "## Next steps",
            "",
            "1. Run the staging review helper.",
            "2. Fill the QA review CSV for approved and rejected samples.",
            "3. Merge only approved images into curated train, val, and test folders.",
            "4. Update the main train, val, and test manifests.",
            "5. Retrain the model with the standard training config.",
        ]
    )

    summary_path.parent.mkdir(parents=True, exist_ok=True)
    summary_path.write_text("\n".join(summary_lines), encoding="utf-8")

    print(f"Candidate split plan: {manifest_path}")
    print(f"Retraining summary: {summary_path}")
    print(f"Staged candidates: {len(staged_rows)}")


if __name__ == "__main__":
    main()
