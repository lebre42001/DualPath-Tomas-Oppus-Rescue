"""Seal the Excel confirmation stage and update the review-pack manifest."""

from __future__ import annotations

import hashlib
import json
import sys
import zipfile
from datetime import datetime
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
AI_ROOT = ROOT / "ai_service"
sys.path.insert(0, str(AI_ROOT / "tools"))
PACK = AI_ROOT / "artifacts" / "review_packs" / "short_panic_v1_human_review_20261001"
EXPERIMENT = AI_ROOT / "artifacts" / "checkpoints" / "text_provisional_synthetic_research_20261001_202001"
RUBRIC = "TEXT-RUBRIC-0.2-B"


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def read_json(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value: object) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2, allow_nan=False) + "\n", encoding="utf-8")


def require(value: bool, message: str) -> None:
    if not value:
        raise ValueError(message)


def main() -> int:
    before = read_json(PACK / "coordinator" / "excel_workflow_before.json")
    validation = read_json(PACK / "coordinator" / "EXCEL_WORKFLOW_VALIDATION.json")
    require(validation["status"] == "passed" and validation["assertion_count"] == 1027, "Workbook validation is incomplete")
    for relative, expected in before["source_files"].items():
        require(sha256(ROOT / relative) == expected, f"Source confirmation CSV changed: {relative}")
    for relative, expected in before["original_human_worksheets"].items():
        require(sha256(PACK / relative) == expected, f"Original human worksheet changed: {relative}")
    for relative, item in validation["workbooks"].items():
        path = ROOT / relative
        require(path.is_file() and sha256(path) == item["sha256"] and path.stat().st_size == item["bytes"], f"Workbook changed: {relative}")
        require(item["rows"] == 80 and item["human_answers_prefilled"] == 0 and item["submission_ready"] is False, "Workbook status is untruthful")
    require(not (PACK / "coordinator" / "submissions").exists(), "Submission output exists even though import was not authorized to run")
    workbook_paths = [PACK / "reviewer_A" / "REVIEWER_A_SHORT_PANIC.xlsx", PACK / "reviewer_B" / "REVIEWER_B_SHORT_PANIC.xlsx"]
    for path in workbook_paths:
        with zipfile.ZipFile(path) as archive:
            require(archive.testzip() is None, f"Corrupt XLSX entry in {path.name}")
            require(not any("vbaProject" in name or "ctrlProp" in name or "activeX" in name for name in archive.namelist()),
                    f"Macros or form controls found in {path.name}")
    from import_excel_human_confirmations import ReviewValidationError, reviewer_a_rows, reviewer_b_rows
    rejected = 0
    for function, path in zip((reviewer_a_rows, reviewer_b_rows), workbook_paths, strict=True):
        try:
            function(path)
        except ReviewValidationError:
            rejected += 1
    require(rejected == 2 and not (PACK / "coordinator" / "submissions").exists(), "Blank workbooks were not safely rejected")

    # Validate protected AI sources, active config and every pre-existing model
    # artifact using the immutable pre-training receipt from the prior stage.
    pre_training = read_json(EXPERIMENT / "pre_training_integrity_receipt.json")
    for relative, expected in pre_training["protected_files"].items():
        require(sha256(ROOT / relative) == expected, f"Protected project file changed: {relative}")
    for item in pre_training["existing_checkpoint_inventory"]:
        path = AI_ROOT / "artifacts" / "checkpoints" / item["path"]
        require(path.is_file() and sha256(path) == item["sha256"] and path.stat().st_size == item["bytes"], f"Historical checkpoint changed: {item['path']}")
    experiment_receipt = read_json(EXPERIMENT / "experiment_receipt.json")
    for item in experiment_receipt["files"]:
        path = EXPERIMENT / item["path"]
        require(path.is_file() and sha256(path) == item["sha256"] and path.stat().st_size == item["bytes"], f"Provisional experiment artifact changed: {item['path']}")

    checks = [
        {"name": "both_workbooks_pass_structural_and_content_validation", "result": "passed", "count": 1027},
        {"name": "source_confirmation_CSVs_unchanged", "result": "passed", "count": 2},
        {"name": "original_blinded_human_worksheets_unchanged", "result": "passed", "count": 2},
        {"name": "workbook_hashes_match_validation_report", "result": "passed", "count": 2},
        {"name": "zero_human_answers_and_both_not_ready", "result": "passed", "count": 162},
        {"name": "no_submission_directory_and_import_not_run", "result": "passed", "count": 1},
        {"name": "protected_sources_and_active_config_unchanged", "result": "passed", "count": len(pre_training["protected_files"])},
        {"name": "all_historical_checkpoint_files_unchanged", "result": "passed", "count": len(pre_training["existing_checkpoint_inventory"])},
        {"name": "prior_provisional_experiment_internal_files_unchanged", "result": "passed", "count": len(experiment_receipt["files"])},
        {"name": "import_script_created_but_not_executed", "result": "passed", "count": 1},
        {"name": "xlsx_archives_have_no_macros_form_controls_or_corrupt_entries", "result": "passed", "count": 2},
        {"name": "blank_workbooks_rejected_without_creating_submissions", "result": "passed", "count": 2},
    ]
    verification = {
        "status": "passed", "stage": "excel_human_confirmation_workflow_prepared",
        "created_at": datetime.now().astimezone().isoformat(), "rubric_version": RUBRIC,
        "checks": checks, "check_count": len(checks), "assertion_count": sum(item["count"] for item in checks),
        "workbooks": validation["workbooks"],
        "human_answers_prefilled": 0, "reviewer_A_rows": 80, "reviewer_B_rows": 80,
        "reviewer_A_row_level_review_complete": False, "reviewer_B_row_level_review_complete": False,
        "human_review_complete": False, "submission_ready": False, "adjudicator_assigned": False,
        "import_script": "ai_service/tools/import_excel_human_confirmations.py",
        "import_executed": False, "submission_directories_created": 0,
        "training_performed_this_stage": False, "datasets_modified": 0, "checkpoints_modified": 0,
        "active_checkpoint_changed": False, "laravel_or_database_modified": False, "deployment_performed": False,
        "warnings": [
            "The workbooks are blank review tools, not completed human submissions.",
            "Formula-derived IMPORT_DATA is not eligible for export until both reviewers complete every required field.",
            "Import later creates sealed submissions but does not complete adjudication or authorize training/deployment.",
        ],
    }
    write_json(PACK / "verification.json", verification)

    manifest_path = PACK / "manifest.json"
    manifest = read_json(manifest_path)
    manifest.update({
        "status": "excel_human_confirmation_workbooks_ready_human_review_not_started",
        "human_review_complete": False,
        "reviewer_a_row_level_review_complete": False,
        "reviewer_b_row_level_review_complete": False,
        "reviewed_eligible_cases": 0,
        "submission_ready": False,
        "adjudicator_assigned": False,
        "excel_human_confirmation_workflow": {
            "status": "prepared_not_submitted",
            "reviewer_A_workbook": "reviewer_A/REVIEWER_A_SHORT_PANIC.xlsx",
            "reviewer_B_workbook": "reviewer_B/REVIEWER_B_SHORT_PANIC.xlsx",
            "rows_per_workbook": 80,
            "human_answers_prefilled": 0,
            "submission_ready": False,
            "import_script": "ai_service/tools/import_excel_human_confirmations.py",
            "import_executed": False,
            "validation_report": "coordinator/EXCEL_WORKFLOW_VALIDATION.json",
            "entry_receipt": "coordinator/excel_workflow_before.json",
            "training_performed_this_stage": False,
        },
    })
    files = [path for path in sorted(PACK.rglob("*")) if path.is_file() and path != manifest_path]
    manifest["files_sha256"] = {path.relative_to(PACK).as_posix(): sha256(path) for path in files}
    manifest["package_file_count"] = len(files) + 1
    manifest["manifest_hashed_file_count"] = len(files)
    write_json(manifest_path, manifest)

    final = read_json(manifest_path)
    for relative, expected in final["files_sha256"].items():
        require(sha256(PACK / relative) == expected, f"Final package manifest mismatch: {relative}")
    print(json.dumps({
        "status": "passed", "checks": len(checks), "assertions": sum(item["count"] for item in checks),
        "package_files": final["package_file_count"], "manifest_hashed_files": len(final["files_sha256"]),
        "reviewer_A_rows": 80, "reviewer_B_rows": 80, "human_answers_prefilled": 0,
        "human_review_complete": False, "submission_ready": False, "import_executed": False,
    }, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
