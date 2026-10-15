from __future__ import annotations

import argparse
import csv
import hashlib
import shutil
from collections import Counter, defaultdict
from datetime import datetime
from pathlib import Path


PROJECT_ROOT = Path(__file__).resolve().parent.parent
DATASET_ROOT = PROJECT_ROOT / "datasets" / "bontoc_southern_leyte"
LABELS = ("minor", "serious", "fatal")


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Build a non-destructive v0.3 train/val/test split from approved reviewed-pool images."
    )
    parser.add_argument(
        "--dataset-root",
        default=str(DATASET_ROOT),
        help="Path to the Bontoc dataset root.",
    )
    parser.add_argument(
        "--approved-status",
        default="approved",
        help="Only rows with this review_status will be included.",
    )
    parser.add_argument(
        "--train-ratio",
        type=float,
        default=0.70,
        help="Train ratio per class.",
    )
    parser.add_argument(
        "--val-ratio",
        type=float,
        default=0.15,
        help="Validation ratio per class.",
    )
    parser.add_argument(
        "--test-ratio",
        type=float,
        default=0.15,
        help="Test ratio per class.",
    )
    return parser.parse_args()


def validate_ratios(train_ratio: float, val_ratio: float, test_ratio: float) -> None:
    total = round(train_ratio + val_ratio + test_ratio, 6)
    if total != 1.0:
        raise ValueError(f"Split ratios must sum to 1.0, got {total}")


def stable_key(value: str) -> str:
    return hashlib.sha1(value.encode("utf-8")).hexdigest()


def load_approved_rows(manifest_path: Path, approved_status: str) -> list[dict[str, str]]:
    with manifest_path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        rows: list[dict[str, str]] = []

        for row in reader:
            image_relative_path = (row.get("image_relative_path") or "").strip()
            severity_label = (row.get("severity_label") or "").strip().lower()
            review_status = (row.get("review_status") or "").strip().lower()

            if not image_relative_path or severity_label not in LABELS:
                continue
            if review_status != approved_status:
                continue

            rows.append({key: (value or "").strip() for key, value in row.items()})

    return rows


def assign_splits(
    approved_rows: list[dict[str, str]],
    *,
    train_ratio: float,
    val_ratio: float,
) -> dict[str, list[dict[str, str]]]:
    per_label: dict[str, list[dict[str, str]]] = defaultdict(list)
    for row in approved_rows:
        per_label[row["severity_label"]].append(row)

    output = {"train": [], "val": [], "test": []}

    for label in LABELS:
        rows = sorted(per_label[label], key=lambda row: stable_key(row["image_relative_path"]))
        total = len(rows)
        if total == 0:
            train_count = 0
            val_count = 0
            test_count = 0
        elif total == 1:
            train_count = 0
            val_count = 0
            test_count = 1
        elif total == 2:
            train_count = 1
            val_count = 0
            test_count = 1
        elif total == 3:
            train_count = 1
            val_count = 1
            test_count = 1
        else:
            train_count = max(1, int(total * train_ratio))
            val_count = max(1, int(total * val_ratio))
            test_count = total - train_count - val_count

            if test_count <= 0:
                test_count = 1
                train_count = total - val_count - test_count

            if train_count <= 0:
                train_count = 1
                val_count = max(1, total - train_count - test_count)

            while train_count + val_count + test_count > total:
                if train_count > val_count and train_count > 1:
                    train_count -= 1
                elif val_count > 1:
                    val_count -= 1
                elif test_count > 1:
                    test_count -= 1
                else:
                    break

        output["train"].extend(rows[:train_count])
        output["val"].extend(rows[train_count:train_count + val_count])
        output["test"].extend(rows[train_count + val_count:train_count + val_count + test_count])

    return output


def copy_images_and_rewrite_rows(
    dataset_root: Path,
    split_rows: dict[str, list[dict[str, str]]],
) -> dict[str, list[dict[str, str]]]:
    generated: dict[str, list[dict[str, str]]] = {"train": [], "val": [], "test": []}

    for split, rows in split_rows.items():
        counters: Counter[str] = Counter()

        for row in rows:
            label = row["severity_label"]
            source_relative = row["image_relative_path"]
            source_path = (dataset_root / source_relative).resolve()

            if not source_path.exists():
                raise FileNotFoundError(f"Reviewed-pool image not found: {source_path}")

            counters[label] += 1
            filename = f"{split}_{label}_{counters[label]:04d}{source_path.suffix.lower() or '.jpg'}"
            target_relative = f"v0_3_candidate/images/{split}/{label}/{filename}"
            target_path = (dataset_root / target_relative).resolve()
            target_path.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(source_path, target_path)

            updated = dict(row)
            updated["image_relative_path"] = target_relative
            updated["split_usage"] = split
            updated["source_pool_reference"] = source_relative
            generated[split].append(updated)

    return generated


def write_manifest(path: Path, rows: list[dict[str, str]]) -> None:
    fieldnames = [
        "image_relative_path",
        "severity_label",
        "source_domain",
        "municipality",
        "province",
        "barangay",
        "latitude",
        "longitude",
        "incident_type",
        "road_type",
        "weather",
        "lighting_condition",
        "vehicle_types",
        "motorcycle_present",
        "multi_vehicle",
        "rural_scene",
        "split_usage",
        "source_pool_reference",
    ]
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fieldnames)
        writer.writeheader()
        for row in rows:
            writer.writerow({name: row.get(name, "") for name in fieldnames})


def write_summary(path: Path, generated: dict[str, list[dict[str, str]]], manifest_paths: dict[str, Path]) -> None:
    lines = [
        "# v0.3 Candidate Split Summary",
        "",
        f"Date: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}",
        "",
        "## Generated manifests",
        "",
    ]

    for split in ("train", "val", "test"):
        lines.append(f"- `{split}`: `{manifest_paths[split].as_posix()}`")

    lines.extend(["", "## Split counts", ""])

    for split in ("train", "val", "test"):
        counts = Counter(row["severity_label"] for row in generated[split])
        lines.append(f"### {split}")
        for label in LABELS:
            lines.append(f"- `{label}`: `{counts[label]}`")
        lines.append(f"- total: `{len(generated[split])}`")
        lines.append("")

    lines.extend(
        [
            "## Notes",
            "",
            "- only approved reviewed-pool rows were included",
            "- images were copied into the v0.3 split folders",
            "- local validation images remain separate and untouched",
        ]
    )

    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")


def main() -> None:
    args = parse_args()
    validate_ratios(args.train_ratio, args.val_ratio, args.test_ratio)

    dataset_root = Path(args.dataset_root).resolve()
    candidate_root = dataset_root / "v0_3_candidate"
    reviewed_manifest = candidate_root / "manifests" / "reviewed_pool_manifest_template.csv"

    manifest_paths = {
        "train": candidate_root / "manifests" / "train_v0_3_candidate_template.csv",
        "val": candidate_root / "manifests" / "val_v0_3_candidate_template.csv",
        "test": candidate_root / "manifests" / "test_v0_3_candidate_template.csv",
    }
    summary_path = candidate_root / "reports" / "v0_3_candidate_split_summary.md"

    approved_rows = load_approved_rows(reviewed_manifest, args.approved_status.strip().lower())
    if not approved_rows:
        raise ValueError("No approved reviewed-pool rows were found. Mark rows as approved first.")

    split_rows = assign_splits(
        approved_rows,
        train_ratio=args.train_ratio,
        val_ratio=args.val_ratio,
    )
    generated = copy_images_and_rewrite_rows(dataset_root, split_rows)

    for split, rows in generated.items():
        write_manifest(manifest_paths[split], rows)

    write_summary(summary_path, generated, manifest_paths)

    print(f"Reviewed manifest: {reviewed_manifest}")
    print(f"Approved rows: {len(approved_rows)}")
    print(f"Train manifest: {manifest_paths['train']}")
    print(f"Val manifest: {manifest_paths['val']}")
    print(f"Test manifest: {manifest_paths['test']}")
    print(f"Summary report: {summary_path}")


if __name__ == "__main__":
    main()
