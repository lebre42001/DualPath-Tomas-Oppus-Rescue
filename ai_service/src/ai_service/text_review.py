"""Offline review, frozen splits and validation-only threshold analysis.

No command sends messages, approves a model, or changes live configuration.
Review imports require completed human decisions and always write a new CSV.
"""
from __future__ import annotations

import argparse
from collections import Counter
import csv
from datetime import date, datetime, timezone
import json
from pathlib import Path

from .text_training import (COLUMNS, DEFAULT_DATA, LABELS, LANGUAGES, _manifest,
    _prepare_output, _write_json, dataset_fingerprint, dataset_summary,
    digest, load_dataset, normalized_text, prediction_metrics, select_rows, split_groups)

WORKSHEET_COLUMNS = ("source_fingerprint", "case_id", "group_id", "language", "text",
                     "suggested_label", "decision", "final_label", "reviewer", "reviewed_at", "rationale")


def write_csv(path: Path, rows: list[dict], columns=COLUMNS) -> None:
    # Exclusive creation: existing masters and experiments are never overwritten.
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("x", encoding="utf-8-sig", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=columns)
        writer.writeheader()
        writer.writerows(rows)


def audit(rows: list[dict]) -> dict:
    counts = Counter(normalized_text(row["text"]) for row in rows if row["review_status"] != "rejected")
    coverage = {language: {label: {
        "rows": sum(row["language"] == language and row["label"] == label for row in rows),
        "approved": sum(row["language"] == language and row["label"] == label and row["review_status"] == "approved" for row in rows),
        "groups": len({row["group_id"] for row in rows if row["language"] == language and row["label"] == label}),
    } for label in LABELS} for language in LANGUAGES}
    return {**dataset_summary(rows), "coverage": coverage,
            "duplicate_normalized_text_count": sum(count - 1 for count in counts.values()),
            "over_1000_character_case_ids": [row["case_id"] for row in rows if len(row["text"]) > 1000],
            "review_needed": sum(row["review_status"] == "draft" for row in rows),
            "ready_for_release": False,
            "note": "Schema and counts are not human review or independent field validation."}


def export_worksheet(rows: list[dict], output: Path) -> dict:
    fingerprint = dataset_fingerprint(rows)
    priority = {"critical": 0, "needs_review": 1, "serious": 2, "minor": 3}
    ordered = sorted((row for row in rows if row["review_status"] == "draft"),
                     key=lambda row: (priority[row["label"]], {"tl": 0, "ceb": 1, "mixed": 2, "en": 3}[row["language"]], row["group_id"], row["case_id"]))
    records = [{"source_fingerprint": fingerprint, **{key: row[key] for key in ("case_id", "group_id", "language", "text")},
                "suggested_label": row["label"], **dict.fromkeys(("decision", "final_label", "reviewer", "reviewed_at", "rationale"), "")}
               for row in ordered]
    write_csv(output, records, WORKSHEET_COLUMNS)
    return {"worksheet": str(output), "pending_rows": len(records), "approved_by_export": 0}


def import_worksheet(rows: list[dict], worksheet: Path) -> list[dict]:
    fingerprint = dataset_fingerprint(rows)
    by_id = {row["case_id"]: dict(row) for row in rows}
    seen = set()
    with worksheet.open(encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        if reader.fieldnames != list(WORKSHEET_COLUMNS):
            raise ValueError("Review worksheet columns changed")
        for raw in reader:
            if None in raw or any(value is None for value in raw.values()):
                raise ValueError("Malformed review worksheet row")
            item = {key: value.strip() for key, value in raw.items()}
            identifier = item["case_id"]
            if identifier not in by_id or identifier in seen or item["source_fingerprint"] != fingerprint:
                raise ValueError("Unknown/duplicate case or stale review source fingerprint")
            seen.add(identifier)
            row = by_id[identifier]
            if row["review_status"] != "draft":
                raise ValueError("Worksheet cannot replace existing approval/rejection records")
            if any(item[key] != row[key] for key in ("group_id", "language", "text")) or item["suggested_label"] != row["label"]:
                raise ValueError("Worksheet identity/text changed; correct the master first and export a fresh worksheet")
            if not item["decision"]:
                if any(item[key] for key in ("final_label", "reviewer", "reviewed_at", "rationale")):
                    raise ValueError("Partially completed review requires an explicit decision")
                continue
            if item["decision"] not in ("approved", "rejected") or item["final_label"] not in LABELS:
                raise ValueError("Decision must be approved/rejected with a supported final label")
            if not item["reviewer"] or not item["rationale"]:
                raise ValueError("Human review needs an accountable reviewer and rationale")
            reviewed_at = date.fromisoformat(item["reviewed_at"])
            if reviewed_at > datetime.now(timezone.utc).date():
                raise ValueError("Review date cannot be in the future")
            row.update(label=item["final_label"], review_status=item["decision"], reviewer=item["reviewer"])
            row["notes"] += f" | Human review {reviewed_at.isoformat()} by {item['reviewer']}: {item['rationale']}"
    # Check label agreement across related non-rejected translations BEFORE writing.
    labels = {}
    for row in by_id.values():
        if row["review_status"] != "rejected":
            previous = labels.setdefault(row["group_id"], row["label"])
            if previous != row["label"]:
                raise ValueError("Related translations have conflicting labels; resolve the entire group before importing")
    return sorted(by_id.values(), key=lambda row: row["case_id"])


def freeze(rows: list[dict], output: Path, allow_draft: bool, seed: int) -> dict:
    selected = select_rows(rows, allow_draft)
    checks = audit(selected)
    if checks["duplicate_normalized_text_count"] or checks["over_1000_character_case_ids"]:
        raise ValueError("Fix duplicated/overlength descriptions before freezing")
    splits = split_groups(selected, seed)
    output = _prepare_output(output)
    write_csv(output / "reports.csv", selected)
    _write_json(output / "split_manifest.json", _manifest(splits))
    receipt = {**checks, "seed": seed, "all_data_reviewed": all(row["review_status"] == "approved" for row in selected),
               "holdout_independence": "Requires documented human verification of source incidents and prior experiment overlap",
               "approved_for_deployment": False}
    _write_json(output / "dataset_receipt.json", receipt)
    return {"output": str(output), "rows": len(selected), "approved_for_deployment": False}


def check_fresh_holdout(rows: list[dict], models: list[Path]) -> dict:
    if any(row["review_status"] != "approved" for row in rows):
        raise ValueError("Fresh release holdout must contain only human-approved descriptions")
    checks = audit(rows)
    if checks["duplicate_normalized_text_count"] or checks["over_1000_character_case_ids"]:
        raise ValueError("Holdout contains duplicated or overlength examples")
    for model in models:
        manifest = json.loads((model / "split_manifest.json").read_text(encoding="utf-8"))
        groups = set().union(*(set(split["group_hashes"]) for split in manifest.values()))
        texts = set().union(*(set(split["text_hashes"]) for split in manifest.values()))
        identifiers = set().union(*(set(split["case_ids"]) for split in manifest.values()))
        if any(row["case_id"] in identifiers or digest(row["group_id"]) in groups or digest(normalized_text(row["text"])) in texts for row in rows):
            raise ValueError("Holdout overlaps a prior training, validation OR inspected test partition")
    return {**checks, "checked_model_directories": [str(model) for model in models],
            "exact_and_declared_group_overlap": 0,
            "note": "Human source/semantic independence and adequate class/language coverage still require review."}


def threshold_report(model: Path, output: Path) -> dict:
    metadata = json.loads((model / "metadata.json").read_text(encoding="utf-8"))
    predictions = json.loads((model / "validation_predictions.json").read_text(encoding="utf-8"))
    manifest = json.loads((model / "split_manifest.json").read_text(encoding="utf-8"))["validation"]
    if predictions["split"] != "validation" or predictions["model_version"] != metadata["version"] or predictions["labels"] != list(LABELS):
        raise ValueError("Threshold analysis must use this model's validation predictions")
    rows = predictions["rows"]
    if (predictions["dataset_fingerprint"] != manifest["summary"]["dataset_fingerprint"]
            or sorted(row["case_id"] for row in rows) != sorted(manifest["case_ids"])
            or any(row["label"] not in LABELS or row["language"] not in LANGUAGES for row in rows)):
        raise ValueError("Validation prediction records do not match the frozen split")
    # prediction_metrics fingerprints input columns; retained prediction exports do not include private text.
    metric_rows = [{**dict.fromkeys(COLUMNS, ""), **row} for row in rows]
    probabilities = [row["probabilities"] for row in rows]
    scores = []
    for threshold in (.25, .35, .45, .55, .65, .75, .85, .95):
        metrics = prediction_metrics(metric_rows, probabilities, threshold)
        scores.append({key: metrics[key] for key in ("review_threshold", "coverage", "accepted_accuracy",
                      "high_risk_undertriage_count", "high_risk_support", "high_risk_review_rate", "per_language")})
    output = _prepare_output(output)
    _write_json(output / "threshold_analysis.json", {
        "model_version": metadata["version"], "source_split": "validation", "count": len(rows),
        "all_data_reviewed": metadata["all_data_reviewed"], "thresholds": scores,
        "confidence_calibrated": False, "approved_for_deployment": False,
        "note": "Diagnostic sweep only; no threshold is auto-selected and model/config files are unchanged. Calibration needs independent reviewed examples."})
    return {"output": str(output), "source_split": "validation", "approved_for_deployment": False}


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    for name in ("audit", "worksheet", "import-review", "freeze", "check-holdout"):
        sub = commands.add_parser(name)
        sub.add_argument("--data", type=Path, default=DEFAULT_DATA)
        if name not in ("audit", "check-holdout"):
            sub.add_argument("--output", type=Path, required=True)
        if name == "import-review":
            sub.add_argument("--worksheet", type=Path, required=True)
        if name == "freeze":
            sub.add_argument("--allow-draft", action="store_true")
            sub.add_argument("--seed", type=int, default=42)
        if name == "check-holdout":
            sub.add_argument("--exclude-model", type=Path, action="append", required=True)
    sub = commands.add_parser("thresholds")
    sub.add_argument("--model", type=Path, required=True)
    sub.add_argument("--output", type=Path, required=True)
    args = parser.parse_args(argv)
    try:
        if args.command == "thresholds":
            result = threshold_report(args.model, args.output)
        else:
            rows = load_dataset(args.data)
            if args.command == "audit":
                result = audit(rows)
            elif args.command == "check-holdout":
                result = check_fresh_holdout(rows, args.exclude_model)
            elif args.command == "worksheet":
                result = export_worksheet(rows, args.output)
            elif args.command == "import-review":
                reviewed = import_worksheet(rows, args.worksheet)
                write_csv(args.output, reviewed)
                result = dataset_summary(load_dataset(args.output))
            else:
                result = freeze(rows, args.output, args.allow_draft, args.seed)
        print(json.dumps(result, ensure_ascii=False, indent=2, allow_nan=False))
        return 0
    except (ValueError, OSError, KeyError) as error:
        parser.exit(2, f"error: {error}\n")


if __name__ == "__main__":
    raise SystemExit(main())
