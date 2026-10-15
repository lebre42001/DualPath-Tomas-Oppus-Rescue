from __future__ import annotations

import hashlib
import json
import os
import platform
import shutil
import subprocess
from datetime import datetime
from pathlib import Path


ROOT = Path(__file__).resolve().parents[4]
RUN = Path(__file__).resolve().parent
RUN_ID = RUN.name.removeprefix("targeted_remediation_")
CHECKPOINT = ROOT / "ai_service/artifacts/checkpoints/text_xlmr_targeted_20261006T154647+0800/seed42"
SOURCE_REPORT = ROOT / "ai_service/artifacts/reports/xlmr_850_humanconfirmed_fulltrain_20261006_1411/model_data/model_development.csv"
SPLIT = ROOT / "ai_service/artifacts/reports/xlmr_850_humanconfirmed_fulltrain_20261006_1411/training_split_manifest.json"
ACTIVE = ROOT / "ai_service/active_config.txt"


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def write_json(name: str, obj: object) -> None:
    (RUN / name).write_text(json.dumps(obj, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")


def read_json(path: Path):
    return json.loads(path.read_text(encoding="utf-8-sig"))


def sha_record(paths: list[Path]) -> dict[str, str]:
    return {str(p.relative_to(ROOT)): sha256(p) for p in paths}


now = datetime.now().astimezone().isoformat()
metadata = read_json(CHECKPOINT / "metadata.json")
history = read_json(CHECKPOINT / "training_history.json")
test = read_json(RUN / "EVALUATIONS_WITH_PREDICTIONS/EXPOSED_TEST/evaluation_metrics.json")
cal = read_json(RUN / "EVALUATIONS_WITH_PREDICTIONS/CALIBRATION_DIAGNOSTIC/evaluation_metrics.json")
challenge = read_json(RUN / "EVALUATIONS_WITH_PREDICTIONS/EXPOSED_CHALLENGE/evaluation_metrics.json")
audit = read_json(RUN / "EVALUATION_AUDIT/EVALUATION_AUDIT.json")
protocol = read_json(RUN / "EXPERIMENT_PROTOCOL.json")
families = read_json(RUN / "FAMILY_EXPOSURE_AUDIT/FAMILY_EXPOSURE_AUDIT.json")
case_audit = read_json(RUN / "CASE_AUDIT/TARGETED_CASE_AUDIT.json")
pipeline = read_json(RUN / "PIPELINE_AUDIT/PIPELINE_DEFECT_AUDIT.json")
train_metrics = read_json(CHECKPOINT / "validation_metrics.json")
labels = ["minor", "serious", "critical", "needs_review"]
raw = test["raw_classifier"]
matrix = raw["confusion_matrix"]["rows_actual_columns_predicted"]
critical_i, serious_i = labels.index("critical"), labels.index("serious")
urgent_support = sum(matrix[critical_i]) + sum(matrix[serious_i])
raw_undertriage = sum(matrix[critical_i][labels.index(x)] for x in ("minor", "serious", "needs_review")) + sum(
    matrix[serious_i][labels.index(x)] for x in ("minor", "needs_review")
)
combined_detection = (
    sum(matrix[critical_i][labels.index(x)] for x in ("serious", "critical"))
    + sum(matrix[serious_i][labels.index(x)] for x in ("serious", "critical"))
) / urgent_support if urgent_support else None
threshold65 = audit["evaluations"]["EXPOSED_TEST"]["routed_at_threshold"]
threshold65_matrix = threshold65["confusion_matrix_actual_rows_predicted_columns"]
threshold65_critical_to_minor = threshold65_matrix[critical_i][labels.index("minor")]
per_language = audit["evaluations"]["EXPOSED_TEST"]["per_language"]
language_macro = {lang: block["raw"]["macro_f1"] for lang, block in per_language.items()}
checks = {
    "overall_macro_f1_ge_0_80": raw["macro_f1"] >= 0.80,
    "critical_recall_ge_0_95": raw["per_class"]["critical"]["recall"] >= 0.95,
    "serious_critical_detection_ge_0_90": combined_detection >= 0.90,
    "all_observed_languages_macro_f1_ge_0_75": all(v >= 0.75 for v in language_macro.values()),
    "zero_raw_urgent_undertriage": raw_undertriage == 0,
    "zero_automatic_critical_to_minor_at_0_65": threshold65_critical_to_minor == 0,
}

write_json("BEST_CHECKPOINT.json", {
    "status": "NO_ELIGIBLE_CHECKPOINT",
    "diagnostic_best_checkpoint": str(CHECKPOINT.relative_to(ROOT)),
    "seed": 42,
    "selection_rule": protocol["selection_rule"],
    "best_epoch": metadata.get("best_epoch"),
    "validation_metrics": {
        "macro_f1": train_metrics["raw_classifier"]["macro_f1"],
        "critical_recall": train_metrics["raw_classifier"]["per_class"]["critical"]["recall"],
        "serious_recall": train_metrics["raw_classifier"]["per_class"]["serious"]["recall"],
        "raw_high_risk_undertriage_count": train_metrics.get("raw_high_risk_undertriage_count"),
    },
    "deployment_eligible": False,
    "model_safetensors_sha256": sha256(CHECKPOINT / "model.safetensors"),
})
write_json("TRAINING_HISTORY.json", {
    "checkpoint": str(CHECKPOINT.relative_to(ROOT)),
    "history": history,
    "summary": metadata,
    "observed_wall_time_seconds": metadata.get("training_and_selection_seconds"),
    "elapsed_time_source": "training logs/metadata only; do not infer from file timestamps",
})
split_manifest = read_json(SPLIT)
write_json("SPLIT_MANIFEST.json", {
    "authoritative_frozen_manifest": str(SPLIT.relative_to(ROOT)),
    "sha256": sha256(SPLIT),
    "partitions": {name: {"rows": block["summary"]["rows"], "groups": block["summary"]["groups"], "fingerprint": block["summary"]["dataset_fingerprint"]} for name, block in split_manifest.items()},
    "calibration_and_challenge": "Separate externally evaluated partitions, recorded in EVALUATION_AUDIT and not used for fitting or checkpoint selection.",
    "exposure": "These held-out identities have prior project development exposure; regression/diagnostic only.",
})
write_json("GATE_RESULTS.json", {
    "evaluation_set": "EXPOSED_TEST",
    "exposure_status": "historically exposed synthetic-origin regression diagnostics; not fresh",
    "metric_source": "saved probabilities rejoined by case_id to the frozen test CSV; independent final QA reproduced these gate values",
    "raw_metrics": {
        "n": raw["count"], "macro_f1": raw["macro_f1"],
        "critical_recall": raw["per_class"]["critical"]["recall"],
        "critical_support": raw["per_class"]["critical"]["support"],
        "serious_recall": raw["per_class"]["serious"]["recall"],
        "serious_support": raw["per_class"]["serious"]["support"],
        "raw_urgent_undertriage_count": raw_undertriage,
        "raw_urgent_support": urgent_support,
        "combined_serious_critical_detection_recall": combined_detection,
        "per_language_macro_f1": language_macro,
        "threshold_0_65_critical_to_minor": threshold65_critical_to_minor,
    },
    "gates": [{"id": k, "result": "PASS" if v else "FAIL", "passed": v} for k, v in checks.items()],
    "passed_count": sum(checks.values()), "gate_count": len(checks),
    "research_acceptance": "NOT_ACCEPTABLE; diagnostic model only",
    "known_metric_issue": "Saved evaluation_metrics.json per_language fields disagree with case-ID recomputation; see PER_LANGUAGE_RECONCILIATION.json and FINAL_QA_AUDIT.json. Original artifacts were preserved.",
    "note": "Research gates are project criteria, not clinical standards; gate results do not establish operational safety.",
})
write_json("MODEL_COMPARISON.json", audit["existing_report_comparison"])
write_json("DATASET_RECEIPT.json", {
    "source": str(SOURCE_REPORT.relative_to(ROOT)),
    "source_sha256": sha256(SOURCE_REPORT),
    "rows": 724,
    "dataset_fingerprint": "8fe3c2fb7d79ba50175ab67026ffedac9b0b015913ba6c6b4deb9f91c152d282",
    "split_manifest": str(SPLIT.relative_to(ROOT)),
    "split_manifest_sha256": sha256(SPLIT),
    "partition_membership": {"train": 514, "validation": 126, "calibration": 83, "exposed_test": 84, "exposed_challenge": 43},
    "label_or_data_change": False,
    "training_data_intervention": "None; justified code-path optimizer/scheduler accounting intervention only.",
    "provenance": "source rows retain prior project-owner-attested verbal batch status; all synthetic-origin; no row-wise independent responder evidence supplied.",
    "historical_exposure": "All rows have prior project model-development exposure; held-out names are regression diagnostics only.",
})
write_json("PROVENANCE_RECEIPT.json", {
    "created_at_local": now,
    "owner_attestation": "OWNER-ATTESTED-850-FD0FB65CCB718A1D; project-owner-attested verbal batch; not independently corroborated direct responder testimony.",
    "source_file": "ai_service/datasets/emergency_text/experiments/civilian_multilingual_6000w_20261005/reports.csv",
    "source_sha256": "fd0fb65ccb718a1dee93fd315aa3a0e12cb5a8c2123bc08c8466e51c75fc7645",
    "derived_model_development_data_sha256": sha256(SOURCE_REPORT),
    "row_decisions_or_label_changes": 0,
    "ai_audit": {"agree": 769, "disagree": 38, "uncertain": 43, "non_agree": 81},
    "synthetic_origin": True,
    "human_language_fluency_validation": "not evidenced",
    "real_world_final_test": "REAL_WORLD_FINAL_TEST_NOT_AVAILABLE",
})
write_json("SOURCE_ACCESS_INVENTORY.json", {
    "historical_restricted_csv_count": 60,
    "existing_paths_found": 60,
    "readable_and_hashable": 0,
    "diagnostic": "Access denied/permission issue; no ACL/security changes performed.",
    "historical_scan_counts": {"prior_accessible_scan_a": 1732, "prior_accessible_scan_b": 2708, "reconciled": False},
    "current_rescan": {"paths": 76, "readable_paths": 16, "unique_content_hashes": 14, "parsed_rows_across_paths": 6724, "rows_after_hash_collapse": 6244, "nonblank_text_rows": 4974, "punctuation_normalized_keys": 1410},
    "detailed_evidence": "FAMILY_EXPOSURE_AUDIT/FAMILY_EXPOSURE_AUDIT.json and RECORDED_60_FILE_ACCESS_PROBES.csv",
})
write_json("CURRENT_EVIDENCE_VERIFICATION.json", {
    "verified_at_local": now,
    "hashes": sha_record([ACTIVE, SOURCE_REPORT, SPLIT, CHECKPOINT / "model.safetensors", ROOT / "ai_service/artifacts/checkpoints/text_xlmr_850_humanconfirmed_fulltrain_20261006_1411/model.safetensors"]),
    "active_selector_value": ACTIVE.read_text(encoding="utf-8").strip(),
    "active_selector_changed": False,
    "prior_rejected_checkpoint_unchanged": True,
    "git_worktree": "unavailable; repository root has no Git metadata",
    "training_gpu_process_running_at_completion": False,
    "production_deployment": False,
})
write_json("MANUAL_MODE_TEST_REPORT.json", {
    "status": "CODE_TESTS_PASS_WITH_LIMITATIONS",
    "laravel_focused": {"tests": 39, "assertions": 316, "result": "PASS", "filter": "TextSeverityIntegrationTest|DescriptionSeverityDashboardTest"},
    "laravel_mobile_auth_reports": {"tests": 76, "assertions": 951, "result": "PASS", "filter": "ApiMobileFlowTest|AuthAndReportFlowTest"},
    "ai_service_tests": {"tests": 21, "result": "PASS", "scope": "MANUAL_ONLY operation mode and text inference"},
    "frontend_build": {"command": "npm run build", "result": "PASS", "note": "stale caniuse-lite informational warning"},
    "python_syntax": "PASS",
    "render_yaml_assertions": "PASS",
    "not_executed": ["persistent database migration", "localhost runtime smoke test", "Docker build", "isolated staging", "public deployment"],
    "reasons": ["Docker executable unavailable; no isolated staging database/target configured; localhost MySQL/Web/AI/Reverb ports 3306/8000/8100/8080 were not listening; production deployment explicitly unauthorized."],
    "ai_status": "MANUAL_ONLY; text and image inference blocked at FastAPI as well as Laravel integration unless explicitly opted in outside this release.",
})

qa_path = RUN / "FINAL_QA_AUDIT.json"
qa_status = read_json(qa_path).get("status") if qa_path.is_file() else "in_progress"
agent_matrix = [
    {"agent": "target_case_audit", "workstream": "targeted severity/text-first case and human worksheet audit", "status": "complete", "output": "CASE_AUDIT/"},
    {"agent": "pipeline_defect_audit", "workstream": "training/inference implementation audit", "status": "complete", "output": "PIPELINE_AUDIT/"},
    {"agent": "family_exposure_audit", "workstream": "family linkage and historical source-access audit", "status": "complete", "output": "FAMILY_EXPOSURE_AUDIT/"},
    {"agent": "manual_mode_engineer", "workstream": "Laravel/FastAPI MANUAL_ONLY implementation and app tests", "status": "complete", "output": "RELEASE_MANUAL_ONLY.md; ROLLBACK.md; ai_service tests; Laravel tests"},
    {"agent": "evaluation_auditor", "workstream": "saved probability evaluation/calibration audit", "status": "complete", "output": "EVALUATION_AUDIT/"},
    {"agent": "qa_final_independent2", "workstream": "independent final artifact/code review", "status": "complete" if qa_path.is_file() else "in_progress", "output": "FINAL_QA_AUDIT.json"},
    {"agent": "root coordinator", "workstream": "integration, GPU experiment, handoff, final decision", "status": "in_progress", "output": "root run artifacts"},
]
write_json("AGENT_EXECUTION_MATRIX.json", {
    "native_agents_spawned_total": 6,
    "note": "Six native agents were spawned across the task. Role/workstream count differs from spawned-agent count because root is also a workstream owner.",
    "workstreams": agent_matrix,
    "gpu_concurrency": "one process at a time; one seed42 full-backbone GPU experiment was run",
})

req_statuses = {
    "EVIDENCE_RECONCILE": ["complete", "BASELINE_EVIDENCE.json; CURRENT_EVIDENCE_VERIFICATION.json; prior handoff backup"],
    "ATTESTATION_PRESERVE": ["complete", "PROVENANCE_RECEIPT.json; no labels rewritten or batch approval reset"],
    "TARGETED_UNION_9_PLUS_6": ["prepared_human_decisions_pending", "CASE_AUDIT/; deduplicated union of nine; no final responder decisions"],
    "PIPELINE_AUDIT": ["complete_with_limited_actionable_fix", "PIPELINE_AUDIT/; skip-aware AMP scheduler fix and telemetry implemented"],
    "FAMILY_EXPOSURE": ["complete_with_access_limitations", "FAMILY_EXPOSURE_AUDIT/; 60 restricted files unreadable; inventory counts unreconciled"],
    "EXPERIMENT_PROTOCOL": ["complete_frozen_before_training", "EXPERIMENT_PROTOCOL.json"],
    "FULL_BACKBONE_RETRAIN": ["complete_research_run", "TRAINING_HISTORY.json; GPU_PREFLIGHT.json; best diagnostic epoch 6"],
    "HUMAN_MANUAL_MODE": ["implemented_and_tested; staging_not_run", "MANUAL_MODE_TEST_REPORT.json; RELEASE_MANUAL_ONLY.md"],
    "TESTS_RELEASE_PACKAGE": ["source_bundle_verified; Docker_build_and_staging_not_run", "APPLICATION_RELEASE_RECEIPT.json; MANUAL_MODE_TEST_REPORT.json"],
    "INDEPENDENT_QA": [qa_status, "FINAL_QA_AUDIT.json; PASS_WITH_LIMITATIONS does not override failed model gates"],
}
state = read_json(RUN / "TASK_STATE.json")
state["active_model"] = "unchanged"
state["track_manual_only_release"] = "prepared; code tests pass; no staging/deploy"
state["track_ai_research"] = "bounded run complete; NO_ELIGIBLE_CHECKPOINT; research only"
state["production_deployment"] = "not performed"
state["requirements"] = [{"id": key, "status": value[0], "evidence": value[1]} for key, value in req_statuses.items()]
state["specialist_roles"] = agent_matrix
write_json("TASK_STATE.json", state)

app_receipt_path = ROOT / "ai_service/artifacts/packages/manual_only_release_source_20261006T154647+0800.receipt.json"
if app_receipt_path.is_file():
    app_receipt = read_json(app_receipt_path)
    write_json("APPLICATION_RELEASE_RECEIPT.json", app_receipt)
    manual_report = read_json(RUN / "MANUAL_MODE_TEST_REPORT.json")
    manual_report["source_release_bundle"] = app_receipt
    manual_report["build_verified"] = False
    manual_report["staging_verified"] = False
    write_json("MANUAL_MODE_TEST_REPORT.json", manual_report)

gates_text = "\n".join(f"{item['id']}: {item['result']}" for item in read_json(RUN / "GATE_RESULTS.json")["gates"])
command_log = f"""# Command and validation log (selected decisive commands)

Recorded at {now}; full command transcripts are in the audit-specific COMMAND_LOG files and agent reports.

AI research:
- GPU preflight: ai_service/.venv-xlmrt-fulltrain/Scripts/python.exe {RUN.relative_to(ROOT)}/GPU_PREFLIGHT.py (PASS; CUDA backward/update, 278,046,724 trainable)
- Full-backbone single seed: {RUN.relative_to(ROOT)}/run_seed42.py (completed 8 epochs; 260 optimizer updates; 4 AMP skips; scheduler advanced 260 times)
- Saved-probability evaluation: {RUN.relative_to(ROOT)}/run_evaluations.py and EVALUATION_AUDIT/audit_evaluations.py (no additional training; independent recomputation)
- Test gate results:\n{gates_text}
- Focused training unit tests: ai_service/.venv-xlmrt-fulltrain/Scripts/python.exe -m unittest discover -s ai_service/tests -p 'test_text_training*.py' (19 passed)

Application:
- Laravel php artisan test --filter='TextSeverityIntegrationTest|DescriptionSeverityDashboardTest' (39 tests, 316 assertions passed)
- Laravel php artisan test --filter='ApiMobileFlowTest|AuthAndReportFlowTest' (76 tests, 951 assertions passed)
- ai_service/.venv-text/Scripts/python.exe -m unittest tests.test_operation_mode tests.test_text_inference (21 passed)
- npm run build (passed; stale caniuse-lite informational warning)
- Python syntax and Render YAML assertions (passed)

Not run: Docker build (docker executable unavailable), persistent DB migration, localhost runtime smoke test, staging, public deployment, TF-IDF executable baseline (Windows Application Control block in prior attempts).
"""
(RUN / "COMMAND_LOG.txt").write_text(command_log, encoding="utf-8")

print(json.dumps({"run": str(RUN), "gate_results": checks, "model_sha256": sha256(CHECKPOINT / "model.safetensors"), "timestamp": now}, indent=2))
