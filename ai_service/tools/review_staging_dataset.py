from __future__ import annotations

import argparse
import csv
import hashlib
import random
from collections import Counter, defaultdict
from dataclasses import dataclass
from pathlib import Path

from PIL import Image, UnidentifiedImageError


PROJECT_ROOT = Path(__file__).resolve().parent.parent
DATASET_ROOT = PROJECT_ROOT / "datasets" / "bontoc_southern_leyte"
ARTIFACTS_REPORTS = PROJECT_ROOT / "artifacts" / "reports"


@dataclass(frozen=True)
class ImageReviewRecord:
    relative_path: str
    severity_label: str
    file_size_bytes: int
    width: int
    height: int
    aspect_ratio: float
    image_mode: str
    sha1_hash: str
    duplicate_group_size: int
    flags: str
    recommendation: str


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Review staged accident image samples before retraining.")
    parser.add_argument(
        "--dataset-root",
        default=str(DATASET_ROOT),
        help="Path to the Bontoc dataset root.",
    )
    parser.add_argument(
        "--staging-subdir",
        default="import_staging/accident_images_analysis_dataset",
        help="Relative staging subdirectory under the dataset root.",
    )
    parser.add_argument(
        "--min-dimension",
        type=int,
        default=224,
        help="Minimum image dimension considered training-friendly.",
    )
    parser.add_argument(
        "--sample-per-class",
        type=int,
        default=10,
        help="How many sample file names to include per class in the markdown summary.",
    )
    parser.add_argument(
        "--seed",
        type=int,
        default=42,
        help="Random seed for deterministic sample selection.",
    )
    return parser.parse_args()


def sha1_for_file(path: Path) -> str:
    digest = hashlib.sha1()
    with path.open("rb") as handle:
        while True:
            chunk = handle.read(1024 * 1024)
            if not chunk:
                break
            digest.update(chunk)
    return digest.hexdigest()


def inspect_image(path: Path) -> tuple[int, int, str]:
    with Image.open(path) as image:
        image.load()
        return image.width, image.height, image.mode


def recommendation_for_flags(flags: list[str]) -> str:
    if "corrupt" in flags:
        return "reject"
    if flags:
        return "review_first"
    return "ready_for_human_review"


def build_records(staging_root: Path, min_dimension: int) -> list[ImageReviewRecord]:
    raw_rows: list[dict[str, object]] = []
    duplicates: defaultdict[str, list[str]] = defaultdict(list)

    for class_dir in sorted([path for path in staging_root.iterdir() if path.is_dir()]):
        severity_label = class_dir.name.strip().lower()
        for image_path in sorted(class_dir.glob("*.jpg")):
            flags: list[str] = []
            width = 0
            height = 0
            mode = "unknown"

            try:
                width, height, mode = inspect_image(image_path)
            except (UnidentifiedImageError, OSError):
                flags.append("corrupt")

            if width and height:
                minimum_side = min(width, height)
                aspect_ratio = round(width / max(height, 1), 4)
                if minimum_side < min_dimension:
                    flags.append("too_small")
                if aspect_ratio > 2.5 or aspect_ratio < 0.4:
                    flags.append("extreme_aspect")
            else:
                aspect_ratio = 0.0

            file_hash = sha1_for_file(image_path)
            relative_path = image_path.relative_to(DATASET_ROOT).as_posix()
            duplicates[file_hash].append(relative_path)

            raw_rows.append(
                {
                    "relative_path": relative_path,
                    "severity_label": severity_label,
                    "file_size_bytes": image_path.stat().st_size,
                    "width": width,
                    "height": height,
                    "aspect_ratio": aspect_ratio,
                    "image_mode": mode,
                    "sha1_hash": file_hash,
                    "flags": flags,
                }
            )

    records: list[ImageReviewRecord] = []
    for row in raw_rows:
        flags = list(row["flags"])
        duplicate_group_size = len(duplicates[str(row["sha1_hash"])])
        if duplicate_group_size > 1:
            flags.append("duplicate_candidate")

        deduped_flags = ",".join(sorted(dict.fromkeys(flags))) if flags else "none"
        records.append(
            ImageReviewRecord(
                relative_path=str(row["relative_path"]),
                severity_label=str(row["severity_label"]),
                file_size_bytes=int(row["file_size_bytes"]),
                width=int(row["width"]),
                height=int(row["height"]),
                aspect_ratio=float(row["aspect_ratio"]),
                image_mode=str(row["image_mode"]),
                sha1_hash=str(row["sha1_hash"]),
                duplicate_group_size=duplicate_group_size,
                flags=deduped_flags,
                recommendation=recommendation_for_flags(flags),
            )
        )

    return records


def write_csv(records: list[ImageReviewRecord], output_path: Path) -> None:
    output_path.parent.mkdir(parents=True, exist_ok=True)
    with output_path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.writer(handle)
        writer.writerow(
            [
                "staging_relative_path",
                "severity_label",
                "file_size_bytes",
                "width",
                "height",
                "aspect_ratio",
                "image_mode",
                "sha1_hash",
                "duplicate_group_size",
                "flags",
                "recommendation",
            ]
        )
        for record in records:
            writer.writerow(
                [
                    record.relative_path,
                    record.severity_label,
                    record.file_size_bytes,
                    record.width,
                    record.height,
                    f"{record.aspect_ratio:.4f}",
                    record.image_mode,
                    record.sha1_hash,
                    record.duplicate_group_size,
                    record.flags,
                    record.recommendation,
                ]
            )


def write_markdown_summary(
    records: list[ImageReviewRecord],
    summary_path: Path,
    staging_root: Path,
    sample_per_class: int,
    seed: int,
) -> None:
    summary_path.parent.mkdir(parents=True, exist_ok=True)
    grouped: defaultdict[str, list[ImageReviewRecord]] = defaultdict(list)
    for record in records:
        grouped[record.severity_label].append(record)

    randomizer = random.Random(seed)
    lines = [
        "# Staging Review Summary",
        "",
        f"- staging root: `{staging_root.as_posix()}`",
        f"- total images: `{len(records)}`",
        "",
        "## Class counts",
        "",
    ]

    for label in sorted(grouped):
        class_records = grouped[label]
        flags_counter = Counter(
            flag
            for record in class_records
            for flag in ([] if record.flags == "none" else record.flags.split(","))
        )
        lines.extend(
            [
                f"### {label}",
                "",
                f"- images: `{len(class_records)}`",
                f"- ready for review: `{sum(1 for record in class_records if record.recommendation == 'ready_for_human_review')}`",
                f"- flagged first: `{sum(1 for record in class_records if record.recommendation != 'ready_for_human_review')}`",
            ]
        )
        if flags_counter:
            lines.append("- flag summary:")
            for flag, count in sorted(flags_counter.items()):
                lines.append(f"  - `{flag}`: `{count}`")

        sample_records = class_records.copy()
        randomizer.shuffle(sample_records)
        lines.extend(["", "- sample files:"])
        for record in sample_records[:sample_per_class]:
            lines.append(f"  - `{record.relative_path}`")
        lines.append("")

    summary_path.write_text("\n".join(lines), encoding="utf-8")


def main() -> None:
    args = parse_args()
    dataset_root = Path(args.dataset_root).resolve()
    staging_root = (dataset_root / args.staging_subdir).resolve()
    if not staging_root.exists():
        raise FileNotFoundError(f"Staging folder was not found: {staging_root}")

    records = build_records(staging_root=staging_root, min_dimension=args.min_dimension)
    dataset_slug = staging_root.name
    csv_path = ARTIFACTS_REPORTS / f"staging_review_{dataset_slug}.csv"
    summary_path = ARTIFACTS_REPORTS / f"staging_review_{dataset_slug}.md"

    write_csv(records, csv_path)
    write_markdown_summary(
        records=records,
        summary_path=summary_path,
        staging_root=staging_root,
        sample_per_class=args.sample_per_class,
        seed=args.seed,
    )

    print(f"Review CSV: {csv_path}")
    print(f"Review summary: {summary_path}")
    print(f"Images scanned: {len(records)}")


if __name__ == "__main__":
    main()
