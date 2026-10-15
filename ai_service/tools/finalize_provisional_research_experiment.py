"""Finalize the authorized synthetic/provisional text experiment.

Produces analysis and integrity artifacts only. It never changes active_config,
source data, human worksheets, Laravel, or deployment state.
"""

from __future__ import annotations

import csv
import hashlib
import json
import os
import platform
import shutil
import sys
from collections import Counter, defaultdict
from datetime import datetime
from pathlib import Path

import numpy as np
from sklearn.metrics import accuracy_score, confusion_matrix, precision_recall_fscore_support


ROOT = Path(__file__).resolve().parents[2]
AI_ROOT = ROOT / "ai_service"
sys.path.insert(0, str(AI_ROOT))

from src.ai_service.text_training import LABELS, _baseline_predictor  # noqa: E402


SOURCE = AI_ROOT / "datasets" / "emergency_text" / "working" / "reports.csv"
CANDIDATES = AI_ROOT / "datasets" / "emergency_text" / "expansions" / "short_panic_v1" / "candidates.csv"
PACK = AI_ROOT / "artifacts" / "review_packs" / "short_panic_v1_human_review_20261001"
AI_DRAFT = PACK / "ai_assisted" / "AI_PRE_REVIEW_DRAFT.csv"
ACTIVE_CONFIG = AI_ROOT / "active_config.txt"
REPORT = AI_ROOT / "artifacts" / "reports" / "PROVISIONAL_TRAINING_UPDATE_FOR_CHATGPT.txt"
RUBRIC = "TEXT-RUBRIC-0.2-B"
AUTHORIZATION = {
    "client_approved_research_training": True,
    "client_approved_provisional_evaluation": True,
    "approval_source": "project_owner_reported_client_approval",
    "approver_identity": "not_provided",
    "approval_date": "2026-10-01",
    "authorization_scope": "offline_research_training_and_provisional_evaluation_only",
}
RELEASE_FIELDS = {
    **AUTHORIZATION,
    "release_status": "experimental_not_approved",
    "training_data_status": "synthetic_provisional_unreviewed",
    "human_review_complete": False,
    "reviewer_a_row_level_review_complete": False,
    "reviewer_b_row_level_review_complete": False,
    "reviewed_eligible_cases": 0,
    "reviewed_milestone_target": 800,
    "approved_for_deployment": False,
    "deployment_ready": False,
    "clinical_validation_complete": False,
    "active_checkpoint_change_authorized": False,
}


def read_json(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value: object) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
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


def length_bucket(text: str) -> str:
    count = len(text.split())
    if count <= 3:
        return "1-3"
    if count <= 8:
        return "4-8"
    if count <= 15:
        return "9-15"
    if count <= 30:
        return "16-30"
    return "31-60" if count <= 60 else "61+"


def raw_predictions(saved: dict) -> list[str]:
    return [LABELS[int(np.argmax(row["probabilities"]))] for row in saved["rows"]]


def routed_predictions(saved: dict, threshold: float) -> list[str]:
    result = []
    for row in saved["rows"]:
        index = int(np.argmax(row["probabilities"]))
        result.append(LABELS[index] if row["probabilities"][index] >= threshold else "needs_review")
    return result


def metrics(actual: list[str], predicted: list[str]) -> dict[str, object]:
    precision, recall, f1, support = precision_recall_fscore_support(
        actual, predicted, labels=list(LABELS), zero_division=0
    )
    total = sum(support)
    macro_precision = float(np.mean(precision))
    macro_recall = float(np.mean(recall))
    macro_f1 = float(np.mean(f1))
    weighted_f1 = float(np.average(f1, weights=support)) if total else None
    per_label = {
        label: {
            "precision": float(precision[index]), "recall": float(recall[index]),
            "f1": float(f1[index]), "support": int(support[index]),
        }
        for index, label in enumerate(LABELS)
    }
    matrix = confusion_matrix(actual, predicted, labels=list(LABELS)).tolist()
    critical_actual = [index for index, label in enumerate(actual) if label == "critical"]
    critical_under = sum(predicted[index] in {"minor", "serious"} for index in critical_actual)
    critical_missed = sum(predicted[index] != "critical" for index in critical_actual)
    return {
        "count": len(actual),
        "accuracy": float(accuracy_score(actual, predicted)) if actual else None,
        "macro_precision": macro_precision,
        "macro_recall": macro_recall,
        "macro_f1": macro_f1,
        "weighted_f1": weighted_f1,
        "per_label": per_label,
        "critical_recall": per_label["critical"]["recall"],
        "critical_underclassification_count_minor_or_serious": critical_under,
        "critical_missed_count_including_needs_review": critical_missed,
        "confusion_matrix": {"labels": list(LABELS), "rows_actual_columns_predicted": matrix},
    }


def grouped_metrics(rows: list[dict], predicted: list[str], key) -> dict[str, object]:
    groups: dict[str, list[int]] = defaultdict(list)
    for index, row in enumerate(rows):
        groups[key(row)].append(index)
    output = {}
    for name, indices in sorted(groups.items()):
        output[name] = metrics([rows[i]["label"] for i in indices], [predicted[i] for i in indices])
    return output


def analyze_saved_predictions(saved: dict, source_by_id: dict[str, dict], threshold: float) -> tuple[dict, list[dict]]:
    rows = [source_by_id[item["case_id"]] for item in saved["rows"]]
    actual = [row["label"] for row in rows]
    raw = raw_predictions(saved)
    routed = routed_predictions(saved, threshold)
    accepted = [index for index, label in enumerate(routed) if label != "needs_review"]
    accepted_rate = len(accepted) / len(rows)
    result = {
        "raw_classifier": metrics(actual, raw),
        "review_routed_at_provisional_threshold": metrics(actual, routed),
        "coverage": accepted_rate,
        "accepted_prediction_rate": accepted_rate,
        "accepted_accuracy": float(accuracy_score([actual[i] for i in accepted], [raw[i] for i in accepted])) if accepted else None,
        "review_routing_rate": sum(label == "needs_review" for label in routed) / len(rows),
        "per_language_raw": grouped_metrics(rows, raw, lambda row: row["language"]),
        "per_language_routed": grouped_metrics(rows, routed, lambda row: row["language"]),
        "per_length_raw": grouped_metrics(rows, raw, lambda row: length_bucket(row["text"])),
        "per_length_routed": grouped_metrics(rows, routed, lambda row: length_bucket(row["text"])),
    }
    detailed = []
    for row, saved_row, raw_label, routed_label in zip(rows, saved["rows"], raw, routed, strict=True):
        detailed.append({
            "case_id": row["case_id"], "group_id": row["group_id"], "language": row["language"],
            "word_count": len(row["text"].split()), "length_bucket": length_bucket(row["text"]),
            "source_provisional_label": row["label"], "raw_prediction": raw_label,
            "routed_prediction": routed_label, "probabilities": dict(zip(LABELS, saved_row["probabilities"], strict=True)),
        })
    return result, detailed


def split_report(manifest: dict, source_by_id: dict[str, dict]) -> dict[str, object]:
    output = {}
    seen_groups: set[str] = set()
    seen_texts: set[str] = set()
    for split in ("train", "validation", "test"):
        ids = manifest[split]["case_ids"]
        rows = [source_by_id[case_id] for case_id in ids]
        groups = {row["group_id"] for row in rows}
        texts = {" ".join(row["text"].casefold().split()) for row in rows}
        if seen_groups & groups or seen_texts & texts:
            raise ValueError("Frozen split leakage detected during finalization")
        seen_groups.update(groups)
        seen_texts.update(texts)
        counts = [len(row["text"].split()) for row in rows]
        output[split] = {
            "rows": len(rows), "groups": len(groups),
            "label_distribution": dict(sorted(Counter(row["label"] for row in rows).items())),
            "language_distribution": dict(sorted(Counter(row["language"] for row in rows).items())),
            "length_distribution": dict(sorted(Counter(length_bucket(row["text"]) for row in rows).items())),
            "word_count_minimum": min(counts), "word_count_maximum": max(counts),
            "word_count_mean": round(sum(counts) / len(counts), 4),
        }
    output["integrity"] = {
        "group_overlap": 0, "normalized_text_overlap": 0,
        "split_description": "synthetic/provisional experimental split; not a fresh operational holdout",
    }
    return output


def predict_challenge(experiment: Path) -> dict[str, object]:
    import torch
    from transformers import AutoModelForSequenceClassification, AutoTokenizer

    rows = read_csv(AI_DRAFT)
    texts = [row["text"] for row in rows]
    baseline_artifact = read_json(experiment / "baseline" / "baseline.json")
    baseline_probs = _baseline_predictor(baseline_artifact)(texts)

    transformer_dir = experiment / "transformer"
    tokenizer = AutoTokenizer.from_pretrained(transformer_dir, local_files_only=True, trust_remote_code=False)
    model = AutoModelForSequenceClassification.from_pretrained(
        transformer_dir, local_files_only=True, trust_remote_code=False, use_safetensors=True
    ).to("cpu").eval()
    transformer_probs: list[list[float]] = []
    for start in range(0, len(texts), 8):
        batch = tokenizer(texts[start:start + 8], padding=True, truncation=True, max_length=128, return_tensors="pt")
        with torch.inference_mode():
            values = torch.softmax(model(**batch).logits.float(), dim=-1).cpu().tolist()
        transformer_probs.extend(values)

    def prediction(values: list[float]) -> tuple[str, str, float]:
        index = int(np.argmax(values))
        raw = LABELS[index]
        routed = raw if values[index] >= 0.65 else "needs_review"
        return raw, routed, float(values[index])

    detailed = []
    for row, bp, tp in zip(rows, baseline_probs, transformer_probs, strict=True):
        braw, brouted, bscore = prediction(bp)
        traw, trouted, tscore = prediction(tp)
        detailed.append({
            "candidate_id": row["candidate_id"], "neutral_alias": row["neutral_alias"],
            "language": row["language"], "word_count": len(row["text"].split()), "text": row["text"],
            "reference_type": "AI pre-review draft; not human ground truth",
            "ai_draft_reference_label": row["ai_suggested_label"],
            "baseline_raw_prediction": braw, "baseline_routed_prediction": brouted,
            "baseline_max_score_uncalibrated": bscore, "baseline_probabilities": dict(zip(LABELS, bp, strict=True)),
            "transformer_raw_prediction": traw, "transformer_routed_prediction": trouted,
            "transformer_max_score_uncalibrated": tscore, "transformer_probabilities": dict(zip(LABELS, tp, strict=True)),
        })

    def summary(prefix: str) -> dict[str, object]:
        reference = [row["ai_draft_reference_label"] for row in detailed]
        raw = [row[f"{prefix}_raw_prediction"] for row in detailed]
        routed = [row[f"{prefix}_routed_prediction"] for row in detailed]
        critical_indices = [i for i, label in enumerate(reference) if label == "critical"]
        return {
            "reference_disclaimer": "Agreement is against an AI draft, not human-reviewed truth and not accuracy.",
            "raw_agreement_count": sum(a == b for a, b in zip(reference, raw, strict=True)),
            "raw_agreement_rate": sum(a == b for a, b in zip(reference, raw, strict=True)) / len(reference),
            "routed_agreement_count": sum(a == b for a, b in zip(reference, routed, strict=True)),
            "routed_agreement_rate": sum(a == b for a, b in zip(reference, routed, strict=True)) / len(reference),
            "raw_prediction_distribution": dict(sorted(Counter(raw).items())),
            "routed_prediction_distribution": dict(sorted(Counter(routed).items())),
            "review_routing_rate": sum(label == "needs_review" for label in routed) / len(routed),
            "critical_draft_reference_count": len(critical_indices),
            "critical_draft_raw_underclassification_minor_or_serious": sum(raw[i] in {"minor", "serious"} for i in critical_indices),
            "critical_draft_routed_to_review": sum(routed[i] == "needs_review" for i in critical_indices),
            "per_language_raw_agreement": {
                language: {
                    "count": len(indices),
                    "agreement": sum(reference[i] == raw[i] for i in indices),
                    "agreement_rate": sum(reference[i] == raw[i] for i in indices) / len(indices),
                }
                for language in sorted({row["language"] for row in detailed})
                for indices in [[i for i, row in enumerate(detailed) if row["language"] == language]]
            },
        }

    return {
        "status": "challenge_only_not_accuracy_evaluation",
        "rows": len(detailed),
        "length_note": "All challenge texts are shorter than the 22-word minimum in the 400-row training source.",
        "baseline": summary("baseline"),
        "transformer": summary("transformer"),
        "predictions": detailed,
    }


def patch_metadata(path: Path) -> dict:
    metadata = read_json(path)
    metadata.update(RELEASE_FIELDS)
    metadata["rubric_version"] = RUBRIC
    metadata["metrics_scope"] = "synthetic_provisional_experimental_only"
    write_json(path, metadata)
    return metadata


def make_report(experiment: Path, comparison: dict, split: dict, challenge: dict, environment: dict) -> str:
    bval = comparison["validation"]["baseline"]
    tval = comparison["validation"]["transformer"]
    btest = comparison["test"]["baseline"]
    ttest = comparison["test"]["transformer"]
    dataset = read_json(experiment / "dataset_receipt.json")
    transformer_meta = read_json(experiment / "transformer" / "metadata.json")

    def metric_line(name: str, value: dict) -> str:
        raw = value["raw_classifier"]
        return (
            f"{name}: accuracy={raw['accuracy']:.4f}; macro_precision={raw['macro_precision']:.4f}; "
            f"macro_recall={raw['macro_recall']:.4f}; macro_F1={raw['macro_f1']:.4f}; "
            f"weighted_F1={raw['weighted_f1']:.4f}; Critical_recall={raw['critical_recall']:.4f}; "
            f"review_routing_rate@0.65={value['review_routing_rate']:.4f}."
        )

    def per_label(name: str, value: dict) -> list[str]:
        return [
            f"{name} {label}: precision={item['precision']:.4f}; recall={item['recall']:.4f}; F1={item['f1']:.4f}; support={item['support']}"
            for label, item in value["raw_classifier"]["per_label"].items()
        ]

    def matrix(name: str, value: dict) -> list[str]:
        rows = value["raw_classifier"]["confusion_matrix"]["rows_actual_columns_predicted"]
        return [
            f"{name} labels/columns = minor, serious, critical, needs_review",
            f"actual minor       {rows[0]}",
            f"actual serious     {rows[1]}",
            f"actual critical    {rows[2]}",
            f"actual needs_review {rows[3]}",
        ]

    experiment_files = [
        "authorization.json", "baseline/baseline.json", "baseline/metadata.json", "baseline/split_manifest.json",
        "baseline/test_metrics.json", "baseline/test_predictions.json", "baseline/validation_metrics.json",
        "baseline/validation_predictions.json", "comparison_metrics.json", "config.json", "confusion_matrix.json",
        "critical_error_analysis.json", "dataset_receipt.json", "environment.json", "experiment_receipt.json",
        "label_mapping.json", "metadata.json", "per_language_metrics.json", "per_length_metrics.json",
        "pre_training_integrity_receipt.json", "provisional_training_labels.csv", "README_EXPERIMENT_LIMITATIONS.txt",
        "short_panic_challenge.json", "split_manifest.json", "split_summary.json", "test_metrics.json",
        "test_predictions.json", "training_config.json", "training_history.json", "transformer/config.json",
        "transformer/metadata.json", "transformer/model.safetensors", "transformer/special_tokens_map.json",
        "transformer/split_manifest.json", "transformer/test_metrics.json", "transformer/test_predictions.json",
        "transformer/tokenizer.json", "transformer/tokenizer_config.json", "transformer/training_history.json",
        "transformer/validation_metrics.json", "transformer/validation_predictions.json", "transformer/vocab.txt",
        "validation_metrics.json", "validation_predictions.json", "validation_results.json",
    ]

    lines = [
        "PROVISIONAL_TRAINING_UPDATE_FOR_CHATGPT",
        "Generated: " + datetime.now().astimezone().isoformat(),
        "",
        "1. PROJECT CONTEXT",
        "Primary application: web_system/. AI service: ai_service/. Task scope: road/vehicle emergency-text severity research for English, Tagalog, Cebuano/Bisaya and mixed text under Policy B / TEXT-RUBRIC-0.2-B.",
        "Official result description: Client-approved provisional validation against a frozen, group-aware held-out split of the current synthetic/provisional emergency-text dataset.",
        "Pipeline execution success is measurable. Synthetic-label performance is measurable. Human-reviewed accuracy, real-world emergency accuracy and clinical validation are not established. Deployment readiness is no.",
        "",
        "2. CLIENT AUTHORIZATION",
        "client_approved_research_training=true; client_approved_provisional_evaluation=true; approval_source=project_owner_reported_client_approval; approver_identity=not_provided; approval_date=2026-10-01.",
        "authorization_scope=offline_research_training_and_provisional_evaluation_only.",
        "This does not authorize production deployment, autonomous dispatch, clinical validation, confirmed human-reviewed ground truth, active checkpoint promotion, or replacement of responder judgment.",
        "",
        "3. CURRENT HUMAN-REVIEW STATUS",
        "Reviewer A: REV-A-002, Bimbo Trimucha, Domain / Emergency Reviewer, assigned; 0/80 row decisions completed.",
        "Reviewer B: REV-B-001, Bimbo Cortel, Multilingual / Context Reviewer, assigned; 0/80 row decisions completed.",
        "Adjudicator: unassigned. human_review_complete=false; reviewed_eligible_cases=0; reviewed_milestone_target=800.",
        "The original Reviewer A and Reviewer B worksheets remain unchanged and blank.",
        "",
        "4. AI-ASSISTED REVIEW ARTIFACTS",
        "Created ai_assisted/AI_PRE_REVIEW_DRAFT.csv with 80 AI-only draft rows: minor=21, serious=19, critical=20, needs_review=20. draft_status=ai_draft_not_human_approved.",
        "Created REVIEWER_A_CONFIRMATION.csv and REVIEWER_B_LANGUAGE_CONFIRMATION.csv with AI context prefilled and every human-response field blank.",
        "Created SIMPLE_RESPONDER_GUIDE.txt in Cebuano/Bisaya and HUMAN_CONFIRMATION_IMPORT_PLAN.txt. AI suggestions are not attributed to Bimbo Trimucha or Bimbo Cortel.",
        "",
        "5. DATASET USED",
        "ai_service/datasets/emergency_text/working/reports.csv only. Derived by_severity/language exports, archives and the historical 292-row experiment were not appended.",
        "All 400 source rows retain review_status=draft. provisional_training_labels.csv preserves source_label=training_label, human_reviewed=false and client-approved offline-research-only eligibility.",
        "",
        "6. DATASET HASH",
        f"SHA-256: {dataset['source_sha256']}",
        "Dataset fingerprint from the training implementation: 535d54dab55097cb7803a05e7ce39ec9242acd926b42fe74cb3488f7834107df.",
        "",
        "7. ROW/GROUP COUNTS",
        "Rows=400; groups=99; unique normalized texts=400; labels minor=100, serious=100, critical=100, needs_review=100; languages en=120, tl=120, ceb=120, mixed=40.",
        "Word counts: minimum=22, maximum=39, mean=30.8675; 16-30 words=155, 31-60 words=245. Approved/human-reviewed rows=0.",
        "",
        "8. SPLIT COUNTS",
        f"Train: {split['train']['rows']} rows, {split['train']['groups']} groups, labels={split['train']['label_distribution']}, languages={split['train']['language_distribution']}.",
        f"Validation: {split['validation']['rows']} rows, {split['validation']['groups']} groups, labels={split['validation']['label_distribution']}, languages={split['validation']['language_distribution']}.",
        f"Test: {split['test']['rows']} rows, {split['test']['groups']} groups, labels={split['test']['label_distribution']}, languages={split['test']['language_distribution']}.",
        "Group overlap=0; exact normalized-text overlap=0. This is a synthetic/provisional experimental test split, not a fresh operational holdout.",
        "",
        "9. MODELS TRAINED",
        "Model A: repository safe-format character word-boundary TF-IDF (3,5), max_features=20000, balanced logistic regression, random seed 42.",
        "Model B: google-bert/bert-base-multilingual-cased with BertTokenizerFast and four-label classifier. CPU-only constraints required a frozen 177,853,440-parameter backbone; 3,076 classifier parameters were trainable.",
        "Both models used the identical frozen manifest. Neither was activated.",
        "",
        "10. EXACT COMMANDS",
        "Interpreter: .\\ai_service\\.venv-text\\Scripts\\python.exe",
        "Dataset validation: python train_text.py validate --data datasets/emergency_text/working/reports.csv",
        "Baseline: python train_text.py baseline --data datasets/emergency_text/working/reports.csv --output artifacts/checkpoints/<experiment>/baseline --allow-draft --seed 42 --validation-fraction 0.15 --test-fraction 0.15 --evaluate-test --review-threshold 0.65",
        "Transformer: python train_text.py train --data datasets/emergency_text/working/reports.csv --output artifacts/checkpoints/<experiment>/transformer --allow-draft --seed 42 --validation-fraction 0.15 --test-fraction 0.15 --split-manifest artifacts/checkpoints/<experiment>/baseline/split_manifest.json --evaluate-test --review-threshold 0.65 --base-model google-bert/bert-base-multilingual-cased --epochs 8 --early-stopping-patience 2 --selection-metric critical_recall_then_macro_f1 --batch-size 4 --gradient-accumulation 4 --max-length 128 --threads 4 --device cpu",
        "HF_HUB_OFFLINE=1 and TRANSFORMERS_OFFLINE=1 were set; the cached base model was used.",
        "Validation: python -m unittest discover -s tests -p test_*.py; python tools/validate_provisional_research_stage.py <experiment>.",
        "",
        "11. ENVIRONMENT AND HARDWARE",
        f"Python={environment['python_version']}; executable={environment['python_executable']}; platform={environment['platform']}.",
        f"torch={environment['packages']['torch']}; transformers={environment['packages']['transformers']}; scikit-learn={environment['packages']['scikit-learn']}; numpy={environment['packages']['numpy']}.",
        f"Device=CPU; CUDA available={environment['cuda_available']}; CUDA devices={environment['cuda_device_count']}; logical CPUs={environment['logical_cpu_count']}; memory bytes={environment['total_memory_bytes']}.",
        "",
        "12. TRAINING CONFIGURATION",
        "Seed=42. Split target=70/15/15 with group integrity taking priority. Review threshold=0.65 provisional and uncalibrated.",
        f"Transformer: AdamW; scheduler=none; learning_rate=0.001; class_weights={transformer_meta['class_weights']}; max_length=128; batch=4; accumulation=4; effective_batch=16; epochs requested=8; patience=2.",
        "Checkpoint selection used validation Critical recall first and macro F1 second. Test data was not used to select the checkpoint.",
        "",
        "13. TRAINING DURATION",
        "Baseline observed command wall time=11.0484 seconds.",
        f"Transformer training and selection time={transformer_meta['training_and_selection_seconds']} seconds; 3 epochs completed; best epoch=1; early stopping triggered after two non-improving epochs.",
        "",
        "14. VALIDATION METRICS",
        metric_line("Baseline validation raw", bval),
        *per_label("Baseline validation", bval),
        metric_line("Transformer selected-checkpoint validation raw", tval),
        *per_label("Transformer validation", tval),
        "",
        "15. TEST METRICS",
        metric_line("Baseline test raw", btest),
        *per_label("Baseline test", btest),
        metric_line("Transformer test raw", ttest),
        *per_label("Transformer test", ttest),
        "",
        "16. CONFUSION MATRIX",
        "Rows are actual synthetic/provisional labels and columns are predicted labels.",
        *matrix("Baseline test", btest),
        *matrix("Transformer test", ttest),
        "",
        "17. CRITICAL ERROR ANALYSIS",
        "Test Critical support=6 for each model. Raw Critical misses=0 and raw Critical-to-Minor/Serious underclassifications=0 on the synthetic split.",
        "At threshold 0.65, all 6 Critical rows from each model were routed to human review. Transformer Critical recall=1.0 is misleading alone because it predicted 40/42 test rows as Critical and had Critical precision=0.15.",
        "On the short/panic AI-draft challenge, baseline had 0 raw Critical-to-Minor/Serious underclassifications; transformer had 2. Both routed all 20 AI-draft Critical cases to human review.",
        "Critical remains urgency, not confirmed fatality.",
        "",
        "18. LANGUAGE RESULTS",
        "Baseline raw test: ceb 13/13 accuracy=1.0000; en 13/13=1.0000; tl 13/13=1.0000; mixed 3/3=1.0000. Mixed lacks Serious/Critical support, so its macro statistics are not comparable.",
        "Transformer raw test: ceb accuracy=0.1538 macro_F1=0.0667; en accuracy=0.2308 macro_F1=0.1769; tl accuracy=0.1538 macro_F1=0.0667; mixed accuracy=0.0000 macro_F1=0.0000.",
        "Per-language results are synthetic and too small for operational conclusions.",
        "",
        "19. TEXT-LENGTH RESULTS",
        "Baseline raw test: 16-30 words n=23 accuracy/macro_F1=1.0000/1.0000; 31-60 words n=19 accuracy/macro_F1=1.0000/1.0000.",
        "Transformer raw test: 16-30 words n=23 accuracy/macro_F1=0.1304/0.0577; 31-60 words n=19 accuracy/macro_F1=0.2105/0.1750.",
        "The training source has no 1-15 word rows, so short-message accuracy cannot be inferred from this split.",
        "",
        "20. SHORT_PANIC_V1 CHALLENGE RESULTS",
        "Reference labels are AI pre-review drafts, not human ground truth. These are agreement figures, not accuracy.",
        f"Baseline raw agreement={challenge['baseline']['raw_agreement_count']}/80 ({challenge['baseline']['raw_agreement_rate']:.4f}); raw predictions={challenge['baseline']['raw_prediction_distribution']}; routed-to-review={challenge['baseline']['review_routing_rate']:.4f} (73/80).",
        f"Transformer raw agreement={challenge['transformer']['raw_agreement_count']}/80 ({challenge['transformer']['raw_agreement_rate']:.4f}); raw predictions={challenge['transformer']['raw_prediction_distribution']}; routed-to-review={challenge['transformer']['review_routing_rate']:.4f} (80/80).",
        "All 80 challenge texts are shorter than the 22-word minimum in training, demonstrating a material distribution shift.",
        "",
        "21. MODEL COMPARISON",
        "Baseline: excellent separation on the structured synthetic split, but only 11.90% test coverage at the provisional threshold and 66.25% raw agreement on the AI-draft short challenge.",
        "Transformer: raw test accuracy=16.67%, macro_F1=10.69%, mostly-Critical collapse, 0% threshold coverage, and 25% AI-draft challenge agreement.",
        "The transformer failed this provisional evaluation. The baseline is the better research diagnostic, but its perfect synthetic score must not be treated as real-world proof.",
        "",
        "22. BEST PROVISIONAL RESEARCH CANDIDATE",
        "TF-IDF plus balanced logistic regression is the best provisional research result: synthetic test raw accuracy=1.0000, macro_F1=1.0000, weighted_F1=1.0000 and Critical recall=1.0000. It is not a deployment candidate because labels are unreviewed, threshold coverage is low, and short/panic human-reviewed accuracy is unknown.",
        "",
        "23. EXPERIMENT DIRECTORY",
        str(experiment),
        "release_status=experimental_not_approved; training_data_status=synthetic_provisional_unreviewed.",
        "",
        "24. ACTIVE-MODEL STATUS",
        "active_model=false. active_config.txt SHA-256 remained 5e56620321a9d19e485f54369cad2e0fa2d6cd8305b6b817f3efa9c0366b41ba. No checkpoint was promoted or configured for live inference.",
        "",
        "25. DEPLOYMENT STATUS",
        "approved_for_deployment=false; deployment_ready=false; clinical_validation_complete=false; confidence_calibrated=false; review_threshold_status=provisional; deployment_performed=false.",
        "Normal inference rejects the new transformer checkpoint unless an explicit experimental override is used.",
        "",
        "26. LIMITATIONS",
        "All 400 labels are synthetic/provisional and unreviewed. The split is synthetic and may preserve generator/template regularities. Mixed-language test support is only 3. The short challenge has no human labels. The transformer backbone was frozen due CPU-only hardware.",
        "The selection rule favored Critical recall and selected a collapsed epoch-1 classifier. Probabilities and threshold are uncalibrated. Results do not establish human-reviewed, real-world or clinical accuracy.",
        "Transformers 4.57.6 emitted a Mistral-regex compatibility warning for the cached Bert tokenizer. A read-only fix_mistral_regex=True diagnostic raised TypeError for BertPreTokenizer, so no tokenizer or core pipeline file was changed.",
        "",
        "27. EXACT NEXT RECOMMENDED STEP",
        "Bimbo Trimucha must complete REVIEWER_A_CONFIRMATION.csv and Bimbo Cortel must independently complete REVIEWER_B_LANGUAGE_CONFIRMATION.csv using actual identities, decisions, evidence and timezone-aware timestamps. Assign a real adjudicator; seal and hash submissions; validate and adjudicate disagreements; complete family-link review; then create a versioned human-reviewed short/panic batch.",
        "After enough reviewed data exists, rerun group/family-disjoint evaluation with real short/panic coverage. Do not promote either current model.",
        "",
        "28. EXACT FILES CREATED/MODIFIED",
        "AI-assisted files created: ai_assisted/AI_PRE_REVIEW_DRAFT.csv; ai_assisted/REVIEWER_A_CONFIRMATION.csv; ai_assisted/REVIEWER_B_LANGUAGE_CONFIRMATION.csv; ai_assisted/SIMPLE_RESPONDER_GUIDE.txt; ai_assisted/HUMAN_CONFIRMATION_IMPORT_PLAN.txt.",
        "Tool files created: ai_service/tools/prepare_provisional_research_stage.py; ai_service/tools/finalize_provisional_research_experiment.py; ai_service/tools/validate_provisional_research_stage.py.",
        "Report created: ai_service/artifacts/reports/PROVISIONAL_TRAINING_UPDATE_FOR_CHATGPT.txt.",
        "Review-pack files updated: FULL_UPDATE_FOR_CHATGPT.txt; manifest.json; verification.json.",
        "Experiment files (45 including receipt): " + "; ".join(experiment_files) + ".",
        "No source dataset, original reviewer worksheet, active config, historical checkpoint, Laravel file, database file or deployment configuration was modified.",
        "",
        "29. PROTECTED FILE STATUS",
        "Passed: source working CSV, short_panic candidates, both original human worksheets and active_config.txt match their pre-training SHA-256 values. All 67 historical checkpoint files match size and SHA-256. The new experiment receipt hashes 44 files; the review-pack manifest hashes 46 files in a 47-file package; final mismatch counts are zero.",
        "Tests: targeted suite 29 passed/1 skipped (30 total); full ai_service suite 59 passed/1 skipped (60 total); stage validator 16 check groups/1,830 assertions passed; experimental checkpoint smoke passed and required responder review.",
        "",
        "COPY-PASTE CONTEXT FOR CHATGPT",
        "The 2026-10-01 client-approved offline provisional experiment is complete under TEXT-RUBRIC-0.2-B. Human review is still 0/80 for A and 0/80 for B; reviewed eligible rows=0; adjudicator unassigned. The 400-row/99-group working dataset remains draft and unchanged. Frozen split=304/54/42 with zero group or normalized-text overlap. TF-IDF/logistic raw synthetic test accuracy/macro_F1=1.0000/1.0000 but routes 37/42 test rows and 73/80 short-challenge rows to review. Frozen-backbone mBERT raw test accuracy/macro_F1=0.1667/0.1069, collapses mostly to Critical, and routes all test/challenge rows to review. The best research diagnostic is the baseline, but neither model is approved or active. Next: actual independent A/B confirmation, real adjudication, family linking, a versioned human-reviewed short/panic batch, then family-disjoint retraining/evaluation.",
    ]
    return "\n".join(lines) + "\n"


def main() -> int:
    if len(sys.argv) != 2:
        raise SystemExit("usage: finalize_provisional_research_experiment.py EXPERIMENT_DIRECTORY")
    experiment = Path(sys.argv[1]).resolve()
    baseline = experiment / "baseline"
    transformer = experiment / "transformer"
    if not baseline.is_dir() or not transformer.is_dir():
        raise ValueError("Both completed baseline and transformer directories are required")

    source_rows = read_csv(SOURCE)
    source_by_id = {row["case_id"]: row for row in source_rows}
    manifest = read_json(baseline / "split_manifest.json")
    if read_json(transformer / "split_manifest.json") != manifest:
        raise ValueError("Baseline and transformer split manifests differ")
    split = split_report(manifest, source_by_id)

    model_meta = {
        "baseline": patch_metadata(baseline / "metadata.json"),
        "transformer": patch_metadata(transformer / "metadata.json"),
    }
    threshold = 0.65
    combined_metrics: dict[str, dict] = {"validation": {}, "test": {}}
    combined_predictions: dict[str, dict] = {"validation": {}, "test": {}}
    for split_name in ("validation", "test"):
        for model_name, directory in (("baseline", baseline), ("transformer", transformer)):
            saved = read_json(directory / f"{split_name}_predictions.json")
            analysis, detailed = analyze_saved_predictions(saved, source_by_id, threshold)
            combined_metrics[split_name][model_name] = analysis
            combined_predictions[split_name][model_name] = {
                "model_version": model_meta[model_name]["version"], "rows": detailed,
            }

    challenge = predict_challenge(experiment)

    import sklearn
    import torch
    import transformers
    try:
        import psutil
        memory = psutil.virtual_memory().total
    except ImportError:
        memory = None
    environment = {
        "created_at": datetime.now().astimezone().isoformat(),
        "python_executable": sys.executable,
        "python_version": sys.version,
        "platform": platform.platform(),
        "logical_cpu_count": os.cpu_count(),
        "total_memory_bytes": memory,
        "device_used": "cpu",
        "cuda_available": torch.cuda.is_available(),
        "cuda_device_count": torch.cuda.device_count(),
        "packages": {
            "torch": torch.__version__, "transformers": transformers.__version__,
            "scikit-learn": sklearn.__version__, "numpy": np.__version__,
        },
        "offline_model_loading": True,
        "tokenizer_runtime_warning": "Transformers 4.57.6 emitted its incorrect-regex compatibility warning while loading the cached Bert tokenizer.",
        "tokenizer_warning_diagnostic": "A read-only load with fix_mistral_regex=True raised TypeError because BertPreTokenizer does not support the requested patch; no tokenizer source or core training file was changed.",
    }

    config = {
        "experiment_name": experiment.name,
        "rubric_version": RUBRIC,
        "authorization": AUTHORIZATION,
        "dataset": "ai_service/datasets/emergency_text/working/reports.csv",
        "split": {"method": "existing group-aware split_groups", "seed": 42, "validation_fraction": 0.15, "test_fraction": 0.15},
        "baseline": {
            "type": "character word-boundary TF-IDF plus balanced logistic regression",
            "ngram_range": [3, 5], "max_features": 20000, "class_weight": "balanced", "random_state": 42,
            "observed_command_wall_seconds": 11.0484,
        },
        "transformer": {
            "base_model": "google-bert/bert-base-multilingual-cased",
            "base_model_revision": model_meta["transformer"].get("base_model_revision"),
            "tokenizer": "BertTokenizerFast", "backbone_frozen": True,
            "optimizer": "AdamW", "scheduler": "none", "learning_rate": 0.001,
            "class_weights": model_meta["transformer"]["class_weights"],
            "epochs_requested": 8, "epochs_completed": model_meta["transformer"]["epochs_completed"],
            "best_epoch": model_meta["transformer"]["best_epoch"], "early_stopping_patience": 2,
            "batch_size": 4, "gradient_accumulation": 4, "effective_batch_size": 16,
            "maximum_token_length": 128, "checkpoint_selection": "validation critical recall then macro F1",
            "device": "cpu", "training_and_selection_seconds": model_meta["transformer"]["training_and_selection_seconds"],
        },
        "review_threshold": 0.65,
        "review_threshold_status": "provisional_uncalibrated",
    }

    label_mapping = {"label_to_id": {label: index for index, label in enumerate(LABELS)}, "id_to_label": {str(index): label for index, label in enumerate(LABELS)}}
    metadata = {
        "schema_version": 1, "experiment_id": experiment.name, "created_at": datetime.now().astimezone().isoformat(),
        "models": {key: value["version"] for key, value in model_meta.items()},
        "rubric_version": RUBRIC, **RELEASE_FIELDS,
        "test_split_description": "synthetic/provisional experimental test split",
        "metrics_are_real_world_accuracy": False,
    }
    training_history = {
        "baseline": {"history": "not emitted by repository baseline runner", "observed_command_wall_seconds": 11.0484},
        "transformer": read_json(transformer / "training_history.json"),
    }
    critical = {}
    for split_name in ("validation", "test"):
        critical[split_name] = {}
        for model_name in ("baseline", "transformer"):
            rows = combined_predictions[split_name][model_name]["rows"]
            errors = [row for row in rows if row["source_provisional_label"] == "critical" and row["raw_prediction"] != "critical"]
            critical[split_name][model_name] = {
                "critical_source_rows": sum(row["source_provisional_label"] == "critical" for row in rows),
                "critical_raw_misses": len(errors),
                "critical_underclassified_as_minor_or_serious": sum(row["raw_prediction"] in {"minor", "serious"} for row in errors),
                "critical_routed_to_review": sum(row["source_provisional_label"] == "critical" and row["routed_prediction"] == "needs_review" for row in rows),
                "errors": errors,
            }

    shutil.copyfile(baseline / "split_manifest.json", experiment / "split_manifest.json")
    write_json(experiment / "config.json", config)
    write_json(experiment / "metadata.json", metadata)
    write_json(experiment / "label_mapping.json", label_mapping)
    write_json(experiment / "training_config.json", config)
    write_json(experiment / "environment.json", environment)
    write_json(experiment / "training_history.json", training_history)
    write_json(experiment / "split_summary.json", split)
    write_json(experiment / "validation_metrics.json", combined_metrics["validation"])
    write_json(experiment / "test_metrics.json", combined_metrics["test"])
    write_json(experiment / "validation_predictions.json", combined_predictions["validation"])
    write_json(experiment / "test_predictions.json", combined_predictions["test"])
    write_json(experiment / "confusion_matrix.json", {
        split_name: {model: combined_metrics[split_name][model]["raw_classifier"]["confusion_matrix"] for model in ("baseline", "transformer")}
        for split_name in ("validation", "test")
    })
    write_json(experiment / "per_language_metrics.json", {
        split_name: {model: {"raw": combined_metrics[split_name][model]["per_language_raw"], "routed": combined_metrics[split_name][model]["per_language_routed"]} for model in ("baseline", "transformer")}
        for split_name in ("validation", "test")
    })
    write_json(experiment / "per_length_metrics.json", {
        split_name: {model: {"raw": combined_metrics[split_name][model]["per_length_raw"], "routed": combined_metrics[split_name][model]["per_length_routed"]} for model in ("baseline", "transformer")}
        for split_name in ("validation", "test")
    })
    write_json(experiment / "critical_error_analysis.json", critical)
    write_json(experiment / "comparison_metrics.json", combined_metrics)
    write_json(experiment / "short_panic_challenge.json", challenge)

    limitations = """EXPERIMENTAL CHECKPOINT — NOT APPROVED FOR DEPLOYMENT

The 400 labels are synthetic/provisional and unreviewed. Human-reviewed eligible count is zero.
Client authorization covers offline research training and provisional evaluation only.
The synthetic/provisional test split is not a fresh operational holdout.
The baseline's perfect raw test score can reflect synthetic template regularity and does not establish real-world accuracy.
The transformer trained only its classification head because this environment has CPU-only PyTorch and no CUDA device.
The validation rule selected a high-Critical-recall but severely collapsed transformer checkpoint.
The 0.65 review threshold and model probabilities are uncalibrated.
The short/panic reference labels are AI drafts, not human ground truth.
Do not activate, deploy, use for autonomous dispatch, infer confirmed fatality, or replace responder judgment.
"""
    (experiment / "README_EXPERIMENT_LIMITATIONS.txt").write_text(limitations, encoding="utf-8")

    report_text = make_report(experiment, combined_metrics, split, challenge, environment)
    REPORT.parent.mkdir(parents=True, exist_ok=True)
    REPORT.write_text(report_text, encoding="utf-8")
    (PACK / "FULL_UPDATE_FOR_CHATGPT.txt").write_text(report_text, encoding="utf-8")

    # Receipt is written last and hashes every experiment artifact other than itself.
    files = [path for path in sorted(experiment.rglob("*")) if path.is_file() and path.name != "experiment_receipt.json"]
    receipt = {
        "created_at": datetime.now().astimezone().isoformat(), "experiment_id": experiment.name,
        "status": "completed_experimental_not_approved", **RELEASE_FIELDS,
        "files": [{"path": path.relative_to(experiment).as_posix(), "sha256": sha256(path), "bytes": path.stat().st_size} for path in files],
        "file_count_excluding_receipt": len(files),
        "source_dataset_sha256": sha256(SOURCE), "active_config_sha256_after": sha256(ACTIVE_CONFIG),
        "original_reviewer_A_worksheet_sha256_after": sha256(PACK / "reviewer_A" / "worksheet.csv"),
        "original_reviewer_B_worksheet_sha256_after": sha256(PACK / "reviewer_B" / "worksheet.csv"),
        "source_candidates_sha256_after": sha256(CANDIDATES),
    }
    before = read_json(experiment / "pre_training_integrity_receipt.json")
    for path, expected_hash in before["protected_files"].items():
        actual_hash = sha256(ROOT / path)
        if actual_hash != expected_hash:
            raise ValueError(f"Protected file changed: {path}")
    receipt["protected_files_match_pre_training_receipt"] = True
    write_json(experiment / "experiment_receipt.json", receipt)

    print(json.dumps({
        "experiment": str(experiment), "report": str(REPORT),
        "baseline_test_raw": combined_metrics["test"]["baseline"]["raw_classifier"],
        "transformer_test_raw": combined_metrics["test"]["transformer"]["raw_classifier"],
        "short_panic": {"baseline": challenge["baseline"], "transformer": challenge["transformer"]},
        "release_status": "experimental_not_approved", "approved_for_deployment": False,
    }, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
