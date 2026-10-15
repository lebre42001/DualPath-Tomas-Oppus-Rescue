from __future__ import annotations

import argparse
import csv
import random
import shutil
import sys
from collections import defaultdict
from dataclasses import dataclass
from pathlib import Path


PROJECT_ROOT = Path(__file__).resolve().parent.parent
if str(PROJECT_ROOT) not in sys.path:
    sys.path.insert(0, str(PROJECT_ROOT))

from src.ai_service.labels import SEVERITY_LABELS


DATASET_ROOT = PROJECT_ROOT / "datasets" / "bontoc_southern_leyte"


@dataclass(frozen=True)
class ReviewRow:
    relative_path: str
    severity_label: str
    sha1_hash: str
    flags: set[str]


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Build a deduplicated balanced external trial subset.")
    parser.add_argument(
        "--dataset-root",
        default=str(DATASET_ROOT),
        help="Path to the Bontoc dataset root.",
    )
    parser.add_argument(
        "--review-csv",
        default=str(PROJECT_ROOT / "artifacts" / "reports" / "staging_review_accident_images_analysis_dataset.csv"),
        help="Path to the staging review CSV.",
    )
    parser.add_argument(
        "--trial-name",
        default="external_trial_accident_images_analysis_dataset",
        help="Folder slug for the generated trial subset.",
    )
    parser.add_argument(
        "--per-class",
        type=int,
        default=60,
        help="Number of deduplicated images per class to include in the trial subset.",
    )
    parser.add_argument(
        "--seed",
        type=int,
        default=42,
        help="Random seed for deterministic selection.",
    )
    parser.add_argument(
        "--source-domain",
        default="road_accident",
        help="Source domain value to write into the generated manifests.",
    )
    parser.add_argument(
        "--incident-type",
        default="vehicular_accident",
        help="Incident type value to write into the generated manifests.",
    )
    parser.add_argument(
        "--review-status",
        default="approved_external_trial",
        help="Review status value to write into the generated manifests.",
    )
    parser.add_argument(
        "--description-template",
        default="Approved external trial import for {label}.",
        help="Description template for each generated manifest row. Use {label} as a placeholder.",
    )
    return parser.parse_args()


def read_review_rows(path: Path) -> list[ReviewRow]:
    rows: list[ReviewRow] = []
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        for row in reader:
            rows.append(
                ReviewRow(
                    relative_path=row["staging_relative_path"].strip(),
                    severity_label=row["severity_label"].strip().lower(),
                    sha1_hash=row["sha1_hash"].strip(),
                    flags=set(flag for flag in row["flags"].split(",") if flag and flag != "none"),
                )
            )
    return rows


def split_name(index: int, total: int) -> str:
    if total <= 0:
        return "train"
    ratio = index / total
    if ratio < 0.70:
        return "train"
    if ratio < 0.85:
        return "val"
    return "test"


def main() -> None:
    args = parse_args()
    dataset_root = Path(args.dataset_root).resolve()
    review_csv = Path(args.review_csv).resolve()
    rows = read_review_rows(review_csv)
    randomizer = random.Random(args.seed)

    grouped: defaultdict[str, list[ReviewRow]] = defaultdict(list)
    seen_hashes: defaultdict[str, set[str]] = defaultdict(set)

    for row in rows:
        if row.severity_label not in SEVERITY_LABELS:
            continue
        if "corrupt" in row.flags:
            continue
        if row.sha1_hash in seen_hashes[row.severity_label]:
            continue
        seen_hashes[row.severity_label].add(row.sha1_hash)
        grouped[row.severity_label].append(row)

    trial_root = dataset_root / "curated" / args.trial_name / "images"
    metadata_root = dataset_root / "metadata"
    manifest_paths = {
        "train": metadata_root / f"train_{args.trial_name}.csv",
        "val": metadata_root / f"val_{args.trial_name}.csv",
        "test": metadata_root / f"test_{args.trial_name}.csv",
    }

    for split in manifest_paths:
        manifest_paths[split].parent.mkdir(parents=True, exist_ok=True)
        with manifest_paths[split].open("w", encoding="utf-8", newline="") as handle:
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
                ]
            )

    summary_lines = [
        "# External Trial Subset Summary",
        "",
        f"- review csv: `{review_csv.as_posix()}`",
        f"- trial root: `{trial_root.as_posix()}`",
        f"- requested per class: `{args.per_class}`",
        "",
    ]

    for label in SEVERITY_LABELS:
        candidates = grouped[label]
        randomizer.shuffle(candidates)
        selected = candidates[: min(args.per_class, len(candidates))]
        total_selected = len(selected)
        summary_lines.extend(
            [
                f"## {label}",
                "",
                f"- deduplicated candidates: `{len(candidates)}`",
                f"- selected for trial merge: `{total_selected}`",
                "",
            ]
        )

        for index, row in enumerate(selected):
            split = split_name(index, total_selected)
            target_dir = trial_root / split / label
            target_dir.mkdir(parents=True, exist_ok=True)

            source_path = dataset_root / row.relative_path
            target_name = f"trial_{label}_{index + 1:04d}{source_path.suffix.lower()}"
            target_path = target_dir / target_name
            shutil.copy2(source_path, target_path)

            manifest_relative_path = target_path.relative_to(dataset_root).as_posix()
            with manifest_paths[split].open("a", encoding="utf-8", newline="") as handle:
                writer = csv.writer(handle)
                writer.writerow(
                    [
                        manifest_relative_path,
                        label,
                        args.source_domain,
                        "",
                        "",
                        "",
                        "",
                        "",
                        args.incident_type,
                        args.description_template.format(label=label),
                        "unknown",
                        args.review_status,
                    ]
                )

    summary_path = PROJECT_ROOT / "artifacts" / "reports" / f"{args.trial_name}_summary.md"
    summary_path.parent.mkdir(parents=True, exist_ok=True)
    summary_path.write_text("\n".join(summary_lines), encoding="utf-8")

    print(f"Trial root: {trial_root}")
    for split, manifest_path in manifest_paths.items():
        print(f"{split} manifest: {manifest_path}")
    print(f"Summary: {summary_path}")


if __name__ == "__main__":
    main()
