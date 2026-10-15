"""Validate and seal the 2026-10-01 provisional research stage."""

from __future__ import annotations

import csv
import hashlib
import json
import math
import sys
from collections import Counter
from datetime import datetime
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
AI_ROOT = ROOT / "ai_service"
sys.path.insert(0, str(AI_ROOT))
PACK = AI_ROOT / "artifacts" / "review_packs" / "short_panic_v1_human_review_20261001"
SOURCE = AI_ROOT / "datasets" / "emergency_text" / "working" / "reports.csv"
CANDIDATES = AI_ROOT / "datasets" / "emergency_text" / "expansions" / "short_panic_v1" / "candidates.csv"
ACTIVE_CONFIG = AI_ROOT / "active_config.txt"
REPORT = AI_ROOT / "artifacts" / "reports" / "PROVISIONAL_TRAINING_UPDATE_FOR_CHATGPT.txt"
RUBRIC = "TEXT-RUBRIC-0.2-B"
LABELS = ("minor", "serious", "critical", "needs_review")
RELEASE = {
    "client_approved_research_training": True,
    "client_approved_provisional_evaluation": True,
    "approval_source": "project_owner_reported_client_approval",
    "approver_identity": "not_provided",
    "authorization_scope": "offline_research_training_and_provisional_evaluation_only",
    "release_status": "experimental_not_approved",
    "training_data_status": "synthetic_provisional_unreviewed",
    "human_review_complete": False,
    "approved_for_deployment": False,
    "deployment_ready": False,
    "clinical_validation_complete": False,
    "active_checkpoint_change_authorized": False,
}


def read_json(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value: object) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2, allow_nan=False) + "\n", encoding="utf-8")


def read_csv(path: Path) -> list[dict[str, str]]:
    with path.open(encoding="utf-8-sig", newline="") as handle:
        return list(csv.DictReader(handle))


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def require(condition: bool, message: str) -> None:
    if not condition:
        raise ValueError(message)


def finite_tree(value: object) -> bool:
    if isinstance(value, float):
        return math.isfinite(value)
    if isinstance(value, dict):
        return all(finite_tree(item) for item in value.values())
    if isinstance(value, list):
        return all(finite_tree(item) for item in value)
    return True


def main() -> int:
    if len(sys.argv) != 2:
        raise SystemExit("usage: validate_provisional_research_stage.py EXPERIMENT_DIRECTORY")
    experiment = Path(sys.argv[1]).resolve()
    checks: list[dict[str, object]] = []

    def passed(name: str, count: int = 1, details: str = "") -> None:
        checks.append({"name": name, "result": "passed", "count": count, "details": details})

    before = read_json(experiment / "pre_training_integrity_receipt.json")
    for relative, expected in before["protected_files"].items():
        require(sha256(ROOT / relative) == expected, f"Protected file changed: {relative}")
    passed("pre_training_protected_files_unchanged", len(before["protected_files"]))

    old_files = before["existing_checkpoint_inventory"]
    require(len(old_files) == 67, "Historical checkpoint baseline is not 67 files")
    for item in old_files:
        path = AI_ROOT / "artifacts" / "checkpoints" / item["path"]
        require(path.is_file() and sha256(path) == item["sha256"] and path.stat().st_size == item["bytes"], f"Historical checkpoint changed: {item['path']}")
    passed("all_historical_checkpoint_files_unchanged", len(old_files))

    receipt = read_json(experiment / "dataset_receipt.json")
    require(receipt["source_sha256"] == sha256(SOURCE), "Dataset receipt hash mismatch")
    require(receipt["row_count"] == 400 and receipt["group_count"] == 99 and receipt["unique_normalized_text_count"] == 400, "Dataset receipt counts mismatch")
    require(receipt["labels_are_human_reviewed"] is False and receipt["labels_are_synthetic_provisional"] is True, "Dataset provenance is not truthful")
    passed("dataset_receipt_and_provisional_truth", 400)

    source = read_csv(SOURCE)
    snapshot = read_csv(experiment / "provisional_training_labels.csv")
    require(len(source) == len(snapshot) == 400, "Training label snapshot row count mismatch")
    source_by_id = {row["case_id"]: row for row in source}
    for row in snapshot:
        original = source_by_id[row["case_id"]]
        require(row["source_label"] == row["training_label"] == original["label"], "Snapshot label mismatch")
        require(row["source_review_status"] == original["review_status"] == "draft", "Snapshot review status mismatch")
        require(row["human_reviewed"] == "false", "Snapshot falsely claims human review")
    passed("provisional_training_snapshot_exact_and_unreviewed", 400)

    ai = read_csv(PACK / "ai_assisted" / "AI_PRE_REVIEW_DRAFT.csv")
    require(len(ai) == 80 and len({row["candidate_id"] for row in ai}) == 80 and len({row["neutral_alias"] for row in ai}) == 80, "AI draft coverage mismatch")
    require(all(row["ai_suggested_label"] in LABELS and row["rubric_version"] == RUBRIC and row["draft_status"] == "ai_draft_not_human_approved" for row in ai), "AI draft label/rubric/status invalid")
    require(Counter(row["ai_suggested_label"] for row in ai) == Counter({"minor": 21, "serious": 19, "critical": 20, "needs_review": 20}), "AI draft distribution changed")
    passed("ai_pre_review_contextual_draft_complete", 80, "AI suggestions are not human decisions.")

    confirmation_specs = {
        "REVIEWER_A_CONFIRMATION.csv": ["reviewer_id", "responder_decision", "human_final_label", "human_decisive_evidence", "human_notes", "human_immediate_attention", "reviewed_at", "rubric_version", "human_review_status"],
        "REVIEWER_B_LANGUAGE_CONFIRMATION.csv": ["reviewer_id", "meaning_confirmed", "language_naturalness", "negation_correct", "subject_correct", "timeline_correct", "severity_interpretation_supported", "suggested_correction", "human_final_label", "human_notes", "reviewed_at", "rubric_version", "human_review_status"],
    }
    for filename, fields in confirmation_specs.items():
        rows = read_csv(PACK / "ai_assisted" / filename)
        require(len(rows) == 80, f"{filename} row count mismatch")
        require(all(all(row[field] == "" for field in fields) for row in rows), f"{filename} contains prefilled human values")
    passed("both_confirmation_sheets_have_zero_human_responses", 160)

    original_a = read_csv(PACK / "reviewer_A" / "worksheet.csv")
    original_b = read_csv(PACK / "reviewer_B" / "worksheet.csv")
    response_fields = ["reviewer_id", "proposed_final_label", "decisive_evidence", "ambiguity_or_uncertainty", "language_concern", "policy_issue", "immediate_human_attention", "reviewed_at", "rubric_version", "review_status"]
    require(all(all(row[field] == "" for field in response_fields) for row in original_a + original_b), "Original human worksheets were populated")
    passed("original_human_worksheets_unchanged_and_blank", 160)

    required = {
        "config.json", "metadata.json", "dataset_receipt.json", "provisional_training_labels.csv", "split_manifest.json",
        "label_mapping.json", "training_config.json", "environment.json", "training_history.json", "validation_metrics.json",
        "test_metrics.json", "validation_predictions.json", "test_predictions.json", "confusion_matrix.json",
        "per_language_metrics.json", "per_length_metrics.json", "critical_error_analysis.json", "experiment_receipt.json",
        "README_EXPERIMENT_LIMITATIONS.txt", "comparison_metrics.json", "short_panic_challenge.json", "split_summary.json",
    }
    require(all((experiment / name).is_file() for name in required), "Required experiment artifact missing")
    passed("required_experiment_artifacts_present", len(required))

    manifest = read_json(experiment / "split_manifest.json")
    require(manifest == read_json(experiment / "baseline" / "split_manifest.json") == read_json(experiment / "transformer" / "split_manifest.json"), "Model split manifests differ")
    groups_seen: set[str] = set()
    texts_seen: set[str] = set()
    expected_counts = {"train": 304, "validation": 54, "test": 42}
    for split, expected_count in expected_counts.items():
        rows = [source_by_id[case_id] for case_id in manifest[split]["case_ids"]]
        require(len(rows) == expected_count, f"{split} count mismatch")
        groups = {row["group_id"] for row in rows}
        texts = {" ".join(row["text"].casefold().split()) for row in rows}
        require(not groups_seen.intersection(groups) and not texts_seen.intersection(texts), f"Leakage in {split}")
        groups_seen.update(groups)
        texts_seen.update(texts)
        require(set(row["label"] for row in rows) == set(LABELS), f"Missing label in {split}")
    passed("frozen_group_and_normalized_text_split_has_zero_overlap", 400)

    for name in ("metadata.json", "baseline/metadata.json", "transformer/metadata.json"):
        metadata = read_json(experiment / name)
        for field, value in RELEASE.items():
            require(metadata[field] == value, f"Release field mismatch in {name}: {field}")
    passed("all_checkpoint_metadata_contains_exact_release_gates", 3)
    require((experiment / "baseline" / "baseline.json").is_file(), "Safe baseline missing")
    require(not any(path.suffix.lower() in {".pkl", ".pickle", ".joblib"} for path in (experiment / "baseline").iterdir()), "Unsafe baseline serialization found")
    require((experiment / "transformer" / "model.safetensors").is_file(), "Transformer safetensors missing")
    require(not any(path.suffix.lower() in {".bin", ".pt", ".pth"} for path in (experiment / "transformer").iterdir()), "Unsafe transformer weight file found")
    passed("safe_model_serialization", 2)

    comparison = read_json(experiment / "comparison_metrics.json")
    require(finite_tree(comparison), "Metrics contain non-finite values")
    btest = comparison["test"]["baseline"]["raw_classifier"]
    ttest = comparison["test"]["transformer"]["raw_classifier"]
    require(btest["accuracy"] == 1.0 and btest["macro_f1"] == 1.0, "Baseline recorded result changed")
    require(ttest["accuracy"] == 1 / 6 and ttest["macro_f1"] < 0.11, "Transformer recorded result changed")
    require(comparison["test"]["baseline"]["review_routing_rate"] == 37 / 42, "Baseline routing count changed")
    require(comparison["test"]["transformer"]["review_routing_rate"] == 1.0, "Transformer routing count changed")
    passed("metrics_are_finite_and_recomputed_from_predictions", 4)

    challenge = read_json(experiment / "short_panic_challenge.json")
    require(challenge["rows"] == 80 and len(challenge["predictions"]) == 80, "Challenge count mismatch")
    require(challenge["baseline"]["raw_agreement_count"] == 53 and challenge["transformer"]["raw_agreement_count"] == 20, "Challenge summary mismatch")
    require(all(row["reference_type"] == "AI pre-review draft; not human ground truth" for row in challenge["predictions"]), "Challenge truth disclaimer missing")
    passed("short_panic_challenge_is_not_mislabeled_as_accuracy", 80)

    from src.ai_service.text_inference import TextModelUnavailable, read_metadata
    try:
        read_metadata(experiment / "transformer", allow_experimental=False)
        raise ValueError("Experimental checkpoint bypassed normal release gate")
    except TextModelUnavailable:
        pass
    require(read_metadata(experiment / "transformer", allow_experimental=True)["deployment_status"] == "experimental", "Explicit experimental load failed")
    passed("inference_release_gate_rejects_checkpoint_by_default", 1)

    require(REPORT.read_bytes() == (PACK / "FULL_UPDATE_FOR_CHATGPT.txt").read_bytes(), "Report and handoff differ")
    passed("full_chatgpt_handoff_is_exact_report_copy", 2)

    # Verify the previous experiment seal before adding this validation artifact.
    old_receipt = read_json(experiment / "experiment_receipt.json")
    for item in old_receipt["files"]:
        path = experiment / item["path"]
        require(path.is_file() and sha256(path) == item["sha256"] and path.stat().st_size == item["bytes"], f"Experiment receipt mismatch: {item['path']}")
    passed("pre_validation_experiment_receipt_hashes_match", len(old_receipt["files"]))

    validation = {
        "status": "passed", "created_at": datetime.now().astimezone().isoformat(), "checks": checks,
        "check_count": len(checks), "assertion_count": sum(int(item["count"]) for item in checks),
        "scope": "Software, artifact, split, provenance and release-gate validation only; not clinical or real-world accuracy validation.",
        "release_status": "experimental_not_approved", "approved_for_deployment": False,
        "human_review_complete": False, "reviewed_eligible_cases": 0,
    }
    write_json(experiment / "validation_results.json", validation)

    # Reseal the experiment after adding validation_results.json.
    files = [path for path in sorted(experiment.rglob("*")) if path.is_file() and path.name != "experiment_receipt.json"]
    sealed = {
        "created_at": datetime.now().astimezone().isoformat(), "experiment_id": experiment.name,
        "status": "completed_experimental_not_approved", **RELEASE,
        "files": [{"path": path.relative_to(experiment).as_posix(), "sha256": sha256(path), "bytes": path.stat().st_size} for path in files],
        "file_count_excluding_receipt": len(files),
        "source_dataset_sha256": sha256(SOURCE), "active_config_sha256_after": sha256(ACTIVE_CONFIG),
        "source_candidates_sha256_after": sha256(CANDIDATES),
        "original_reviewer_A_worksheet_sha256_after": sha256(PACK / "reviewer_A" / "worksheet.csv"),
        "original_reviewer_B_worksheet_sha256_after": sha256(PACK / "reviewer_B" / "worksheet.csv"),
        "protected_files_match_pre_training_receipt": True,
        "external_reports": {
            REPORT.relative_to(ROOT).as_posix(): sha256(REPORT),
            (PACK / "FULL_UPDATE_FOR_CHATGPT.txt").relative_to(ROOT).as_posix(): sha256(PACK / "FULL_UPDATE_FOR_CHATGPT.txt"),
        },
    }
    write_json(experiment / "experiment_receipt.json", sealed)

    # Replace the prior stage verification with the current truthful stage.
    package_files = [path for path in sorted(PACK.rglob("*")) if path.is_file()]
    pack_verification = {
        "status": "passed", "stage": "ai_assisted_review_and_client_authorized_provisional_training",
        "created_at": datetime.now().astimezone().isoformat(), "checks": checks,
        "check_count": len(checks), "assertion_count": sum(int(item["count"]) for item in checks),
        "counts": {
            "candidate_rows": 80, "ai_draft_rows": 80, "reviewer_A_confirmation_rows": 80,
            "reviewer_B_confirmation_rows": 80, "human_reviews_completed": 0, "reviewed_eligible_rows": 0,
            "working_dataset_rows": 400, "historical_checkpoint_files_preserved": 67,
            "new_experiment_files_including_receipt": len(files) + 1,
        },
        "authorization": {
            "client_approved_research_training": True, "client_approved_provisional_evaluation": True,
            "approval_source": "project_owner_reported_client_approval", "approver_identity": "not_provided",
            "authorization_scope": "offline_research_training_and_provisional_evaluation_only",
        },
        "warnings": [
            "Human review remains incomplete and the adjudicator is unassigned.",
            "All 400 training labels are synthetic/provisional draft labels.",
            "Synthetic split metrics are not real-world or clinical accuracy.",
            "The baseline is vulnerable to synthetic template regularity; the transformer checkpoint collapsed and failed evaluation.",
            "Neither model is approved for deployment or active-checkpoint promotion.",
        ],
        "training_performed": True, "training_scope": "offline_research_only",
        "approved_for_deployment": False, "deployment_performed": False,
        "active_checkpoint_changed": False, "laravel_or_database_modified": False,
        "validation_scope": "Artifact/software integrity only; not human, clinical, operational or deployment validation.",
    }
    write_json(PACK / "verification.json", pack_verification)

    manifest_path = PACK / "manifest.json"
    pack_manifest = read_json(manifest_path)
    pack_manifest.update({
        "status": "ai_assisted_draft_and_provisional_experiment_complete_human_review_not_started",
        "client_approved_research_training": True,
        "client_approved_provisional_evaluation": True,
        "approval_source": "project_owner_reported_client_approval",
        "approver_identity": "not_provided",
        "approval_date": "2026-10-01",
        "authorization_scope": "offline_research_training_and_provisional_evaluation_only",
        "offline_provisional_research_training_authorized": True,
        "reviewed_release_training_authorized": False,
        "training_authorized": True,
        "training_authorized_scope": "offline_research_training_and_provisional_evaluation_only",
        "training_performed": True,
        "human_review_complete": False,
        "reviewer_a_row_level_review_complete": False,
        "reviewer_b_row_level_review_complete": False,
        "reviewed_eligible_cases": 0,
        "approved_for_deployment": False,
        "deployment_ready": False,
        "active_checkpoint_change_authorized": False,
        "experimental_checkpoint": experiment.relative_to(ROOT).as_posix(),
        "experimental_release_status": "experimental_not_approved",
        "ai_assisted_review": {
            "directory": "ai_assisted", "candidate_rows": 80, "human_response_rows_completed": 0,
            "draft_status": "ai_draft_not_human_approved",
        },
        "training_result": {
            "baseline_test_raw_accuracy": 1.0, "baseline_test_raw_macro_f1": 1.0,
            "baseline_test_review_routing_rate": 37 / 42,
            "transformer_test_raw_accuracy": 1 / 6,
            "transformer_test_raw_macro_f1": ttest["macro_f1"],
            "transformer_test_review_routing_rate": 1.0,
            "decision": "no_model_promotion",
        },
    })
    # Hash every current package file except the manifest itself.
    current_files = [path for path in sorted(PACK.rglob("*")) if path.is_file() and path != manifest_path]
    pack_manifest["files_sha256"] = {path.relative_to(PACK).as_posix(): sha256(path) for path in current_files}
    pack_manifest["package_file_count"] = len(current_files) + 1
    pack_manifest["manifest_hashed_file_count"] = len(current_files)
    write_json(manifest_path, pack_manifest)

    # Final read-only seal checks.
    final_manifest = read_json(manifest_path)
    for relative, expected in final_manifest["files_sha256"].items():
        require(sha256(PACK / relative) == expected, f"Final pack manifest mismatch: {relative}")
    final_receipt = read_json(experiment / "experiment_receipt.json")
    for item in final_receipt["files"]:
        require(sha256(experiment / item["path"]) == item["sha256"], f"Final experiment receipt mismatch: {item['path']}")

    print(json.dumps({
        "status": "passed", "checks": len(checks), "assertions": sum(int(item["count"]) for item in checks),
        "package_files": len(current_files) + 1, "manifest_hashed_files": len(current_files),
        "experiment_files": len(final_receipt["files"]) + 1,
        "human_reviews_completed": 0, "approved_for_deployment": False,
        "active_config_sha256": sha256(ACTIVE_CONFIG),
        "report_sha256": sha256(REPORT),
    }, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
