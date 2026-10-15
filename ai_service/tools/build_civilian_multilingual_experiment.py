"""Freeze AI-generated road-emergency draft examples into a family-safe experiment.

This builder refuses to overwrite outputs, silently drop overlaps, or convert
drafts to approved labels. It writes a five-way research split; only train,
validation and fresh_test are supplied to the training CLI.
"""
from __future__ import annotations

import csv
from collections import Counter, defaultdict
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import random
import re
import sys
import unicodedata

AI_ROOT = Path(__file__).resolve().parents[1]
REPO_ROOT = AI_ROOT.parent
sys.path.insert(0, str(AI_ROOT))
from src.ai_service.text_training import LABELS, LANGUAGES, dataset_summary, digest, normalized_text  # noqa: E402

STAGING = AI_ROOT / "artifacts/reports/civilian_multilingual_fulltrain_20261005_staging"
OUTPUT = AI_ROOT / "datasets/emergency_text/experiments/civilian_multilingual_6000w_20261005"
REPORTS = AI_ROOT / "artifacts/reports/civilian_multilingual_fulltrain_20261005"
SOURCE_FILES = ["english_draft.csv", "tagalog_draft.csv", "cebuano_draft.csv", "mixed_draft.csv"]
FULL_COLUMNS = [
    "case_id", "scenario_family_id", "annotation_group_id", "language", "text",
    "word_count", "length_bucket", "label", "rubric_version", "generation_source",
    "review_status", "created_at", "parent_case_id", "relationship_type", "normalized_text_hash",
]
TRAIN_COLUMNS = ["case_id", "group_id", "language", "text", "label", "review_status", "reviewer", "notes"]
BUCKETS = {"1-3": (1, 3), "4-8": (4, 8), "9-15": (9, 15), "16+": (16, 10**9)}
SPLIT_SEED = 20261005


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for block in iter(lambda: f.read(1024 * 1024), b""):
            h.update(block)
    return h.hexdigest()


def bucket(text: str) -> str:
    n = len(text.split())
    for name, (lo, hi) in BUCKETS.items():
        if lo <= n <= hi:
            return name
    raise ValueError(f"Description must contain at least one word: {text!r}")


def read_staging() -> tuple[list[dict], dict[str, str]]:
    rows: list[dict] = []
    source_hashes: dict[str, str] = {}
    for filename in SOURCE_FILES:
        path = STAGING / filename
        if not path.is_file():
            raise FileNotFoundError(path)
        source_hashes[filename] = sha256(path)
        with path.open(encoding="utf-8-sig", newline="") as f:
            reader = csv.DictReader(f)
            if reader.fieldnames != FULL_COLUMNS:
                raise ValueError(f"Unexpected columns in {filename}: {reader.fieldnames}")
            rows.extend(dict(row) for row in reader)
    ids, text_hashes = set(), set()
    for row in rows:
        text = row["text"].strip()
        normalized = normalized_text(text)
        wc = len(text.split())
        expected_hash = hashlib.sha256(normalized.encode("utf-8")).hexdigest()
        if not row["case_id"] or row["case_id"] in ids:
            raise ValueError(f"Missing or duplicate candidate ID: {row['case_id']}")
        if normalized in text_hashes:
            raise ValueError(f"Duplicate normalized description: {row['case_id']}")
        if wc != int(row["word_count"]) or bucket(text) != row["length_bucket"]:
            raise ValueError(f"Word count/bucket mismatch: {row['case_id']}")
        if row["normalized_text_hash"] != expected_hash:
            raise ValueError(f"Normalized text hash mismatch: {row['case_id']}")
        if row["language"] not in LANGUAGES or row["label"] not in LABELS:
            raise ValueError(f"Unsupported language/label: {row['case_id']}")
        if row["review_status"] != "draft" or row["generation_source"] != "synthetic_ai_generated":
            raise ValueError(f"Refusing non-draft or non-synthetic row: {row['case_id']}")
        if row["rubric_version"] != "TEXT-RUBRIC-0.2-B":
            raise ValueError(f"Rubric mismatch: {row['case_id']}")
        ids.add(row["case_id"])
        text_hashes.add(normalized)
    return rows, source_hashes


def prior_text_inventory() -> tuple[dict[str, str], int, list[dict[str, str]]]:
    result: dict[str, str] = {}
    records = 0
    inaccessible: list[dict[str, str]] = []
    for path in (AI_ROOT / "datasets/emergency_text").rglob("*.csv"):
        if STAGING in path.parents or OUTPUT in path.parents:
            continue
        try:
            with path.open(encoding="utf-8-sig", newline="") as f:
                reader = csv.DictReader(f)
                if not reader.fieldnames or "text" not in reader.fieldnames:
                    continue
                for row in reader:
                    if row.get("text", "").strip():
                        key = re.sub(r"[^\w\s]", " ", normalized_text(row["text"]))
                        key = " ".join(key.split())
                        result.setdefault(key, str(path.relative_to(REPO_ROOT)))
                        records += 1
        except (OSError, UnicodeError, csv.Error) as exc:
            inaccessible.append({"path": str(path.relative_to(REPO_ROOT)), "error": type(exc).__name__})
            continue
    return result, records, inaccessible


def write_csv(path: Path, columns: list[str], rows: list[dict]) -> None:
    with path.open("w", encoding="utf-8", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=columns, lineterminator="\n")
        writer.writeheader()
        writer.writerows({key: row.get(key, "") for key in columns} for row in rows)


def train_row(row: dict) -> dict:
    return {"case_id": row["case_id"], "group_id": row["scenario_family_id"],
            "language": row["language"], "text": row["text"], "label": row["label"],
            "review_status": "draft", "reviewer": "", "notes": "Synthetic draft; unreviewed."}


def split_rows(rows: list[dict]) -> dict[str, list[dict]]:
    grouped: dict[tuple[str, str], dict[str, list[dict]]] = defaultdict(lambda: defaultdict(list))
    group_labels: dict[str, str] = {}
    group_langs: dict[str, str] = {}
    annotation_families: dict[str, set[str]] = defaultdict(set)
    for row in rows:
        group = row["scenario_family_id"]
        if not group:
            raise ValueError(f"Missing scenario family: {row['case_id']}")
        if group in group_labels and group_labels[group] != row["label"]:
            raise ValueError(f"A family crosses labels: {group}")
        if group in group_langs and group_langs[group] != row["language"]:
            raise ValueError(f"A family crosses language groups: {group}")
        group_labels[group], group_langs[group] = row["label"], row["language"]
        if row["annotation_group_id"]:
            annotation_families[row["annotation_group_id"]].add(group)
        grouped[(row["language"], row["label"])][group].append(row)
    cross_family_annotations = {k: sorted(v) for k, v in annotation_families.items() if len(v) > 1}
    if cross_family_annotations:
        raise ValueError(f"Annotation groups span declared families; reconcile before splitting: {cross_family_annotations}")

    output: dict[str, list[dict]] = {name: [] for name in ("train", "validation", "calibration", "fresh_test", "challenge")}
    rng = random.Random(SPLIT_SEED)
    for language in LANGUAGES:
        for label in LABELS:
            families = list(grouped[(language, label)].items())
            if len(families) < 10:
                raise ValueError(f"Insufficient families for {language}/{label}: {len(families)}")
            rng.shuffle(families)
            n = len(families)
            n_val = max(1, round(n * 0.15))
            n_cal = max(1, round(n * 0.10))
            n_test = max(1, round(n * 0.10))
            n_challenge = max(1, round(n * 0.05))
            counts = {"validation": n_val, "calibration": n_cal, "fresh_test": n_test, "challenge": n_challenge}
            cursor = 0
            for name, count in counts.items():
                for _, items in families[cursor:cursor + count]:
                    output[name].extend(items)
                cursor += count
            for _, items in families[cursor:]:
                output["train"].extend(items)
    for part in output.values():
        part.sort(key=lambda row: row["case_id"])
    all_ids = [r["case_id"] for part in output.values() for r in part]
    if len(all_ids) != len(set(all_ids)) or set(all_ids) != {r["case_id"] for r in rows}:
        raise ValueError("Five-way split does not cover each case exactly once")
    family_sets = {name: {r["scenario_family_id"] for r in part} for name, part in output.items()}
    for i, left in enumerate(output):
        for right in list(output)[i + 1:]:
            if family_sets[left] & family_sets[right]:
                raise ValueError(f"Family leakage between {left} and {right}")
    return output


def main() -> None:
    refresh = os.environ.get("CIVILIAN_EXPERIMENT_REFRESH") == "1"
    if OUTPUT.exists() or REPORTS.exists():
        if not refresh:
            raise FileExistsError(f"Refusing to reuse existing dataset/report directories without CIVILIAN_EXPERIMENT_REFRESH=1: {OUTPUT} / {REPORTS}")
        if not OUTPUT.is_dir() or not REPORTS.is_dir():
            raise FileExistsError("Refresh expects both existing task output paths to be directories")
        prior_receipt = OUTPUT / "dataset_receipt.json"
        prior_summary = REPORTS / "BUILD_SUMMARY.json"
        if not prior_receipt.is_file() or not prior_summary.is_file():
            raise FileExistsError("Refresh refused: existing paths lack this experiment's generated receipts")
        prior_state = json.loads(prior_receipt.read_text(encoding="utf-8"))
        prior_build = json.loads(prior_summary.read_text(encoding="utf-8"))
        if prior_state.get("dataset_id") != OUTPUT.name or Path(prior_build.get("dataset_dir", "")).resolve() != OUTPUT.resolve():
            raise FileExistsError("Refresh refused: existing receipts identify another dataset")
        if prior_state.get("status") != "provisional_synthetic_research_dataset_not_human_reviewed":
            raise FileExistsError("Refresh refused: prior output does not have the expected unreviewed synthetic status")
    rows, source_hashes = read_staging()
    prior, prior_count, inaccessible = prior_text_inventory()
    exact_matches = []
    for row in rows:
        key = re.sub(r"[^\w\s]", " ", normalized_text(row["text"]))
        key = " ".join(key.split())
        if key in prior:
            exact_matches.append({"case_id": row["case_id"], "source": prior[key], "text": row["text"]})
    if exact_matches:
        raise ValueError(f"Exact prior-data overlap; replace or link before freezing: {exact_matches}")
    partitions = split_rows(rows)
    OUTPUT.mkdir(parents=True, exist_ok=refresh)
    REPORTS.mkdir(parents=True, exist_ok=refresh)
    for name, part in partitions.items():
        write_csv(OUTPUT / f"{name}.csv", TRAIN_COLUMNS, [train_row(row) for row in part])
    training_splits = {"train": partitions["train"], "validation": partitions["validation"], "test": partitions["fresh_test"]}
    development_rows = [r for part in training_splits.values() for r in part]
    write_csv(OUTPUT / "reports.csv", TRAIN_COLUMNS, [train_row(row) for row in rows])
    write_csv(OUTPUT / "model_development.csv", TRAIN_COLUMNS, [train_row(row) for row in development_rows])
    write_csv(OUTPUT / "provenance.csv", FULL_COLUMNS, rows)

    from src.ai_service.text_training import _manifest
    model_split = _manifest({name: [train_row(row) for row in part] for name, part in training_splits.items()})
    # _manifest reads group_id as the split group key; provenance retains family IDs.
    (OUTPUT / "training_split_manifest.json").write_text(json.dumps(model_split, indent=2) + "\n", encoding="utf-8")
    full_split = {name: _manifest({name: [train_row(row) for row in part]})[name] for name, part in partitions.items()}
    (OUTPUT / "split_manifest.json").write_text(json.dumps({"seed": SPLIT_SEED, "method": "stratified_by_language_label_and_scenario_family", "partitions": full_split}, indent=2) + "\n", encoding="utf-8")

    by_lang = defaultdict(list)
    by_label = defaultdict(list)
    by_length = defaultdict(list)
    for row in rows:
        by_lang[row["language"]].append(row)
        by_label[row["label"]].append(row)
        by_length[row["length_bucket"]].append(row)
    stats = {"rows": len(rows), "words": sum(int(r["word_count"]) for r in rows), "languages": {}, "labels": {}, "length_buckets": {}, "review_status": dict(Counter(r["review_status"] for r in rows)), "generation_source": dict(Counter(r["generation_source"] for r in rows)), "scenario_family_count": len({r["scenario_family_id"] for r in rows}), "annotation_group_count": len({r["annotation_group_id"] for r in rows})}
    for name, subset in by_lang.items():
        stats["languages"][name] = {"rows": len(subset), "words": sum(int(r["word_count"]) for r in subset), "by_label": {l: {"rows": sum(r["label"] == l for r in subset), "words": sum(int(r["word_count"]) for r in subset if r["label"] == l)} for l in LABELS}, "by_length": dict(Counter(r["length_bucket"] for r in subset))}
    for name, subset in by_label.items():
        stats["labels"][name] = {"rows": len(subset), "words": sum(int(r["word_count"]) for r in subset), "by_language": dict(Counter(r["language"] for r in subset)), "by_length": dict(Counter(r["length_bucket"] for r in subset))}
    stats["length_buckets"] = {name: {"rows": len(subset), "by_language": dict(Counter(r["language"] for r in subset)), "by_label": dict(Counter(r["label"] for r in subset))} for name, subset in by_length.items()}
    (OUTPUT / "dataset_statistics.json").write_text(json.dumps(stats, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    (OUTPUT / "language_statistics.json").write_text(json.dumps(stats["languages"], ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    (OUTPUT / "severity_statistics.json").write_text(json.dumps(stats["labels"], ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    (OUTPUT / "length_statistics.json").write_text(json.dumps(stats["length_buckets"], ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    family_manifest = {r["scenario_family_id"]: {"case_ids": [], "language": r["language"], "label": r["label"], "relationship_type": r["relationship_type"]} for r in rows}
    for row in rows:
        family_manifest[row["scenario_family_id"]]["case_ids"].append(row["case_id"])
    (OUTPUT / "family_manifest.json").write_text(json.dumps(family_manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    prov = {"created_at": datetime.now(timezone.utc).isoformat(), "status": "synthetic_draft_experiment_only", "rubric_version": "TEXT-RUBRIC-0.2-B", "human_confirmed_real_rows": 0, "human_confirmed_synthetic_rows": 0, "ai_generated_draft_rows": len(rows), "other_draft_rows": 0, "source_csv_sha256": source_hashes, "prior_dataset_csv_records_checked": prior_count, "prior_csv_inaccessible_files": inaccessible, "prior_dataset_scan_status": "partial_inaccessible_files" if inaccessible else "complete", "exact_prior_text_overlap_count_accessible_text_columns": 0, "all_rows_are_synthetic_ai_generated": True, "all_rows_remain_draft": True, "training_eligible_for_provisional_research_only_with_explicit_allow_draft": True, "training_or_release_approval": False, "label_semantics": "needs_review is an abstention/uncertainty state, not a medical severity", "limitations": ["No generated row is human-confirmed.", "Fresh-test, calibration and challenge rows are synthetic AI-authored drafts; they are family-disjoint within this experiment but do not establish independent real-world accuracy.", "Unique family IDs do not prove semantic independence; human scenario-link review remains necessary.", "Prior exact-overlap audit is incomplete at the individual-file level because some existing CSVs were inaccessible; accessible canonical/master CSVs were scanned.", "Tagalog, Cebuano/Bisaya and mixed language need fluent human review.", "No active checkpoint change or deployment is authorized by this research artifact."]}
    (OUTPUT / "provenance_summary.json").write_text(json.dumps(prov, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    for name, part in partitions.items():
        (OUTPUT / f"{name}_statistics.json").write_text(json.dumps(dataset_summary([train_row(r) for r in part]), ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    duplicate_audit = {"prior_csv_text_records_checked": prior_count, "prior_csv_inaccessible_files": inaccessible, "exact_punctuation_normalized_overlap_count_accessible_text_columns": 0, "new_dataset_duplicate_check": "pass", "prior_dataset_scan_status": "partial_inaccessible_files" if inaccessible else "complete", "similarity_audit_note": "Exact normalized matches were checked against all readable emergency_text CSV text columns. Some browsing-copy CSVs were inaccessible and were not read directly; accessible canonical masters were scanned. This is not a byte-level verification of inaccessible copies. Automated similarity checks and human semantic family adjudication are separate; absence of exact matches does not prove semantic independence."}
    (OUTPUT / "duplicate_audit.json").write_text(json.dumps(duplicate_audit, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    receipt_files = ["reports.csv", "model_development.csv", "provenance.csv", "training_split_manifest.json", "split_manifest.json", "dataset_statistics.json", "language_statistics.json", "severity_statistics.json", "length_statistics.json", "family_manifest.json", "provenance_summary.json", "duplicate_audit.json"] + [f"{name}.csv" for name in partitions] + [f"{name}_statistics.json" for name in partitions]
    receipt = {"dataset_id": OUTPUT.name, "created_at": datetime.now(timezone.utc).isoformat(), "files_sha256": {name: sha256(OUTPUT / name) for name in receipt_files}, "source_staging_csv_sha256": source_hashes, "statistics": stats, "split_rows": {name: len(part) for name, part in partitions.items()}, "group_leakage": "none by declared scenario_family_id; semantic ancestry not adjudicated", "prior_overlap_audit": "partial; readable canonical/master CSVs scanned, some existing CSVs were inaccessible", "prior_csv_inaccessible_paths": [item["path"] for item in inaccessible], "status": "provisional_synthetic_research_dataset_not_human_reviewed"}
    (OUTPUT / "dataset_receipt.json").write_text(json.dumps(receipt, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    readme = f"""# {OUTPUT.name}\n\nVersioned multilingual road/vehicle emergency-description experiment.\n\n- {len(rows)} synthetic AI-generated draft descriptions; {stats['words']} whitespace-separated words.\n- All proposed labels use {prov['rubric_version']}; no row is human-confirmed.\n- `needs_review` is an abstention/uncertainty state.\n- `provenance.csv` carries row-level source/language/family/word/hash fields. `reports.csv` is the all-row audit master and includes every partition; NEVER pass it to the training CLI.\n- Train only from `model_development.csv` with `training_split_manifest.json`; this pair contains train, validation and fresh_test (mapped to CLI `test`) only. Calibration and challenge rows are withheld from training and selection.\n- `fresh_test.csv`, `calibration.csv`, and `challenge.csv` are family-disjoint from train/validation but are synthetic drafts, not independent real-world evidence.\n- `training_split_manifest.json` is the immutable three-way split expected by `train_text.py`; full five-way partition hashes are in `split_manifest.json`.\n- No deployment, model activation or human-review approval is implied.\n"""
    (OUTPUT / "README.md").write_text(readme, encoding="utf-8")
    receipt["files_sha256"]["README.md"] = sha256(OUTPUT / "README.md")
    (OUTPUT / "dataset_receipt.json").write_text(json.dumps(receipt, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    REPORTS.mkdir(exist_ok=refresh)
    (REPORTS / "BUILD_SUMMARY.json").write_text(json.dumps({"dataset_dir": str(OUTPUT), "report_dir": str(REPORTS), "rows": len(rows), "words": stats["words"], "languages": stats["languages"], "labels": stats["labels"], "splits": receipt["split_rows"], "staging_hashes": source_hashes, "dataset_receipt": str(OUTPUT / "dataset_receipt.json")}, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"output": str(OUTPUT), "report": str(REPORTS), "rows": len(rows), "words": stats["words"], "splits": receipt["split_rows"], "status": receipt["status"]}, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
