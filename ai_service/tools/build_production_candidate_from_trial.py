from __future__ import annotations

import argparse
import csv
from pathlib import Path


PROJECT_ROOT = Path(__file__).resolve().parent.parent
DATASET_ROOT = PROJECT_ROOT / "datasets" / "bontoc_southern_leyte"


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Build production-candidate manifests from approved external trial manifests."
    )
    parser.add_argument(
        "--dataset-root",
        default=str(DATASET_ROOT),
        help="Path to the Bontoc dataset root.",
    )
    parser.add_argument(
        "--source-slug",
        default="accident_severity_image_dataset_v4",
        help="Source slug used in the existing external trial manifest names.",
    )
    parser.add_argument(
        "--candidate-slug",
        default="production_candidate_external_approved_kaggle_300",
        help="Slug used in the generated production-candidate manifest names.",
    )
    parser.add_argument(
        "--review-status",
        default="approved_production_candidate_external_kaggle_300",
        help="Review status to write into the generated production-candidate manifests.",
    )
    parser.add_argument(
        "--description-prefix",
        default="Approved external production-candidate image for",
        help="Description prefix used when rewriting manifest descriptions.",
    )
    return parser.parse_args()


def source_manifest_paths(dataset_root: Path, source_slug: str) -> dict[str, Path]:
    metadata_root = dataset_root / "metadata"
    return {
        "train": metadata_root / f"train_external_trial_{source_slug}.csv",
        "val": metadata_root / f"val_external_trial_{source_slug}.csv",
        "test": metadata_root / f"test_external_trial_{source_slug}.csv",
    }


def target_manifest_paths(dataset_root: Path, candidate_slug: str) -> dict[str, Path]:
    metadata_root = dataset_root / "metadata"
    return {
        "train": metadata_root / f"train_{candidate_slug}.csv",
        "val": metadata_root / f"val_{candidate_slug}.csv",
        "test": metadata_root / f"test_{candidate_slug}.csv",
    }


def rewrite_rows(
    rows: list[dict[str, str]],
    review_status: str,
    description_prefix: str,
) -> list[dict[str, str]]:
    rewritten: list[dict[str, str]] = []
    for row in rows:
        updated = dict(row)
        label = updated.get("severity_label", "").strip().lower() or "unknown"
        updated["review_status"] = review_status
        updated["description"] = f"{description_prefix} {label}."
        rewritten.append(updated)
    return rewritten


def main() -> None:
    args = parse_args()
    dataset_root = Path(args.dataset_root).resolve()
    source_paths = source_manifest_paths(dataset_root, args.source_slug)
    target_paths = target_manifest_paths(dataset_root, args.candidate_slug)

    fieldnames: list[str] | None = None
    split_counts: dict[str, int] = {}

    for split, source_path in source_paths.items():
        if not source_path.exists():
            raise FileNotFoundError(f"Source manifest not found: {source_path}")

        with source_path.open("r", encoding="utf-8-sig", newline="") as handle:
            reader = csv.DictReader(handle)
            rows = list(reader)
            fieldnames = reader.fieldnames or fieldnames

        rewritten_rows = rewrite_rows(
            rows=rows,
            review_status=args.review_status,
            description_prefix=args.description_prefix,
        )

        target_path = target_paths[split]
        target_path.parent.mkdir(parents=True, exist_ok=True)
        with target_path.open("w", encoding="utf-8", newline="") as handle:
            writer = csv.DictWriter(handle, fieldnames=fieldnames)
            writer.writeheader()
            writer.writerows(rewritten_rows)

        split_counts[split] = len(rewritten_rows)

    print(f"Source manifests: {source_paths}")
    print(f"Target manifests: {target_paths}")
    print(f"Split counts: {split_counts}")


if __name__ == "__main__":
    main()
