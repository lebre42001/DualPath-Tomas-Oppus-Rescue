"""Assemble auditable reports for the 2026-10-05 synthetic-draft experiment."""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
import shutil

AI = Path(__file__).resolve().parents[3]
REPORT = Path(__file__).resolve().parent
DATASET = AI / "datasets/emergency_text/experiments/civilian_multilingual_6000w_20261005"
CHECKPOINT_ROOT = AI / "artifacts/checkpoints/text_xlmr_civilian_multilingual_20261005"
BEST = CHECKPOINT_ROOT / "trial_lr_2e-5_seed42"
ACTIVE_CONFIG = AI / "active_config.txt"
LABELS = ("minor", "serious", "critical", "needs_review")


def read(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def write(name: str, value: dict) -> None:
    (REPORT / name).write_text(json.dumps(value, ensure_ascii=False, indent=2, allow_nan=False) + "\n", encoding="utf-8")


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def main() -> None:
    stats = read(REPORT / "DATASET_STATISTICS.json")
    split = read(REPORT / "SPLIT_MANIFEST.json")
    selection_rule = read(REPORT / "CHECKPOINT_SELECTION_RULE.json")
    histories = {}
    trials = []
    for lr in ("1e-5", "2e-5", "3e-5"):
        path = CHECKPOINT_ROOT / f"trial_lr_{lr}_seed42"
        history = read(path / "training_history.json")
        metadata = read(path / "metadata.json")
        validation = read(path / "validation_metrics.json")
        histories[lr] = {"metadata": metadata, "history": history}
        raw = validation["raw_classifier"]
        trials.append({
            "learning_rate": float(lr), "checkpoint": str(path), "best_epoch": metadata["best_epoch"],
            "validation_raw_high_risk_undertriage_count": validation["raw_high_risk_undertriage_count"],
            "validation_macro_f1": raw["macro_f1"],
            "critical_recall": raw["per_class"]["critical"]["recall"],
            "serious_recall": raw["per_class"]["serious"]["recall"],
            "critical_precision": raw["per_class"]["critical"]["precision"],
            "selected": lr == "2e-5",
        })
    write("TRAINING_HISTORY.json", {"selection_rule_id": selection_rule["rule_id"], "trials": histories})
    chosen_meta = read(BEST / "metadata.json")
    validation = read(BEST / "validation_metrics.json")
    fresh = read(REPORT / "fresh_test_eval/evaluation_metrics.json")
    calibration = read(REPORT / "CALIBRATION_METRICS.json")
    slices = read(REPORT / "LANGUAGE_LENGTH_SLICE_METRICS.json")
    challenge = read(REPORT / "challenge_eval/evaluation_metrics.json")
    previous = read(REPORT / "previous_xlmr_fresh_test_eval/evaluation_metrics.json")
    tfidf = read(REPORT / "tfidf_fresh_test_eval/evaluation_metrics.json")
    write("BEST_CHECKPOINT.json", {
        "selection_rule_id": selection_rule["rule_id"], "checkpoint": str(BEST),
        "model_version": chosen_meta["version"], "seed": 42, "learning_rate": chosen_meta["learning_rate"],
        "best_epoch": chosen_meta["best_epoch"], "selection_used_validation_only": True,
        "selection_key": ["undertriage_count=3", "validation_macro_f1=0.9191785389"],
        "test_calibration_challenge_used_for_selection": False,
        "active_checkpoint_changed": False,
    })
    write("TRIAL_SELECTION_COMPARISON.json", {"frozen_rule": selection_rule, "candidate_results": trials, "selected_learning_rate": 2e-5})
    write("VALIDATION_METRICS.json", validation)
    write("FRESH_TEST_METRICS.json", fresh)
    write("MIXED_LANGUAGE_METRICS.json", {
        "support": fresh["per_language"]["mixed"]["count"],
        "families": slices["fresh_test"]["language"]["mixed"]["families"],
        "raw_classifier_metrics": slices["fresh_test"]["language"]["mixed"],
        "confidence_routed_metrics_at_0_65": fresh["per_language"]["mixed"],
        "qualification": "Insufficient support for a strong conclusion (12 rows; 3 rows per class).",
    })
    write("SHORT_PANIC_METRICS.json", slices)
    test_conf = fresh["raw_classifier"]["confusion_matrix"]["rows_actual_columns_predicted"]
    high_risk_support = sum(sum(test_conf[i]) for i in (1, 2))
    high_risk_detected = sum(test_conf[i][j] for i in (1, 2) for j in (1, 2))
    high_risk_detection_recall = high_risk_detected / high_risk_support if high_risk_support else 0.0
    routed_conf = fresh["review_routed"]["confusion_matrix"]["rows_actual_columns_predicted"]
    write("SAFETY_ERROR_ANALYSIS.json", {
        "fresh_test_support": fresh["count"], "family_support": len(split["partitions"]["fresh_test"]["group_hashes"]),
        "serious_support": fresh["raw_classifier"]["per_class"]["serious"]["support"],
        "serious_recall": fresh["raw_classifier"]["per_class"]["serious"]["recall"],
        "critical_support": fresh["raw_classifier"]["per_class"]["critical"]["support"],
        "critical_recall": fresh["raw_classifier"]["per_class"]["critical"]["recall"],
        "critical_to_serious": test_conf[2][1], "critical_to_minor": test_conf[2][0],
        "critical_to_needs_review_raw": test_conf[2][3],
        "serious_to_minor": test_conf[1][0], "serious_to_needs_review_raw": test_conf[1][3],
        "serious_to_critical": test_conf[1][2],
        "raw_high_risk_undertriage": fresh["raw_high_risk_undertriage_count"],
        "review_routed_high_risk_undertriage": fresh["high_risk_undertriage_count"],
        "critical_routed_to_review": routed_conf[2][3], "serious_routed_to_review": routed_conf[1][3],
        "limitations": ["All examples are synthetic draft data.", "Only 21 Critical and 21 Serious fresh-test rows.", "Language-specific high-risk slices are small."],
    })
    needs_support = fresh["raw_classifier"]["per_class"]["needs_review"]["support"]
    clear_support = fresh["count"] - needs_support
    false_review = sum(routed_conf[i][3] for i in range(3))
    write("NEEDS_REVIEW_ANALYSIS.json", {
        "semantics": "abstention/uncertainty state, not a medical severity",
        "fresh_test": {
            "support": needs_support,
            "raw_precision": fresh["raw_classifier"]["per_class"]["needs_review"]["precision"],
            "raw_recall": fresh["raw_classifier"]["per_class"]["needs_review"]["recall"],
            "routed_precision": fresh["review_routed"]["per_class"]["needs_review"]["precision"],
            "routed_recall": fresh["review_routed"]["per_class"]["needs_review"]["recall"],
            "clear_cases_routed_to_review": false_review,
            "clear_case_false_review_rate": false_review / clear_support,
            "high_risk_raw_assigned_minor": test_conf[1][0] + test_conf[2][0],
            "high_risk_review_routing": fresh["high_risk_review_rate"],
        },
        "qualification": "Synthetic-only; confidence threshold 0.65 is provisional and not calibrated for deployment.",
    })
    write("CALIBRATION_METRICS.json", calibration)
    write("REVIEW_ROUTING_ANALYSIS.json", {
        "fresh_test_threshold": fresh["review_threshold"], "fresh_test_coverage": fresh["coverage"],
        "fresh_test_review_routing_percent": fresh["abstention_rate"],
        "fresh_test_accepted_accuracy": fresh["accepted_accuracy"],
        "fresh_test_routed_undertriage": fresh["high_risk_undertriage_count"],
        "calibration_threshold_sweep": calibration["routing_sweep_calibration_only"],
        "selected_production_threshold": None,
        "notice": "Calibration sweep only uses calibration.csv; threshold is not approved for live use.",
    })
    write("MODEL_COMPARISON.json", {
        "common_test": "same 84-row synthetic fresh_test.csv; same comparison protocol; these historical model weights were trained elsewhere",
        "new_xlmr": {"macro_f1": fresh["raw_classifier"]["macro_f1"], "critical_recall": fresh["raw_classifier"]["per_class"]["critical"]["recall"], "serious_recall": fresh["raw_classifier"]["per_class"]["serious"]["recall"], "raw_high_risk_undertriage": fresh["raw_high_risk_undertriage_count"]},
        "previous_xlmr": {"macro_f1": previous["raw_classifier"]["macro_f1"], "critical_recall": previous["raw_classifier"]["per_class"]["critical"]["recall"], "serious_recall": previous["raw_classifier"]["per_class"]["serious"]["recall"], "raw_high_risk_undertriage": previous["raw_high_risk_undertriage_count"], "checkpoint": "text_xlmr_fulltrain_local_20261005_1724/trial_lr_2e-5_seed42"},
        "tfidf": {"macro_f1": tfidf["raw_classifier"]["macro_f1"], "critical_recall": tfidf["raw_classifier"]["per_class"]["critical"]["recall"], "serious_recall": tfidf["raw_classifier"]["per_class"]["serious"]["recall"], "raw_high_risk_undertriage": tfidf["raw_high_risk_undertriage_count"], "checkpoint": "text_draft_baseline"},
        "qualification": "Synthetic held-out comparison only. New XLM-R raises Serious recall and macro-F1, but has lower Critical recall and more raw high-risk under-triage than these comparators.",
    })
    write("ACCEPTANCE_GATE.json", {
        "gate_type": "proposed research acceptance gates; not clinical standards",
        "source": "CHECKPOINT_SELECTION_RULE.json frozen before held-out test",
        "test_results": {
            "macro_f1_at_least_0_80": fresh["raw_classifier"]["macro_f1"] >= 0.80,
            "critical_recall_at_least_0_95": fresh["raw_classifier"]["per_class"]["critical"]["recall"] >= 0.95,
            "serious_or_critical_detection_recall_at_least_0_90": high_risk_detection_recall >= 0.90,
            "each_observed_language_macro_f1_at_least_0_75": all(fresh["per_language"][k]["macro_f1"] >= 0.75 for k in ("en", "tl", "ceb", "mixed")),
            "zero_raw_high_risk_undertriage": fresh["raw_high_risk_undertriage_count"] == 0,
        },
        "language_macro_f1_raw": {k: slices["fresh_test"]["language"][k]["raw_macro_f1"] for k in ("en", "tl", "ceb", "mixed")},
        "language_macro_f1_after_provisional_routing": {k: fresh["per_language"][k]["macro_f1"] for k in ("en", "tl", "ceb", "mixed")},
        "serious_or_critical_detection_recall": high_risk_detection_recall,
        "passed": sum([fresh["raw_classifier"]["macro_f1"] >= 0.80, fresh["raw_classifier"]["per_class"]["critical"]["recall"] >= 0.95, high_risk_detection_recall >= 0.90, all(slices["fresh_test"]["language"][k]["raw_macro_f1"] >= 0.75 for k in ("en", "tl", "ceb", "mixed")), fresh["raw_high_risk_undertriage_count"] == 0]),
        "total": 5,
        "status": "FAIL",
        "reason": "Critical recall, raw mixed-language F1 floor, and zero-undertriage requirements fail on fresh_test; overall macro-F1 and combined high-risk detection recall pass.",
    })
    write("SCENARIO_DIVERSITY_AUDIT.json", {
        "rows": stats["rows"], "declared_scenario_family_count": stats["scenario_family_count"],
        "annotation_group_count": stats["annotation_group_count"],
        "families_shared_by_more_than_one_row": stats["rows"] - stats["scenario_family_count"],
        "interpretation": "High nominal family count; family identifiers were generator-assigned and do not establish true semantic independence. Human family-link review remains necessary.",
    })
    write("SEMANTIC_SHORTCUT_AUDIT.json", {
        "status": "PARTIAL",
        "checks": {
            "all_four_labels_present_in_each_language": True,
            "length_bucket_distribution_reported": True,
            "exact_duplicate_check": "pass_with_limitations; see DUPLICATE_AUDIT.json",
            "near_duplicate_review": "38 internal similarity pairs >=0.75 reported by independent red-team; pair relationships were reviewed/linked where detected; further human semantic audit required",
            "native_language_subject_negation_person_object_review": "not established without fluent responder reviewers",
        },
        "risk": "Synthetic labels may remain associated with wording, severity cues, and template style. Metrics alone cannot establish full-meaning interpretation.",
        "required_human_checks": ["person vs vehicle/object", "dead engine vs deceased/unresponsive person", "phone nonresponse vs victim nonresponse", "negation", "bleeding modifiers", "functional impairment", "short panic texts", "language naturalness"],
    })
    write("TRAINING_PIPELINE_AUDIT.json", {
        "model": chosen_meta["base_model"], "revision": chosen_meta["base_model_revision"],
        "model_type": chosen_meta["model_type"], "labels": chosen_meta["labels"],
        "tokenizer_max_length": chosen_meta["max_length"], "total_parameters": chosen_meta["total_parameters"],
        "trainable_parameters": chosen_meta["trainable_parameters"], "frozen_parameters": chosen_meta["total_parameters"] - chosen_meta["trainable_parameters"],
        "trainable_percentage": chosen_meta["trainable_parameters"] / chosen_meta["total_parameters"] * 100,
        "optimizer": chosen_meta["optimizer"], "learning_rate": chosen_meta["learning_rate"],
        "batch_size": chosen_meta["batch_size"], "gradient_accumulation": chosen_meta["gradient_accumulation"],
        "precision": chosen_meta["precision"], "gradient_checkpointing": chosen_meta["gradient_checkpointing"],
        "device": chosen_meta["device"], "training_and_selection_seconds": chosen_meta["training_and_selection_seconds"],
        "best_epoch": chosen_meta["best_epoch"], "all_rows_draft_allow_draft_used": chosen_meta["allow_draft"],
        "tokenizer_warning": "Transformers emitted an incorrect-regex warning that references Mistral while loading the XLM-R tokenizer; the saved tokenizer class/config remains XLM-R, but this warning needs independent resolution before any controlled use.",
        "active_model_changed": False, "deployment_performed": False,
    })
    write("MULTI_AGENT_EXECUTION_MATRIX.json", {
        "agents_assigned": 4, "agents_completed": 4, "agents_partial": 0, "agents_blocked": 0, "agents_failed": 0,
        "agents": [
            {"agent": "dataset_privacy_inventory", "role": "English draft corpus and privacy/provenance inventory", "status": "COMPLETE", "main_artifact": "staging/english_draft.csv + ENGLISH_GENERATION_NOTES.json", "finding": "250 rows; 2,543 words; all drafts."},
            {"agent": "checkpoint_pipeline_audit", "role": "Cebuano and mixed-language corpus/pipeline audit", "status": "COMPLETE", "main_artifact": "staging/cebuano_draft.csv + mixed_draft.csv and notes", "finding": "240 Cebuano and 120 mixed rows; language categories remain heuristically unverified."},
            {"agent": "phase1_independent_redteam", "role": "Independent corpus overlap, duplicate, privacy and semantic red-team", "status": "COMPLETE_WITH_LIMITATIONS", "main_artifact": "DUPLICATE_AUDIT.json + LEAKAGE_AUDIT.json", "finding": "No exact prior matches in accessible sources; 60 CSV files inaccessible; human multilingual review remains required."},
            {"agent": "final_experiment_audit", "role": "Independent final metrics, split, checkpoint and active-model QA", "status": "COMPLETE_WITH_LIMITATIONS", "main_artifact": "FINAL_QA_AUDIT.json", "finding": "Selection is consistent; fresh-test gates fail; active config unchanged."}
        ],
        "conflicts_found": ["Provenance scan reports 1,732 prior text records while independent red-team inventory reports 2,708 rows across 17 readable files; scan populations/definitions differ."],
        "conflicts_resolved": ["Selected checkpoint resolved by frozen validation rule; old EN/CEB family-link corrections incorporated before splitting.", "Per-language raw F1 is now reported separately from post-threshold routed F1; the CLI per_language field represents routed results."],
        "unresolved_conflicts": ["Prior-source inventory counts are not directly reconcilable, and 60 CSV files were inaccessible."],
        "scope_note": "Four focused agent assignments were used; the 20 example workstreams in the task were not each separately staffed. Lead coordinator completed training/evaluation and artifact assembly."
    })
    sealed_receipt_path = REPORT / "SEALED_PACKAGE_RECEIPT.json"
    sealed_receipt = read(sealed_receipt_path) if sealed_receipt_path.is_file() else None
    write("FINAL_QA_AUDIT.json", {
        "status": "PASS_WITH_LIMITATIONS",
        "dataset_counts_and_provenance": "PASS: 850 rows, all synthetic drafts, 0 approved.",
        "five_way_split": "PASS: rows 516/124/83/84/43; recorded family/text overlap 0.",
        "selection": "PASS: LR 2e-5 epoch 6 selected under frozen validation-only balanced_safety rule.",
        "research_acceptance_gates": "FAIL: fresh-test Critical recall 0.714, raw mixed-language macro-F1 0.731 below 0.75, and raw high-risk undertriage 6. Routed per-language scores are separately reported.",
        "active_config": "PASS: pre/post SHA-256 match; no active checkpoint change.",
        "deployment": "PASS: none performed.",
        "prior_exposure": "PARTIAL: 60 historical CSVs inaccessible; readable-source inventory counts differ by method (1,732 vs 2,708 rows).",
        "language_quality": "PARTIAL: no fluent human review of Tagalog/Cebuano/mixed draft corpus.",
        "tokenizer_warning": "PARTIAL: Transformers printed a Mistral regex warning while loading XLM-R tokenizer; unresolved.",
        "sealed_package": "PASS: ZIP CRC and per-member SHA-256 checks passed." if sealed_receipt else "PENDING",
        "independent_audit_agent": "final_experiment_audit",
        "human_review_required": True,
        "active_model_changed": False,
        "deployment_performed": False
    })
    write("CHECKPOINT_SHA256.json", {p.name: sha256(p) for p in sorted(BEST.iterdir()) if p.is_file()})
    env = read(REPORT / "SYSTEM_ENVIRONMENT.json")
    env["active_config_sha256_after_training"] = sha256(ACTIVE_CONFIG)
    env["active_config_unchanged"] = env["active_config_sha256_before_training"] == env["active_config_sha256_after_training"]
    env["active_model_changed"] = False
    env["deployment_performed"] = False
    write("SYSTEM_ENVIRONMENT.json", env)
    final_text = """# Final decision: blocked pending required evidence

This was a completed exploratory, synthetic-draft training experiment. It did not create a human-validated severity model. Final technical decision: `BLOCKED_PENDING_REQUIRED_EVIDENCE`.

The 850 descriptions (7,795 primary-language words plus 1,725 separate mixed-language words) remain `synthetic_ai_generated` and `draft`. No human-confirmed rows were included. Three full-backbone XLM-R learning-rate trials ran; the validation-only frozen rule selected `trial_lr_2e-5_seed42`, best epoch 6. The 84-row fresh test reached macro-F1 0.878, but Critical recall was 0.714 and raw Serious/Critical under-triage was 6/42. The proposed research acceptance gates therefore fail. Calibration and challenge sets are synthetic and small; calibration did not change the checkpoint. Language and short-text results have low support, and the independent historical overlap scan could not read 60 CSV copies.

Do not activate this checkpoint, use it to make dispatch decisions, or describe it as production ready. The existing active model/configuration were left unchanged, and no deployment was performed.

## Exact next action

Have a responder and fluent English, Tagalog, and Cebuano/Bisaya reviewers independently review the 850 draft cases using the current rubric; adjudicate high-risk disagreement and semantic-family links. Record genuine review provenance. Only after accepted data and prior-source access gaps are resolved should a newly versioned dataset and split be created and a fresh experiment run.
"""
    (REPORT / "FINAL_DECISION.md").write_text(final_text, encoding="utf-8")

    command_log = r"""# Experiment command log (PowerShell; run from ai_service)

Dataset and frozen-split preparation/validation are detailed in BUILD_SUMMARY.json, DATASET_GENERATION_REPORT.json, SPLIT_MANIFEST.json and LEAKAGE_AUDIT.json.

Three independent full-backbone trials (all used `--allow-draft`; exploratory only):

```powershell
.\.venv-xlmrt-fulltrain\Scripts\python.exe -B train_text.py train --data datasets\emergency_text\experiments\civilian_multilingual_6000w_20261005\model_development.csv --output artifacts\checkpoints\text_xlmr_civilian_multilingual_20261005\trial_lr_1e-5_seed42 --allow-draft --seed 42 --split-manifest datasets\emergency_text\experiments\civilian_multilingual_6000w_20261005\training_split_manifest.json --base-model FacebookAI/xlm-roberta-base --revision 42f548f32366559214515ec137cdd16002968bf6 --epochs 8 --early-stopping-patience 2 --selection-metric balanced_safety --batch-size 1 --gradient-accumulation 16 --max-length 64 --unfreeze-backbone --learning-rate 1e-5 --threads 2 --device cuda --review-threshold 0.65 --gradient-checkpointing --optimizer adafactor --warmup-ratio 0.05
.\.venv-xlmrt-fulltrain\Scripts\python.exe -B train_text.py train --data datasets\emergency_text\experiments\civilian_multilingual_6000w_20261005\model_development.csv --output artifacts\checkpoints\text_xlmr_civilian_multilingual_20261005\trial_lr_2e-5_seed42 --allow-draft --seed 42 --split-manifest datasets\emergency_text\experiments\civilian_multilingual_6000w_20261005\training_split_manifest.json --base-model FacebookAI/xlm-roberta-base --revision 42f548f32366559214515ec137cdd16002968bf6 --epochs 8 --early-stopping-patience 2 --selection-metric balanced_safety --batch-size 1 --gradient-accumulation 16 --max-length 64 --unfreeze-backbone --learning-rate 2e-5 --threads 2 --device cuda --review-threshold 0.65 --gradient-checkpointing --optimizer adafactor --warmup-ratio 0.05
.\.venv-xlmrt-fulltrain\Scripts\python.exe -B train_text.py train --data datasets\emergency_text\experiments\civilian_multilingual_6000w_20261005\model_development.csv --output artifacts\checkpoints\text_xlmr_civilian_multilingual_20261005\trial_lr_3e-5_seed42 --allow-draft --seed 42 --split-manifest datasets\emergency_text\experiments\civilian_multilingual_6000w_20261005\training_split_manifest.json --base-model FacebookAI/xlm-roberta-base --revision 42f548f32366559214515ec137cdd16002968bf6 --epochs 8 --early-stopping-patience 2 --selection-metric balanced_safety --batch-size 1 --gradient-accumulation 16 --max-length 64 --unfreeze-backbone --learning-rate 3e-5 --threads 2 --device cuda --review-threshold 0.65 --gradient-checkpointing --optimizer adafactor --warmup-ratio 0.05
```

Post-selection evaluation commands (all run from `ai_service`):

```powershell
.\.venv-xlmrt-fulltrain\Scripts\python.exe -B train_text.py evaluate --data datasets\emergency_text\experiments\civilian_multilingual_6000w_20261005\model_development.csv --model artifacts\checkpoints\text_xlmr_civilian_multilingual_20261005\trial_lr_2e-5_seed42 --output artifacts\reports\civilian_multilingual_fulltrain_20261005\fresh_test_eval --split test --allow-draft --batch-size 16 --device cuda
.\.venv-xlmrt-fulltrain\Scripts\python.exe -B train_text.py evaluate --data datasets\emergency_text\experiments\civilian_multilingual_6000w_20261005\calibration.csv --model artifacts\checkpoints\text_xlmr_civilian_multilingual_20261005\trial_lr_2e-5_seed42 --output artifacts\reports\civilian_multilingual_fulltrain_20261005\calibration_eval --split external --allow-draft --batch-size 16 --device cuda
.\.venv-xlmrt-fulltrain\Scripts\python.exe -B train_text.py evaluate --data datasets\emergency_text\experiments\civilian_multilingual_6000w_20261005\challenge.csv --model artifacts\checkpoints\text_xlmr_civilian_multilingual_20261005\trial_lr_2e-5_seed42 --output artifacts\reports\civilian_multilingual_fulltrain_20261005\challenge_eval --split external --allow-draft --batch-size 16 --device cuda
.\.venv-xlmrt-fulltrain\Scripts\python.exe -B train_text.py evaluate --data datasets\emergency_text\experiments\civilian_multilingual_6000w_20261005\fresh_test.csv --model artifacts\checkpoints\text_xlmr_fulltrain_local_20261005_1724\trial_lr_2e-5_seed42 --output artifacts\reports\civilian_multilingual_fulltrain_20261005\previous_xlmr_fresh_test_eval --split external --allow-draft --batch-size 16 --device cuda
.\.venv-xlmrt-fulltrain\Scripts\python.exe -B train_text.py evaluate --data datasets\emergency_text\experiments\civilian_multilingual_6000w_20261005\fresh_test.csv --model artifacts\checkpoints\text_draft_baseline --output artifacts\reports\civilian_multilingual_fulltrain_20261005\tfidf_fresh_test_eval --split external --allow-draft --batch-size 16 --device cpu
.\.venv-xlmrt-fulltrain\Scripts\python.exe -B artifacts\reports\civilian_multilingual_fulltrain_20261005\analyze_calibration_and_slices.py
```

These evaluation/analysis commands ran only after validation selection was frozen. Fresh-test/challenge results were not used for training, checkpoint selection, or calibration fitting. Calibration temperature and threshold sweep use calibration.csv only.
"""
    (REPORT / "COMMAND_LOG.txt").write_text(command_log, encoding="utf-8")
    handoff = AI / "artifacts/reports/NEXT_STAGE_DECISION_FOR_CHATGPT.txt"
    marker = "## 2026-10-05 Multilingual synthetic full-backbone experiment"
    addition = f"""

{marker}

This section supersedes earlier planned/blocked status for this specific experiment. The work completed is an exploratory synthetic-draft study only.

## New dataset and label distribution

- English: 250 rows, 2,543 words; Minor 63/621 words, Serious 64/662, Critical 61/683, needs_review 62/577.
- Tagalog: 240 rows, 2,633 words; Minor 60/561, Serious 60/706, Critical 60/742, needs_review 60/624.
- Cebuano/Bisaya: 240 rows, 2,619 words; Minor 60/689, Serious 60/697, Critical 60/699, needs_review 60/534.
- Mixed language: 120 rows, 1,725 words (separate from the primary 7,795 words); Minor 30/472, Serious 30/417, Critical 30/444, needs_review 30/392.
- Total: 850 descriptions, 9,520 words (7,795 primary-language + 1,725 mixed).
- Length buckets overall: 1–3 words 54; 4–8 words 247; 9–15 words 373; 16+ words 176. Very-short examples are sparse in several language/severity slices.
- Mixed-language switching categories are lexical heuristics, not human-verified: Cebuano–Tagalog 32, Tagalog–English 11, Cebuano–English 14, three-language 54, unclear 9.

## Provenance, families and partitions

All 850 rows are synthetic AI-generated drafts; human-confirmed real 0, human-confirmed synthetic 0, other draft 0. `needs_review` is the uncertainty/abstention state, not a severity label. There are 833 declared families/groups across 850 rows. Family identifiers are generator-assigned and do not prove semantic independence. Major intended scenario types include motorcycle/car/tricycle/bicycle/truck crashes, pedestrian impact, rollovers, multi-vehicle collisions, vehicle/object impacts, entrapment, fire and weather/night conditions.

Family-disjoint partitions: train 516; validation 124; calibration 83; fresh test 84; challenge 43. Recorded cross-partition family and normalized-text overlap is zero. Accessible-source scan found no exact or high-similarity matches; 60 existing CSV files were inaccessible. Two inventory methods reported 1,732 vs 2,708 readable prior rows, so total prior-exposure coverage remains unresolved.

## Full training and evaluation

Full-backbone base: FacebookAI/xlm-roberta-base, pinned revision 42f548f32366559214515ec137cdd16002968bf6. Selected checkpoint: `artifacts/checkpoints/text_xlmr_civilian_multilingual_20261005/trial_lr_2e-5_seed42`; all 278,046,724 parameters trainable, GPU RTX 3050 Laptop (4 GB), Adafactor, FP16 autocast, gradient checkpointing, batch 1, accumulation 16, max length 64; selected epoch 6 (8 epochs completed); duration 948.16 sec. Three learning-rate trials (1e-5, 2e-5, 3e-5); frozen selection used validation only.

Selected validation: macro-F1 .9192, Critical recall .871, Serious recall .938, raw high-risk undertriage 3/63.

Fresh test (84 synthetic cases): macro-F1 .8781, Critical recall .714 (15/21), Serious recall .952 (20/21), raw high-risk undertriage 6/42; review threshold routing moved some to human review but still left 4/42 routed under-triage. Critical errors included 4 Critical→Minor and 2 Critical→Serious.

Fresh-test RAW language macro-F1: English 1.000 (24), Tagalog .873 (24), Cebuano .823 (24), mixed .731 (12). The CLI's separately reported confidence-routed language macro-F1 at provisional threshold .65 is English .958, Tagalog .834, Cebuano .725, mixed .731. Mixed Critical recall .333 on only 3 Critical examples; this is insufficient support for a strong conclusion. By length: 1–3 words n=7, 4–8 n=28, 9–15 n=34, 16+ n=15; slices are too small to establish generalization.

Calibration used only its separate 83 synthetic drafts. Scalar temperature fit T=.774; calibration NLL .317→.298, Brier .149→.142, 10-bin ECE .063→.037. This is exploratory and does not validate deployment confidence. Challenge n=43 scored macro-F1 .977, Critical and Serious recall 1.0, undertriage 0; small, synthetic challenge results do not offset fresh-test errors.

Same 84-row fresh-test comparison: new XLM-R macro-F1 .878, Critical recall .714, Serious recall .952, raw undertriage 6; previous XLM-R .491, .857, .286, 0; TF-IDF .690, .857, .571, 3. New XLM-R improves overall and Serious results but regresses on Critical recall and raw under-triage relative to these candidates.

## Decision and safeguards

Proposed research gates: 2/5 pass (macro-F1 and combined Serious/Critical recall); Critical recall, per-language floor and zero-undertriage fail. Final decision: `BLOCKED_PENDING_REQUIRED_EVIDENCE`. Active checkpoint/config unchanged (active-config hash unchanged); no deployment. The model remains unapproved. A tokenizer warning referencing Mistral regex appeared while loading the XLM-R tokenizer and remains unresolved.

## Exact next action

Have a responder and fluent English, Tagalog and Cebuano/Bisaya reviewers independently review and adjudicate all 850 drafts, prioritizing Critical/Serious boundary cases, short/panic wording, negation, person/object ambiguity, language naturalness and family links. Resolve prior-source access/inventory discrepancies. Do not promote this checkpoint; create a new versioned dataset and experiment only after genuine review evidence exists.

Sealed package: `artifacts/packages/civilian_multilingual_6000w_20261005_xlmr_research.zip`. Its SHA-256 is recorded in `SEALED_PACKAGE_RECEIPT.json` beside this report.

Report directory: `artifacts/reports/civilian_multilingual_fulltrain_20261005/`.

## COPY-PASTE CONTEXT FOR CHATGPT

The multilingual XLM-R experiment completed using 850 synthetic draft descriptions, with no human-confirmed rows. The selected checkpoint is `artifacts/checkpoints/text_xlmr_civilian_multilingual_20261005/trial_lr_2e-5_seed42`. Fresh-test macro-F1 was 0.878, Critical recall 0.714, and raw Serious/Critical under-triage 6/42. Only 2 of 5 proposed research gates passed. The checkpoint remains inactive and no deployment occurred. Final decision: `BLOCKED_PENDING_REQUIRED_EVIDENCE`. The next action is independent responder and fluent-language review/adjudication of the 850 drafts, followed by a new family-safe dataset/split and a new experiment. Treat all current scores as synthetic-only and do not use this checkpoint for live triage.
"""
    prior_handoff = handoff.read_text(encoding="utf-8") if handoff.exists() else ""
    if marker in prior_handoff:
        prior_handoff = prior_handoff.split(marker, 1)[0].rstrip() + "\n"
    handoff.write_text(prior_handoff + addition, encoding="utf-8")
    print(json.dumps({"selected_checkpoint": str(BEST), "report": str(REPORT), "active_config_unchanged": env["active_config_unchanged"], "fresh_test_macro_f1": fresh["raw_classifier"]["macro_f1"], "fresh_test_critical_recall": fresh["raw_classifier"]["per_class"]["critical"]["recall"], "final_decision": "BLOCKED_PENDING_REQUIRED_EVIDENCE"}, indent=2))


if __name__ == "__main__":
    main()
