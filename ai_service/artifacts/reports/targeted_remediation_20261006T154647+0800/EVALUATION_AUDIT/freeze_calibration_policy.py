"""Fit and freeze calibration-only diagnostic policy before exposed-set scoring."""
from __future__ import annotations

import json
from datetime import datetime, timezone
from pathlib import Path

import audit_evaluations as audit

def main() -> None:
    pred_path = audit.PRED_ROOT / "CALIBRATION_DIAGNOSTIC/evaluation_predictions.json"
    source_path = audit.SOURCE / "calibration.csv"
    saved = json.loads(pred_path.read_text(encoding="utf-8"))
    source = {r["case_id"]: r for r in audit.read_csv(source_path)}
    rows = [source[p["case_id"]] for p in saved["rows"]]
    probs = [[float(x) for x in p["probabilities"]] for p in saved["rows"]]
    labels = [r["label"] for r in rows]
    opt = audit.minimize_scalar(lambda t: audit.cal_stats(labels, audit.probabilities_temperature(probs, t))["nll"],
                                bounds=(0.05, 10), method="bounded", options={"xatol": 1e-7})
    temperature = float(opt.x)
    scaled = audit.probabilities_temperature(probs, temperature)
    candidates = []
    for cents in range(0, 101):
        threshold = cents / 100
        result = audit.metrics_for(rows, scaled, threshold)
        if result["routed_urgent_undertriage_count"] == 0:
            candidates.append((result["coverage"], -threshold, threshold, result))
    if not candidates:
        raise RuntimeError("No candidate threshold has zero calibration auto-undertriage; no point can be frozen")
    _, _, selected_threshold, selected_metrics = max(candidates)
    policy = {
        "policy_id": "calibration-only-diagnostic-temperature-threshold-20261006T154647+0800",
        "frozen_at_utc": datetime.now(timezone.utc).isoformat(),
        "temperature": temperature,
        "temperature_fit": "minimum multiclass NLL by bounded scalar optimization over calibration-only log probabilities, T in [0.05,10]",
        "threshold": audit.THRESHOLD,
        "threshold_selection": "unchanged existing protocol diagnostic threshold .65; no threshold tuning/selection",
        "research_threshold": selected_threshold,
        "research_threshold_selection_rule": "among thresholds 0.00 through 1.00 inclusive in 0.01 increments applied to temperature-scaled calibration probabilities, select highest calibration coverage with zero routed Serious/Critical undertriage; tie-break lower threshold",
        "research_threshold_calibration_result": {"coverage": selected_metrics["coverage"], "accepted_count": selected_metrics["accepted_count"],
                                                   "routed_urgent_undertriage_count": selected_metrics["routed_urgent_undertriage_count"],
                                                   "auto_handled_error_count": selected_metrics["auto_handled_error_count"],
                                                   "urgent_support": selected_metrics["urgent_support_serious_plus_critical"]},
        "intended_use": "research diagnostic comparisons only; no operational recommendation or active model metadata change",
        "calibration_n": len(rows), "calibration_fingerprint": saved["dataset_fingerprint"],
        "calibration_prediction_sha256": audit.sha(pred_path), "calibration_csv_sha256": audit.sha(source_path),
        "calibration_raw_quality": audit.cal_stats(labels, probs),
        "calibration_temperature_scaled_quality": audit.cal_stats(labels, audit.probabilities_temperature(probs, temperature)),
        "exposed_test_or_challenge_read_before_freeze": True,
        "sequencing_note": "A preliminary exposed-set audit was already inspected under the initial calibration-only T/.65 freeze. This second threshold freeze is selected mechanically from calibration-only evidence under the explicitly recorded rule, without using exposed-set results; all subsequent final reporting is recomputed after this freeze. Because preliminary exposed metrics were already seen, this is not confirmatory evaluation.",
    }
    out = audit.OUT / "FROZEN_DIAGNOSTIC_POLICY_V2.json"
    if out.exists():
        raise FileExistsError(f"Refusing to overwrite frozen policy: {out}")
    out.write_text(json.dumps(policy, ensure_ascii=False, indent=2, allow_nan=False) + "\n", encoding="utf-8")
    print(json.dumps({"frozen_policy": str(out), "temperature": temperature, "research_threshold": selected_threshold,
                      "research_threshold_calibration_coverage": selected_metrics["coverage"],
                      "research_threshold_calibration_auto_undertriage": selected_metrics["routed_urgent_undertriage_count"],
                      "calibration_nll_before": policy["calibration_raw_quality"]["nll"],
                      "calibration_nll_after": policy["calibration_temperature_scaled_quality"]["nll"],
                      "threshold": audit.THRESHOLD}, indent=2))

if __name__ == "__main__":
    main()
