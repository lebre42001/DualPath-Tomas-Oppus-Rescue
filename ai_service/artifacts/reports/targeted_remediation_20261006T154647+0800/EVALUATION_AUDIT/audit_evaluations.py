"""Read-only CPU audit of saved evaluation probabilities; no model loading/inference."""
from __future__ import annotations

import csv
import hashlib
import json
import math
from collections import Counter, defaultdict
from datetime import datetime, timezone
from pathlib import Path

from sklearn.metrics import accuracy_score, confusion_matrix, f1_score, precision_recall_fscore_support
from scipy.optimize import minimize_scalar

ROOT = Path(__file__).resolve().parents[5]
RUN = ROOT / "ai_service/artifacts/reports/targeted_remediation_20261006T154647+0800"
PRED_ROOT = RUN / "EVALUATIONS_WITH_PREDICTIONS"
SOURCE = ROOT / "ai_service/artifacts/reports/xlmr_850_humanconfirmed_fulltrain_20261006_1411/frozen_splits"
CASE_CSV = RUN / "CASE_AUDIT/CASE_COMPARISON.csv"
OUT = RUN / "EVALUATION_AUDIT"
OUT.mkdir(parents=True, exist_ok=True)
LABELS = ["minor", "serious", "critical", "needs_review"]
THRESHOLD = 0.65


def sha(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for b in iter(lambda: f.read(1024 * 1024), b""):
            h.update(b)
    return h.hexdigest()


def read_csv(path: Path) -> list[dict]:
    with path.open(encoding="utf-8-sig", newline="") as f:
        return list(csv.DictReader(f))


def metrics(actual: list[str], predicted: list[str]) -> dict:
    p, r, f, s = precision_recall_fscore_support(actual, predicted, labels=LABELS, zero_division=0)
    return {
        "n": len(actual), "accuracy": accuracy_score(actual, predicted) if actual else None,
        "macro_f1": f1_score(actual, predicted, labels=LABELS, average="macro", zero_division=0) if actual else None,
        "per_class": {label: {"precision": float(p[i]), "recall": float(r[i]), "f1": float(f[i]), "support": int(s[i])} for i, label in enumerate(LABELS)},
        "confusion_matrix_actual_rows_predicted_columns": confusion_matrix(actual, predicted, labels=LABELS).tolist(),
    }


def metrics_for(rows: list[dict], probs: list[list[float]], threshold: float) -> dict:
    actual = [r["label"] for r in rows]
    raw = [LABELS[max(range(len(LABELS)), key=lambda i: p[i])] for p in probs]
    routed = [pred if max(p) >= threshold else "needs_review" for p, pred in zip(probs, raw)]
    urgent_ix = [i for i, a in enumerate(actual) if a in ("serious", "critical")]
    rank = {"minor": 0, "serious": 1, "critical": 2}
    under_raw = [i for i in urgent_ix if raw[i] in rank and rank[raw[i]] < rank[actual[i]]]
    under_routed = [i for i in urgent_ix if routed[i] in rank and rank[routed[i]] < rank[actual[i]]]
    accepted_ix = [i for i, p in enumerate(probs) if max(p) >= threshold and raw[i] != "needs_review"]
    accepted_errors = [i for i in accepted_ix if raw[i] != actual[i]]
    accepted_urgent_ix = [i for i in accepted_ix if actual[i] in ("serious", "critical")]
    groups = {}
    group_values = sorted(set(r["language"] for r in rows))
    for lang in group_values:
        ix = [i for i, r in enumerate(rows) if r["language"] == lang]
        groups[lang] = {"raw": metrics([actual[i] for i in ix], [raw[i] for i in ix]),
                        "routed": metrics([actual[i] for i in ix], [routed[i] for i in ix]),
                        "support": len(ix)}
    bins = {"1-3": [], "4-8": [], "9-15": [], "16+": []}
    for i, row in enumerate(rows):
        count = len(row["text"].split())
        key = "1-3" if count <= 3 else "4-8" if count <= 8 else "9-15" if count <= 15 else "16+"
        bins[key].append(i)
    length = {}
    for name, ix in bins.items():
        length[name] = {"support": len(ix), "raw": metrics([actual[i] for i in ix], [raw[i] for i in ix]) if ix else None,
                        "routed": metrics([actual[i] for i in ix], [routed[i] for i in ix]) if ix else None}
    directions = Counter(f"{actual[i]}->{raw[i]}" for i in range(len(actual)) if actual[i] != raw[i])
    routed_directions = Counter(f"{actual[i]}->{routed[i]}" for i in range(len(actual)) if actual[i] != routed[i])
    overtriage = {"minor_to_serious_or_critical": sum(a == "minor" and p in ("serious", "critical") for a, p in zip(actual, raw)),
                  "serious_to_critical": sum(a == "serious" and p == "critical" for a, p in zip(actual, raw))}
    return {
        "n": len(rows), "raw": metrics(actual, raw), "routed_at_threshold": metrics(actual, routed),
        "threshold_diagnostic_only": threshold, "coverage": len(accepted_ix) / len(rows) if rows else None,
        "accepted_count": len(accepted_ix), "auto_handled_error_count": len(accepted_errors),
        "auto_handled_error_rate_among_auto": len(accepted_errors) / len(accepted_ix) if accepted_ix else None,
        "accepted_error_directions": dict(sorted(Counter(f"{actual[i]}->{raw[i]}" for i in accepted_errors).items())),
        "raw_error_directions": dict(sorted(directions.items())), "routed_error_directions": dict(sorted(routed_directions.items())),
        "urgent_support_serious_plus_critical": len(urgent_ix),
        "automatically_handled_high_risk_count": len(accepted_urgent_ix),
        "automatic_high_risk_coverage": len(accepted_urgent_ix) / len(urgent_ix) if urgent_ix else None,
        "high_risk_human_review_count": len(urgent_ix) - len(accepted_urgent_ix),
        "high_risk_human_review_rate": (len(urgent_ix) - len(accepted_urgent_ix)) / len(urgent_ix) if urgent_ix else None,
        "conditional_automatic_urgent_accuracy": sum(raw[i] == actual[i] for i in accepted_urgent_ix) / len(accepted_urgent_ix) if accepted_urgent_ix else None,
        "conditional_automatic_urgent_undertriage_rate": sum(raw[i] in rank and rank[raw[i]] < rank[actual[i]] for i in accepted_urgent_ix) / len(accepted_urgent_ix) if accepted_urgent_ix else None,
        "conditional_automatic_urgent_metrics_status": "defined" if accepted_urgent_ix else "UNDEFINED_NO_AUTOMATIC_HIGH_RISK_CASES",
        "raw_urgent_undertriage_count": len(under_raw), "raw_urgent_undertriage_rate": len(under_raw) / len(urgent_ix) if urgent_ix else None,
        "routed_urgent_undertriage_count": len(under_routed), "routed_urgent_undertriage_rate": len(under_routed) / len(urgent_ix) if urgent_ix else None,
        "raw_critical_to_minor_serious_needs_review": {label: sum(a == "critical" and p == label for a, p in zip(actual, raw)) for label in LABELS if label != "critical"},
        "raw_serious_to_minor_needs_review": {label: sum(a == "serious" and p == label for a, p in zip(actual, raw)) for label in ("minor", "needs_review")},
        "raw_overtriage_counts": overtriage, "per_language": groups, "word_length_bins_whitespace": length,
        "raw_prediction_rows": [{"case_id": row["case_id"], "language": row["language"], "label": actual[i], "raw_prediction": raw[i],
                                 "routed_prediction": routed[i], "confidence": max(probs[i]), "word_count": len(row["text"].split())}
                                for i, row in enumerate(rows)],
    }


def probabilities_temperature(probs: list[list[float]], t: float) -> list[list[float]]:
    output = []
    for p in probs:
        logits = [math.log(max(x, 1e-12)) / t for x in p]
        m = max(logits)
        ex = [math.exp(x - m) for x in logits]
        total = sum(ex)
        output.append([x / total for x in ex])
    return output


def cal_stats(actual: list[str], probs: list[list[float]]) -> dict:
    ys = [LABELS.index(a) for a in actual]
    n = len(ys)
    nll = -sum(math.log(max(probs[i][ys[i]], 1e-12)) for i in range(n)) / n
    brier = sum(sum((probs[i][j] - (1.0 if j == ys[i] else 0.0)) ** 2 for j in range(4)) for i in range(n)) / n
    conf = [max(p) for p in probs]
    pred = [max(range(4), key=lambda j: p[j]) for p in probs]
    ece = 0.0
    bins = []
    for b in range(10):
        lo, hi = b / 10, (b + 1) / 10
        ix = [i for i, c in enumerate(conf) if lo <= c < hi or (b == 9 and c == 1.0)]
        if ix:
            acc = sum(pred[i] == ys[i] for i in ix) / len(ix)
            meanconf = sum(conf[i] for i in ix) / len(ix)
            ece += len(ix) / n * abs(acc - meanconf)
            bins.append({"interval": f"[{lo:.1f},{hi:.1f}{']' if b == 9 else ')'}", "n": len(ix), "accuracy": acc, "mean_confidence": meanconf})
    return {"nll": nll, "multiclass_brier": brier, "ece_10_equal_width": ece, "bins": bins}


def main() -> None:
    frozen_policy_path = OUT / "FROZEN_DIAGNOSTIC_POLICY_V2.json"
    if not frozen_policy_path.is_file():
        raise RuntimeError("Freeze the calibration-only diagnostic policy first; exposed evaluation is blocked")
    frozen_policy = json.loads(frozen_policy_path.read_text(encoding="utf-8"))
    if frozen_policy.get("calibration_prediction_sha256") != sha(PRED_ROOT / "CALIBRATION_DIAGNOSTIC/evaluation_predictions.json"):
        raise RuntimeError("Frozen policy does not match the preserved calibration predictions")
    if frozen_policy.get("threshold") != THRESHOLD:
        raise RuntimeError("The unchanged .65 comparative threshold differs from this audit script")
    research_threshold = float(frozen_policy["research_threshold"])
    case_rows = read_csv(CASE_CSV)
    latest = {r["case_id"] for r in case_rows if r["latest_nine_undertriage"].lower() == "true"}
    older = {r["case_id"] for r in case_rows if r["older_six_regression_list"].lower() == "true"}
    source_paths = {"EXPOSED_TEST": SOURCE / "fresh_test.csv", "CALIBRATION_DIAGNOSTIC": SOURCE / "calibration.csv", "EXPOSED_CHALLENGE": SOURCE / "challenge.csv"}
    model_dir = ROOT / "ai_service/artifacts/checkpoints/text_xlmr_targeted_20261006T154647+0800/seed42"
    outputs = {}
    metric_consistency = {}
    detail_cases = {}
    for name, source_path in source_paths.items():
        saved_path = PRED_ROOT / name / "evaluation_predictions.json"
        saved = json.loads(saved_path.read_text(encoding="utf-8"))
        saved_metric_path = PRED_ROOT / name / "evaluation_metrics.json"
        saved_metrics = json.loads(saved_metric_path.read_text(encoding="utf-8"))
        src = read_csv(source_path)
        by_id = {r["case_id"]: r for r in src}
        pred_by_id = {r["case_id"]: r for r in saved["rows"]}
        if set(by_id) != set(pred_by_id):
            raise ValueError(f"Prediction/source ID mismatch {name}: source-only={set(by_id)-set(pred_by_id)} predictions-only={set(pred_by_id)-set(by_id)}")
        rows, probs = [], []
        for case_id, source_row in by_id.items():
            p = pred_by_id[case_id]
            if source_row["label"] != p["label"] or source_row["language"] != p["language"]:
                raise ValueError(f"Label/language mismatch for {name} {case_id}")
            rows.append(source_row)
            probs.append([float(x) for x in p["probabilities"]])
        result = metrics_for(rows, probs, THRESHOLD)
        temp_probs = probabilities_temperature(probs, float(frozen_policy["temperature"]))
        result["frozen_calibration_diagnostic_temperature"] = frozen_policy["temperature"]
        result["temperature_scaled_routed_diagnostic"] = metrics_for(rows, temp_probs, THRESHOLD)
        result["frozen_research_threshold_routed_diagnostic"] = metrics_for(rows, temp_probs, research_threshold)
        next_zero_auto_thresholds = [c / 100 for c in range(int(research_threshold * 100) + 1, 101)
                                     if metrics_for(rows, temp_probs, c / 100)["automatically_handled_high_risk_count"] == 0]
        result["zero_auto_high_risk_endpoint_routed_diagnostic"] = (metrics_for(rows, temp_probs, next_zero_auto_thresholds[0])
                                                                     if next_zero_auto_thresholds else None)
        if result["zero_auto_high_risk_endpoint_routed_diagnostic"] is not None:
            result["zero_auto_high_risk_endpoint_routed_diagnostic"]["threshold"] = next_zero_auto_thresholds[0]
        result.update({"source_fingerprint": saved["dataset_fingerprint"], "source_status": "historically_exposed_synthetic_only" if name != "CALIBRATION_DIAGNOSTIC" else "synthetic_calibration_only_not_independent_real_world_evidence",
                      "all_data_reviewed_recorded": saved.get("all_data_reviewed"), "saved_split": saved.get("split"), "model_version": saved.get("model_version")})
        consistency = {
            "fingerprint_equal": saved["dataset_fingerprint"] == saved_metrics["dataset_fingerprint"],
            "count_equal": len(rows) == saved_metrics["count"],
            "raw_confusion_equal": result["raw"]["confusion_matrix_actual_rows_predicted_columns"] == saved_metrics["raw_classifier"]["confusion_matrix"]["rows_actual_columns_predicted"],
            "raw_macro_f1_equal": math.isclose(result["raw"]["macro_f1"], saved_metrics["raw_classifier"]["macro_f1"], abs_tol=1e-12),
            "routed_065_confusion_equal": result["routed_at_threshold"]["confusion_matrix_actual_rows_predicted_columns"] == saved_metrics["review_routed"]["confusion_matrix"]["rows_actual_columns_predicted"],
            "routed_065_coverage_equal": math.isclose(result["coverage"], saved_metrics["coverage"], abs_tol=1e-12),
            "routed_065_urgent_undertriage_equal": result["routed_urgent_undertriage_count"] == saved_metrics["high_risk_undertriage_count"],
        }
        if not all(consistency.values()):
            raise ValueError(f"Independent metric mismatch in {name}: {consistency}")
        metric_consistency[name] = consistency
        outputs[name] = result
        if name in ("EXPOSED_TEST", "EXPOSED_CHALLENGE"):
            def subset(ids, threshold=THRESHOLD, temperature_scaled=False):
                selected = [i for i, row in enumerate(rows) if row["case_id"] in ids]
                selected_probs = [probs[i] for i in selected]
                if temperature_scaled:
                    selected_probs = probabilities_temperature(selected_probs, float(frozen_policy["temperature"]))
                out = metrics_for([rows[i] for i in selected], selected_probs, threshold) if selected else {"n": 0}
                out["case_ids_found"] = [rows[i]["case_id"] for i in selected]
                return out
            detail_cases[name] = {"latest_nine_raw_and_dot65": subset(latest), "older_six_raw_and_dot65": subset(older),
                                  "deduplicated_union_raw_and_dot65": subset(latest | older),
                                  "latest_nine_frozen_research_threshold": subset(latest, research_threshold, True),
                                  "older_six_frozen_research_threshold": subset(older, research_threshold, True),
                                  "deduplicated_union_frozen_research_threshold": subset(latest | older, research_threshold, True)}
    # Fit a scalar temperature on calibration probabilities only. This is exploratory, not operational.
    cal = outputs["CALIBRATION_DIAGNOSTIC"]
    cal_path = PRED_ROOT / "CALIBRATION_DIAGNOSTIC/evaluation_predictions.json"
    cal_saved = json.loads(cal_path.read_text(encoding="utf-8"))
    cal_source = {r["case_id"]: r for r in read_csv(source_paths["CALIBRATION_DIAGNOSTIC"])}
    cal_rows = [cal_source[p["case_id"]] for p in cal_saved["rows"]]
    cal_probs = [[float(x) for x in p["probabilities"]] for p in cal_saved["rows"]]
    cal_actual = [r["label"] for r in cal_rows]
    temp = float(frozen_policy["temperature"])
    cal_raw_stats = cal_stats(cal_actual, cal_probs)
    cal_temp_probs = probabilities_temperature(cal_probs, temp)
    cal_temp_stats = cal_stats(cal_actual, cal_temp_probs)
    threshold_sweep = []
    for cents in range(0, 101):
        t = cents / 100
        row = {"threshold": t}
        for key, pr in (("raw_probabilities", cal_probs), ("temperature_scaled_diagnostic", cal_temp_probs)):
            m = metrics_for(cal_rows, pr, t)
            row[key] = {"coverage": m["coverage"], "auto_handled_error_count": m["auto_handled_error_count"],
                        "auto_handled_error_rate": m["auto_handled_error_rate_among_auto"],
                        "routed_urgent_undertriage_count": m["routed_urgent_undertriage_count"],
                        "routed_urgent_undertriage_rate": m["routed_urgent_undertriage_rate"],
                        "critical_to_minor": m["raw_critical_to_minor_serious_needs_review"]["minor"]}
        threshold_sweep.append(row)
    research_calibration_metrics = metrics_for(cal_rows, cal_temp_probs, research_threshold)
    zero_auto_threshold = None
    for cents in range(int(research_threshold * 100) + 1, 101):
        candidate_metrics = metrics_for(cal_rows, cal_temp_probs, cents / 100)
        if candidate_metrics["automatically_handled_high_risk_count"] == 0:
            zero_auto_threshold = cents / 100
            zero_auto_metrics = candidate_metrics
            zero_auto_metrics["threshold"] = zero_auto_threshold
            break
    calibration = {"data_used": "only the predeclared 83-row synthetic calibration split", "n": len(cal_rows),
                   "dataset_fingerprint": cal_saved["dataset_fingerprint"], "temperature_fit_method": "bounded scalar NLL minimization over log-probability temperature T in [0.05,10]",
                   "temperature": temp, "raw_probability_quality": cal_raw_stats, "temperature_scaled_diagnostic_quality": cal_temp_stats,
                   "threshold_sweep": threshold_sweep,
                   "frozen_research_threshold": research_threshold,
                   "frozen_research_threshold_calibration_result": {k: research_calibration_metrics[k] for k in (
                       "coverage", "accepted_count", "automatically_handled_high_risk_count", "automatic_high_risk_coverage",
                       "high_risk_human_review_count", "high_risk_human_review_rate", "conditional_automatic_urgent_accuracy",
                       "conditional_automatic_urgent_undertriage_rate", "conditional_automatic_urgent_metrics_status",
                       "routed_urgent_undertriage_count", "urgent_support_serious_plus_critical", "auto_handled_error_count")},
                   "zero_auto_high_risk_endpoint_threshold": zero_auto_threshold,
                   "zero_auto_high_risk_endpoint_calibration_result": ({k: zero_auto_metrics[k] for k in (
                       "coverage", "accepted_count", "automatically_handled_high_risk_count", "automatic_high_risk_coverage",
                       "high_risk_human_review_count", "high_risk_human_review_rate", "conditional_automatic_urgent_accuracy",
                       "conditional_automatic_urgent_undertriage_rate", "conditional_automatic_urgent_metrics_status",
                       "routed_urgent_undertriage_count", "urgent_support_serious_plus_critical", "auto_handled_error_count")}
                       if zero_auto_threshold is not None else None),
                   "decision": "no operational threshold or active calibration selected; diagnostic only; synthetic low-support data cannot establish deployment confidence"}
    # Compare existing authoritative summaries and independently recomputed new outputs.
    old_root = ROOT / "ai_service/artifacts/reports/xlmr_850_humanconfirmed_fulltrain_20261006_1411"
    old_summaries = {}
    for name, path in {"prior_rejected_checkpoint_same_84": old_root / "fresh_test_evaluation/evaluation_metrics.json",
                       "previous_checkpoint_matched_83": old_root / "previous_xlmr_fresh_test_comparison/evaluation_metrics.json",
                       "new_checkpoint_matched_83": old_root / "new_xlmr_fresh_test_matched_comparison/evaluation_metrics.json"}.items():
        d = json.loads(path.read_text(encoding="utf-8"))
        old_summaries[name] = {"path": str(path.relative_to(ROOT)), "sha256": sha(path), "n": d["count"], "fingerprint": d["dataset_fingerprint"],
                               "raw_macro_f1": d["raw_classifier"]["macro_f1"], "raw_accuracy": d["raw_classifier"]["accuracy"],
                               "critical_recall": d["raw_classifier"]["per_class"]["critical"]["recall"],
                               "serious_recall": d["raw_classifier"]["per_class"]["serious"]["recall"],
                               "raw_urgent_undertriage_count": d["raw_high_risk_undertriage_count"],
                               "routed_urgent_undertriage_count": d["high_risk_undertriage_count"], "coverage": d["coverage"],
                               "model_version": d.get("model_version")}
    old84 = old_summaries["prior_rejected_checkpoint_same_84"]
    current84 = outputs["EXPOSED_TEST"]
    old_summaries["same_84_row_delta_current_minus_prior_rejected"] = {
        "fingerprint_matches": old84["fingerprint"] == current84["source_fingerprint"],
        "count_matches": old84["n"] == current84["n"],
        "macro_f1_delta": current84["raw"]["macro_f1"] - old84["raw_macro_f1"],
        "accuracy_delta": current84["raw"]["accuracy"] - old84["raw_accuracy"],
        "critical_recall_delta": current84["raw"]["per_class"]["critical"]["recall"] - old84["critical_recall"],
        "raw_urgent_undertriage_count_current_vs_old": [current84["raw_urgent_undertriage_count"], old84["raw_urgent_undertriage_count"]],
        "routed_urgent_undertriage_count_current_vs_old_at_065": [current84["routed_urgent_undertriage_count"], old84["routed_urgent_undertriage_count"]],
    }
    matched_old = old_summaries["previous_checkpoint_matched_83"]
    matched_new = old_summaries["new_checkpoint_matched_83"]
    old_summaries["historical_matched_83_row_comparison"] = {
        "fingerprints_match": matched_old["fingerprint"] == matched_new["fingerprint"],
        "macro_f1_old_vs_new": [matched_old["raw_macro_f1"], matched_new["raw_macro_f1"]],
        "critical_recall_old_vs_new": [matched_old["critical_recall"], matched_new["critical_recall"]],
        "raw_undertriage_old_vs_new": [matched_old["raw_urgent_undertriage_count"], matched_new["raw_urgent_undertriage_count"]],
        "routed_undertriage_old_vs_new_at_065": [matched_old["routed_urgent_undertriage_count"], matched_new["routed_urgent_undertriage_count"]],
        "note": "This is the historical 83-row previous-vs-earlier-new comparison, separate from the new targeted checkpoint's 84-row evaluation. It is not pooled or mixed with the 84-row comparison."
    }
    report = {
        "created_at_utc": datetime.now(timezone.utc).isoformat(),
        "method": "join saved model probability vectors by case_id to authoritative frozen split CSV; recompute with sklearn metrics; no model loads and no inference",
        "threshold": {"comparative_value": THRESHOLD, "comparative_status": "existing protocol threshold retained unchanged for diagnostic comparison", "frozen_research_value": research_threshold,
                      "status": "calibration-only synthetic research diagnostic; not safe/deployable", "routed_policy": "argmax prediction is abstained to needs_review if max probability < threshold; predicted needs_review remains needs_review"},
        "frozen_diagnostic_policy": frozen_policy,
        "preliminary_ordering_limitation": "The initial evaluation runner executed exposed-test inference before calibration in the same sequence. The coordinator inspected preliminary exposed-test metrics before the second calibration threshold policy froze. No training/checkpoint selection changed, but the initial order was imperfect. Final reporting below was recalculated from preserved prediction probabilities after the mechanical calibration-only research policy freeze, so it is not a confirmatory untouched-heldout evaluation.",
        "label_order": LABELS,
        "deduplicated_case_sets": {"latest_nine_count": len(latest), "older_six_count": len(older), "intersection_count": len(latest & older),
                                   "union_count": len(latest | older), "latest_nine_ids": sorted(latest), "older_six_ids": sorted(older), "union_ids": sorted(latest | older)},
        "evaluations": outputs, "target_case_predictions": detail_cases, "calibration_diagnostic": calibration,
        "saved_metric_consistency_checks": metric_consistency,
        "existing_report_comparison": old_summaries,
        "exact_commands": [
            "ai_service/.venv-xlmrt-fulltrain/Scripts/python.exe -m py_compile ai_service/artifacts/reports/targeted_remediation_20261006T154647+0800/EVALUATION_AUDIT/audit_evaluations.py ai_service/artifacts/reports/targeted_remediation_20261006T154647+0800/EVALUATION_AUDIT/freeze_calibration_policy.py",
            "ai_service/.venv-xlmrt-fulltrain/Scripts/python.exe ai_service/artifacts/reports/targeted_remediation_20261006T154647+0800/EVALUATION_AUDIT/freeze_calibration_policy.py (ran after the calibration-only model evaluation and before this final exposed-set recalculation; created FROZEN_DIAGNOSTIC_POLICY_V2.json)",
            "ai_service/.venv-xlmrt-fulltrain/Scripts/python.exe ai_service/artifacts/reports/targeted_remediation_20261006T154647+0800/EVALUATION_AUDIT/audit_evaluations.py"
        ],
        "limitations": ["All dataset rows remain synthetic even if metadata records human-review/attestation status.", "84-row test and 43-row challenge were already exposed historically; not fresh independent validation.", "Calibration split is synthetic and small; threshold/calibration results are exploratory only.", "No labels were adjudicated or changed; case-level audit is not a human decision.", "No real-world/final responder evaluation is represented.", "Small subgroup and word-length bins have low support and do not establish generalization."],
        "input_sha256": {str(p.relative_to(ROOT)): sha(p) for p in [CASE_CSV, *source_paths.values(), *[PRED_ROOT/n/"evaluation_predictions.json" for n in source_paths],
                                                                    old_root/"fresh_test_evaluation/evaluation_metrics.json",
                                                                    old_root/"previous_xlmr_fresh_test_comparison/evaluation_metrics.json",
                                                                    old_root/"new_xlmr_fresh_test_matched_comparison/evaluation_metrics.json",
                                                                    model_dir/"metadata.json", model_dir/"model.safetensors",
                                                                    *[PRED_ROOT/n/"evaluation_metrics.json" for n in source_paths]]},
        "deployment_approval": False, "active_model_changed": False, "label_change": False,
    }
    (OUT / "EVALUATION_AUDIT.json").write_text(json.dumps(report, ensure_ascii=False, indent=2, allow_nan=False) + "\n", encoding="utf-8")
    (OUT / "CALIBRATION_DIAGNOSTIC.json").write_text(json.dumps(calibration, ensure_ascii=False, indent=2, allow_nan=False) + "\n", encoding="utf-8")
    case_lines = []
    for name, groups in detail_cases.items():
        for set_name, result in groups.items():
            for r in result.get("raw_prediction_rows", []):
                case_lines.append({"evaluation": name, "case_set": set_name, **r})
    if case_lines:
        with (OUT / "TARGET_CASE_PREDICTIONS.csv").open("w", newline="", encoding="utf-8-sig") as f:
            w = csv.DictWriter(f, fieldnames=list(case_lines[0]))
            w.writeheader(); w.writerows(case_lines)
    print(json.dumps({"output": str(OUT), "n_union": len(latest | older), "test": {k: outputs['EXPOSED_TEST'][k] for k in ('n','raw','coverage','auto_handled_error_count','raw_urgent_undertriage_count','routed_urgent_undertriage_count')},
                      "calibration_temperature_diagnostic": temp}, indent=2))


if __name__ == "__main__":
    main()
