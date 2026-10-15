"""Reconcile the completed 850-row responder worksheet without duplicating cases.

This creates a new immutable-by-convention dataset version. It never edits the
canonical input dataset, the supplied worksheet, the active model, or config.
"""
from __future__ import annotations

import argparse
from collections import Counter
import csv
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import shutil
import sys
import unicodedata

ROOT = Path(__file__).resolve().parents[2]
AI_ROOT = ROOT / "ai_service"
sys.path.insert(0, str(AI_ROOT))

from src.ai_service.text_training import COLUMNS, LABELS, LANGUAGES, load_dataset, dataset_summary

CANONICAL = AI_ROOT / "datasets/emergency_text/reviewed/civilian_multilingual_850_humanconfirmed_20261006T141537+0800"
DEFAULT_REVIEW = Path(r"C:\Users\Christian\Downloads\RESPONDER_HUMAN_REVIEW_COMPLETED.csv")
DEFAULT_XLSX = Path(r"C:\Users\Christian\Downloads\RESPONDER_HUMAN_REVIEW_COMPLETED.xlsx")
DEFAULT_OUTPUT = AI_ROOT / "datasets/emergency_text/reviewed/civilian_multilingual_850_responder_reconciled_20261007"
FAMILY_AUDIT = AI_ROOT / "artifacts/reports/xlmr_850_humanconfirmed_fulltrain_20261006_1411/FAMILY_RECONCILIATION_REPORT.json"

EXPECTED_REVIEW_FIELDS = {
    "case_id", "scenario_family_id", "language", "original_text", "current_label",
    "AI_model_prediction", "AI_model_confidence", "word_count", "length_bucket",
    "human_decision", "human_corrected_label", "immediate_attention", "human_notes",
    "reviewer_id", "reviewed_at",
}


def file_sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def normalized_text(text: str) -> str:
    return " ".join(unicodedata.normalize("NFKC", text).casefold().split())


def read_csv(path: Path) -> tuple[list[str], list[dict[str, str]]]:
    with path.open(encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        if not reader.fieldnames:
            raise ValueError(f"Missing CSV header: {path}")
        rows = list(reader)
        if any(None in row or any(value is None for value in row.values()) for row in rows):
            raise ValueError(f"Malformed CSV row: {path}")
        return list(reader.fieldnames), rows


def write_csv(path: Path, fields: list[str], rows: list[dict[str, str]]) -> None:
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields, extrasaction="raise", lineterminator="\n")
        writer.writeheader()
        writer.writerows(rows)


def union_find(ids: set[str], pairs: list[tuple[str, str]]) -> dict[str, str]:
    parent = {item: item for item in ids}

    def find(item: str) -> str:
        while parent[item] != item:
            parent[item] = parent[parent[item]]
            item = parent[item]
        return item

    for left, right in pairs:
        if left not in parent or right not in parent:
            raise ValueError(f"Family audit references a case outside the reconciled dataset: {left}, {right}")
        a, b = find(left), find(right)
        if a != b:
            parent[max(a, b)] = min(a, b)
    return {item: find(item) for item in sorted(ids)}


def reconcile(review_path: Path, xlsx_path: Path, output: Path) -> dict:
    if output.exists():
        raise ValueError(f"Refusing to overwrite an existing dataset version: {output}")
    for required in (CANONICAL / "reports.csv", CANONICAL / "human_review_records.csv", CANONICAL / "provenance.csv", CANONICAL / "family_manifest.json", review_path, xlsx_path, FAMILY_AUDIT):
        if not required.is_file():
            raise FileNotFoundError(required)

    canonical_fields, canonical_rows = read_csv(CANONICAL / "reports.csv")
    canonical_human_fields, canonical_human = read_csv(CANONICAL / "human_review_records.csv")
    _, incoming = read_csv(review_path)
    if set(canonical_fields) != set(COLUMNS) or len(canonical_fields) != len(COLUMNS):
        raise ValueError("Canonical reports.csv schema does not match the training loader")
    if set(incoming[0]) != EXPECTED_REVIEW_FIELDS if incoming else True:
        raise ValueError("Responder worksheet schema does not match the expected 15-column template")
    if len(canonical_rows) != 850 or len(incoming) != 850 or len(canonical_human) != 850:
        raise ValueError("Expected exactly 850 canonical rows, row-level records, and incoming review rows")

    canonical_by_id = {row["case_id"]: row for row in canonical_rows}
    human_by_id = {row["case_id"]: row for row in canonical_human}
    incoming_by_id = {row["case_id"]: row for row in incoming}
    if any(len(mapping) != 850 for mapping in (canonical_by_id, human_by_id, incoming_by_id)):
        raise ValueError("Duplicate case IDs found")
    if set(canonical_by_id) != set(human_by_id) or set(canonical_by_id) != set(incoming_by_id):
        raise ValueError("Case ID sets differ; no rows were reconciled")

    row_checks = Counter()
    mismatches: list[dict] = []
    for case_id, base in canonical_by_id.items():
        review = incoming_by_id[case_id]
        prior = human_by_id[case_id]
        pairs = {
            "language": (base["language"], review["language"]),
            "normalized_text": (normalized_text(base["text"]), normalized_text(review["original_text"])),
            "scenario_family_id": (base["group_id"], review["scenario_family_id"]),
            "current_label": (base["label"], review["current_label"]),
            "prior_reviewed_label": (prior["reviewed_label"], review["human_corrected_label"]),
        }
        for key, (left, right) in pairs.items():
            row_checks[f"{key}_match" if left == right else f"{key}_mismatch"] += 1
            if left != right:
                mismatches.append({"case_id": case_id, "field": key, "canonical": left, "incoming": right})
        label = review["human_corrected_label"]
        if label not in LABELS:
            mismatches.append({"case_id": case_id, "field": "human_corrected_label", "incoming": label})
        if review["human_decision"] not in {"confirm", "override_ai"}:
            mismatches.append({"case_id": case_id, "field": "human_decision", "incoming": review["human_decision"]})
        if not review["reviewer_id"].strip() or not review["reviewed_at"].strip():
            mismatches.append({"case_id": case_id, "field": "review_attribution", "incoming": "missing reviewer or timestamp"})
        if review["human_decision"] == "confirm" and review["current_label"] != label:
            mismatches.append({"case_id": case_id, "field": "confirm_label_consistency", "incoming": [review["current_label"], label]})
        if review["human_decision"] == "override_ai" and (not review["AI_model_prediction"] or review["AI_model_prediction"] == label):
            mismatches.append({"case_id": case_id, "field": "override_ai_consistency", "incoming": [review["AI_model_prediction"], label]})
        if review["AI_model_prediction"] and review["AI_model_prediction"] not in LABELS:
            mismatches.append({"case_id": case_id, "field": "AI_model_prediction", "incoming": review["AI_model_prediction"]})
        if review["AI_model_confidence"]:
            try:
                confidence = float(review["AI_model_confidence"])
                if not 0 <= confidence <= 1:
                    raise ValueError
            except ValueError:
                mismatches.append({"case_id": case_id, "field": "AI_model_confidence", "incoming": review["AI_model_confidence"]})

    if mismatches:
        raise ValueError("Reconciliation failed; see stdout audit diagnostics before retrying: " + json.dumps(mismatches[:20], ensure_ascii=False))

    audit = json.loads(FAMILY_AUDIT.read_text(encoding="utf-8"))
    id_to_row = {row["case_id"]: row for row in canonical_rows}
    conservative_pairs = []
    for item in audit["pair_recommendations"]:
        if item["recommendation"] == "SAME_SCENARIO_FAMILY":
            left, right = item["case_a"], item["case_b"]
            if id_to_row[left]["label"] != id_to_row[right]["label"]:
                raise ValueError(f"Conservative leakage pair has conflicting labels: {left}, {right}")
            conservative_pairs.append((left, right))
    effective = union_find(set(canonical_by_id), conservative_pairs)

    # Create the new version only after all input reconciliation checks pass.
    output.mkdir(parents=True)
    reports = []
    for case_id in sorted(canonical_by_id):
        row = dict(canonical_by_id[case_id])
        review = incoming_by_id[case_id]
        row["group_id"] = "SPLIT-" + hashlib.sha256(effective[case_id].encode()).hexdigest()[:16]
        row["review_status"] = "approved"
        row["reviewer"] = review["reviewer_id"]
        row["notes"] = ("Responder worksheet reconciled; original synthetic-origin text retained. "
                        f"Decision={review['human_decision']}; see responder_review_reconciled.csv for supplied rationale and provenance.")
        reports.append(row)
    write_csv(output / "reports.csv", canonical_fields, reports)

    merged_fields = list(canonical_human_fields) + [
        "latest_review_decision", "latest_reviewed_label", "latest_immediate_attention",
        "latest_reviewer_id", "latest_reviewed_at", "latest_review_notes",
        "AI_model_prediction", "AI_model_confidence", "review_time_interpretation",
    ]
    merged = []
    for case_id in sorted(canonical_by_id):
        prior = dict(human_by_id[case_id])
        review = incoming_by_id[case_id]
        prior.update({
            "latest_review_decision": review["human_decision"],
            "latest_reviewed_label": review["human_corrected_label"],
            "latest_immediate_attention": review["immediate_attention"],
            "latest_reviewer_id": review["reviewer_id"],
            "latest_reviewed_at": review["reviewed_at"],
            "latest_review_notes": review["human_notes"],
            "AI_model_prediction": review["AI_model_prediction"],
            "AI_model_confidence": review["AI_model_confidence"],
            "review_time_interpretation": "batch timestamp supplied identically for all rows; not treated as independently verified per-row timing",
        })
        merged.append(prior)
    write_csv(output / "human_review_records.csv", merged_fields, merged)
    write_csv(output / "responder_review_reconciled.csv", list(incoming[0]), [incoming_by_id[key] for key in sorted(incoming_by_id)])

    provenance_fields, provenance_rows = read_csv(CANONICAL / "provenance.csv")
    prov_by_id = {row["case_id"]: dict(row) for row in provenance_rows}
    for row in reports:
        case_id = row["case_id"]
        prov = prov_by_id.get(case_id)
        if prov is not None:
            prov["training_group_id"] = row["group_id"]
            prov["scenario_family_id_source"] = incoming_by_id[case_id]["scenario_family_id"]
            prov["latest_review_decision"] = incoming_by_id[case_id]["human_decision"]
            prov["latest_review_label"] = incoming_by_id[case_id]["human_corrected_label"]
            prov["latest_reviewer_id"] = incoming_by_id[case_id]["reviewer_id"]
    if len(prov_by_id) == len(reports):
        expanded = provenance_fields + [x for x in ("training_group_id", "scenario_family_id_source", "latest_review_decision", "latest_review_label", "latest_reviewer_id") if x not in provenance_fields]
        write_csv(output / "provenance.csv", expanded, [prov_by_id[key] for key in sorted(prov_by_id)])
    else:
        shutil.copy2(CANONICAL / "provenance.csv", output / "provenance.csv")

    # Preserve both supplied review files and canonical evidence as exact source copies.
    shutil.copy2(review_path, output / "SOURCE_RESPONDER_REVIEW.csv")
    shutil.copy2(xlsx_path, output / "SOURCE_RESPONDER_REVIEW.xlsx")
    shutil.copy2(CANONICAL / "family_manifest.json", output / "SOURCE_FAMILY_MANIFEST.json")
    family_guard = {
        "status": "SPLIT_LEAKAGE_GUARD_ONLY_NOT_FINAL_FAMILY_ADJUDICATION",
        "source": str(FAMILY_AUDIT.relative_to(ROOT)),
        "recommendations_union_count": len(conservative_pairs),
        "recommendations_union_case_pairs": [list(pair) for pair in conservative_pairs],
        "statement": "The source scenario_family_id remains preserved. The loader group_id is a deterministic split-only component that conservatively co-locates audit pairs marked SAME_SCENARIO_FAMILY; it does not assert a final family relationship.",
    }
    (output / "SPLIT_LEAKAGE_GUARD.json").write_text(json.dumps(family_guard, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    # Validate the exact training schema, one row per case, labels and group consistency.
    selected = load_dataset(output / "reports.csv")
    summary = dataset_summary(selected)
    if len(selected) != 850 or len({row["case_id"] for row in selected}) != 850:
        raise ValueError("Training-loader validation did not retain 850 unique cases")

    label_counts = dict(Counter(row["label"] for row in reports))
    language_counts = dict(Counter(row["language"] for row in reports))
    decision_counts = dict(Counter(row["human_decision"] for row in incoming))
    prediction_count = sum(bool(row["AI_model_prediction"]) for row in incoming)
    timestamp_counts = Counter(row["reviewed_at"] for row in incoming)
    receipt = {
        "dataset_version": "civilian_multilingual_850_responder_reconciled_20261007",
        "created_at_utc": datetime.now(timezone.utc).isoformat(),
        "source_canonical_dataset": str(CANONICAL.relative_to(ROOT)),
        "source_review_csv_sha256": file_sha256(review_path),
        "source_review_xlsx_sha256": file_sha256(xlsx_path),
        "canonical_reports_sha256": file_sha256(CANONICAL / "reports.csv"),
        "canonical_human_review_records_sha256": file_sha256(CANONICAL / "human_review_records.csv"),
        "rows": len(reports),
        "unique_case_ids": len(canonical_by_id),
        "duplicate_case_ids": 0,
        "added_training_rows": 0,
        "label_changes_vs_canonical": 0,
        "reviewer_decisions": decision_counts,
        "labels": label_counts,
        "languages": language_counts,
        "AI_prediction_present_rows": prediction_count,
        "AI_prediction_missing_rows": len(incoming) - prediction_count,
        "timestamp_values": dict(timestamp_counts),
        "reviewer_ids": dict(Counter(row["reviewer_id"] for row in incoming)),
        "matching_checks": dict(row_checks),
        "training_dataset": summary,
        "effective_split_group_count": len({row["group_id"] for row in reports}),
        "family_audit_same_scenario_guard_pairs": len(conservative_pairs),
        "attribution_limitations": [
            "The user attests that the worksheet was completed by a real responder; the supplied reviewer_id string is preserved exactly and was not independently identity-verified.",
            "The supplied reviewed_at value is identical for all 850 rows and is preserved as a supplied batch timestamp, not verified as per-row review time.",
            "All source descriptions remain synthetic-origin; responder review does not make them real incident reports or establish real-world representation.",
            "766 rows have no model prediction or confidence, so those rows cannot contribute to a measured AI correctness or override comparison.",
            "The 10 override_ai decisions compare the responder label against the supplied nonblank model prediction; labels match the canonical label on all 850 rows.",
        ],
        "active_model_changed": False,
        "deployment_approved": False,
        "training_performed": False,
    }
    (output / "RECONCILIATION_AUDIT.json").write_text(json.dumps(receipt, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    (output / "provenance.json").write_text(json.dumps({
        "dataset_version": receipt["dataset_version"],
        "generation_source": "synthetic_ai_generated",
        "review_evidence_source": "user-supplied completed responder worksheet; user attests reviewer is a real responder",
        "reviewer_ids_preserved_verbatim": receipt["reviewer_ids"],
        "source_case_count": 850,
        "added_training_rows": 0,
        "training_eligibility": "eligible for a candidate research run only; not deployment evidence",
        "independent_holdout_warning": "previous XLM-R experiment and related model-development workflows have already used these cases; any new within-dataset test result is not an independent external validation",
        "active_model_changed": False,
        "deployment_approved": False,
    }, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    (output / "README.md").write_text(
        "# Reconciled responder-reviewed 850-case dataset\n\n"
        "This version reconciles the supplied responder worksheet against the existing 850 canonical cases. It contains exactly one training row per existing case; it does not append a second 850 rows. The source canonical dataset and source worksheet are preserved unchanged.\n\n"
        "All 850 IDs, normalized texts, languages, scenario-family IDs, current labels, and the latest responder labels matched the canonical dataset. Therefore no label or text changed. The worksheet records 840 `confirm` decisions and 10 `override_ai` decisions; all 10 override labels disagree with their supplied AI prediction and match the existing canonical label. There are 84 prediction-bearing rows and 766 with no prediction/confidence.\n\n"
        "`reports.csv` is a training-loader-compatible copy. Its `group_id` is a deterministic split-only group that conservatively co-locates pairs marked `SAME_SCENARIO_FAMILY` by the prior family audit. Original scenario-family IDs remain in the row-level review/provenance files. This guard is not a final semantic family adjudication.\n\n"
        "The user attests that a real responder completed the worksheet. The supplied reviewer ID (`SIM_HUMAN_REVIEW_001`) is retained verbatim and was not independently identity-verified. All rows carry the same supplied timestamp, so it is treated as a batch timestamp. Source text remains synthetic-origin. See `RECONCILIATION_AUDIT.json` and `SPLIT_LEAKAGE_GUARD.json` for machine-readable evidence and limitations.\n\n"
        "No model was trained, activated, or deployed by this reconciliation step. Existing checkpoints, active configuration, and canonical dataset were not changed.\n",
        encoding="utf-8",
    )
    return receipt


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--review-csv", type=Path, default=DEFAULT_REVIEW)
    parser.add_argument("--review-xlsx", type=Path, default=DEFAULT_XLSX)
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT)
    args = parser.parse_args()
    print(json.dumps(reconcile(args.review_csv, args.review_xlsx, args.output), ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
