"""Post-selection calibration and slice analysis for the synthetic 2026-10-05 run.

This script does not modify model weights or choose a checkpoint. Calibration
metrics and threshold sweeps use calibration.csv only. Fresh-test/challenge
metrics are descriptive and cannot be used for model selection.
"""
from __future__ import annotations

import json
import math
from pathlib import Path
import sys

import torch
from transformers import AutoModelForSequenceClassification, AutoTokenizer

AI_ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(AI_ROOT))
from src.ai_service.text_training import (  # noqa: E402
    LABELS,
    _transformer_predictor,
    load_dataset,
    prediction_metrics,
    select_rows,
)

ROOT = Path(__file__).resolve().parent
DATA = AI_ROOT / "datasets/emergency_text/experiments/civilian_multilingual_6000w_20261005"
MODEL = AI_ROOT / "artifacts/checkpoints/text_xlmr_civilian_multilingual_20261005/trial_lr_2e-5_seed42"
MAX_LENGTH = 64
DEVICE = torch.device("cuda" if torch.cuda.is_available() else "cpu")


def write_json(path: Path, data: dict) -> None:
    path.write_text(json.dumps(data, ensure_ascii=False, indent=2, allow_nan=False) + "\n", encoding="utf-8")


def nll(labels: list[int], probs: list[list[float]]) -> float:
    return -sum(math.log(max(probs[i][labels[i]], 1e-12)) for i in range(len(labels))) / len(labels)


def brier(labels: list[int], probs: list[list[float]]) -> float:
    return sum(sum((probs[i][j] - (1.0 if j == labels[i] else 0.0)) ** 2 for j in range(len(LABELS))) for i in range(len(labels))) / len(labels)


def ece(labels: list[int], probs: list[list[float]], bins: int = 10) -> dict:
    rows = []
    total = len(labels)
    error = 0.0
    for b in range(bins):
        low, high = b / bins, (b + 1) / bins
        indexes = [i for i, p in enumerate(probs) if low <= max(p) < high or (b == bins - 1 and max(p) == 1.0)]
        if indexes:
            conf = sum(max(probs[i]) for i in indexes) / len(indexes)
            acc = sum(max(range(len(LABELS)), key=lambda j: probs[i][j]) == labels[i] for i in indexes) / len(indexes)
            error += len(indexes) / total * abs(acc - conf)
            rows.append({"lower": low, "upper": high, "count": len(indexes), "mean_confidence": conf, "accuracy": acc})
    return {"bins": bins, "ece": error, "by_bin": rows}


def main() -> None:
    metadata = json.loads((MODEL / "metadata.json").read_text(encoding="utf-8"))
    tokenizer = AutoTokenizer.from_pretrained(MODEL, local_files_only=True, trust_remote_code=False)
    model = AutoModelForSequenceClassification.from_pretrained(MODEL, local_files_only=True, trust_remote_code=False).to(DEVICE)
    predict = _transformer_predictor(model, tokenizer, DEVICE, MAX_LENGTH, 16)

    calibration = select_rows(load_dataset(DATA / "calibration.csv"), allow_draft=True)
    calibration_probs = predict([r["text"] for r in calibration])
    eligible = [(r, p) for r, p in zip(calibration, calibration_probs) if p is not None]
    labels = [LABELS.index(r["label"]) for r, _ in eligible]
    raw = [p for _, p in eligible]
    # A one-dimensional scalar temperature is fit on calibration only. Grid
    # search on log temperature avoids dependency/version-specific optimizers.
    candidates = [math.exp(-2 + 4 * i / 1000) for i in range(1001)]
    logp = torch.tensor([[math.log(max(v, 1e-12)) for v in p] for p in raw], dtype=torch.float64)
    target = torch.tensor(labels, dtype=torch.long)
    losses = [float(torch.nn.functional.cross_entropy(logp / t, target).item()) for t in candidates]
    temperature = candidates[min(range(len(candidates)), key=losses.__getitem__)]
    calibrated = torch.softmax(logp / temperature, dim=1).tolist()

    calibration_report = {
        "checkpoint": str(MODEL),
        "method": "scalar temperature scaling; grid search minimizing calibration negative log-likelihood",
        "temperature": temperature,
        "support": len(eligible),
        "calibration_only_fit": True,
        "raw": {"nll": nll(labels, raw), "brier_multiclass": brier(labels, raw), "top_label_ece_10_bins": ece(labels, raw)},
        "temperature_scaled": {"nll": nll(labels, calibrated), "brier_multiclass": brier(labels, calibrated), "top_label_ece_10_bins": ece(labels, calibrated)},
        "routing_sweep_calibration_only": [],
        "warning": "83 synthetic draft rows; calibration statistics are exploratory and do not establish reliable probability calibration.",
    }
    for threshold in (0.50, 0.60, 0.65, 0.70, 0.80, 0.90):
        metrics = prediction_metrics([r for r, _ in eligible], calibrated, threshold)
        calibration_report["routing_sweep_calibration_only"].append({
            "threshold": threshold,
            "coverage": metrics["coverage"],
            "review_routing_percent": metrics["abstention_rate"],
            "critical_routed_to_review": sum(1 for i, (r, p) in enumerate(eligible) if r["label"] == "critical" and (p is None or max(calibrated[i]) < threshold)),
            "serious_routed_to_review": sum(1 for i, (r, p) in enumerate(eligible) if r["label"] == "serious" and (p is None or max(calibrated[i]) < threshold)),
            "routed_high_risk_undertriage": metrics["high_risk_undertriage_count"],
        })
    write_json(ROOT / "CALIBRATION_METRICS.json", calibration_report)

    slice_results = {}
    for name in ("fresh_test", "challenge"):
        rows = select_rows(load_dataset(DATA / f"{name}.csv"), allow_draft=True)
        probs = predict([r["text"] for r in rows])
        groups = {}
        for dimension, get_value in (
            ("language", lambda r: r["language"]),
            ("label", lambda r: r["label"]),
            ("length_bucket", lambda r: (lambda n: "1-3" if n <= 3 else "4-8" if n <= 8 else "9-15" if n <= 15 else "16+")(len(r["text"].split()))),
        ):
            values = sorted({get_value(r) for r in rows})
            groups[dimension] = {}
            for value in values:
                indexes = [i for i, r in enumerate(rows) if get_value(r) == value]
                selected_rows = [rows[i] for i in indexes]
                selected_probs = [probs[i] for i in indexes]
                m = prediction_metrics(selected_rows, selected_probs, 0.65)
                groups[dimension][value] = {
                    "rows": len(indexes),
                    "families": len({r["group_id"] for r in selected_rows}),
                    "raw_macro_f1": m["raw_classifier"]["macro_f1"],
                    "routed_macro_f1_at_provisional_0_65": m["review_routed"]["macro_f1"],
                    "critical_recall": m["raw_classifier"]["per_class"]["critical"]["recall"],
                    "serious_recall": m["raw_classifier"]["per_class"]["serious"]["recall"],
                    "needs_review_recall": m["raw_classifier"]["per_class"]["needs_review"]["recall"],
                    "raw_high_risk_undertriage": m["raw_high_risk_undertriage_count"],
                    "routed_high_risk_undertriage": m["high_risk_undertriage_count"],
                    "coverage_at_provisional_0_65": m["coverage"],
                }
        slice_results[name] = groups
    write_json(ROOT / "LANGUAGE_LENGTH_SLICE_METRICS.json", slice_results)


if __name__ == "__main__":
    main()
