"""Offline verification of the two explicitly experimental September 19 candidates."""
import csv
import gc
import json
import statistics
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT))
import torch
from src.ai_service.text_inference import TextSeverityPredictor, TextModelUnavailable, read_metadata

torch.set_num_threads(2)
rows = list(csv.DictReader((ROOT / "datasets/emergency_text/archive/initial_80/draft_reports.csv").open(encoding="utf-8-sig")))
results = []
for name in ("text_mbert_draft_head_20260919", "text_mbert_draft_full_20260919"):
    directory = ROOT / "artifacts/checkpoints" / name
    metadata = json.loads((directory / "metadata.json").read_text())
    manifest = json.loads((directory / "split_manifest.json").read_text())
    assert metadata["approved_for_deployment"] is False
    assert metadata["all_data_reviewed"] is False
    try:
        read_metadata(directory, allow_experimental=False)
    except TextModelUnavailable:
        pass
    else:
        raise AssertionError("Unreviewed candidate passed the release gate")
    started = time.perf_counter()
    predictor = TextSeverityPredictor(directory, allow_experimental=True)
    load_seconds = time.perf_counter() - started
    predictions = []
    for row in sorted(rows, key=lambda r: r["case_id"]):
        if row["case_id"] not in manifest["test"]["case_ids"]:
            continue
        started = time.perf_counter()
        prediction = predictor.predict(row["text"])
        elapsed = time.perf_counter() - started
        assert prediction["deployment_status"] == "experimental"
        assert prediction["responder_review_required"] is True
        predictions.append({"case_id": row["case_id"], "language": row["language"],
            "draft_label": row["label"], "description": row["text"],
            "latency_seconds": elapsed, "prediction": prediction})
    too_long = predictor.predict("accident " * 150)
    assert too_long["severity"] is None and too_long["confidence"] is None
    assert too_long["probabilities"] == {} and too_long["responder_review_required"]
    test_metrics = json.loads((directory / "test_metrics.json").read_text())
    correct = sum(p["draft_label"] == p["prediction"]["predicted_label"] for p in predictions)
    assert abs(correct / len(predictions) - test_metrics["raw_classifier"]["accuracy"]) < 1e-8
    results.append({"candidate": name, "release_gate_blocks": True,
        "overlength_review_passed": True, "checkpoint_load_seconds": load_seconds,
        "parameter_count": sum(p.numel() for p in predictor.model.parameters()),
        "inference_threads": 2, "test_raw_correct": correct, "test_count": len(predictions),
        "median_inference_seconds": statistics.median(p["latency_seconds"] for p in predictions),
        "per_language_raw": {language: {
            "count": sum(p["language"] == language for p in predictions),
            "correct": sum(p["language"] == language and p["draft_label"] == p["prediction"]["predicted_label"] for p in predictions)}
            for language in ("en", "tl", "ceb", "mixed")},
        "predictions": predictions})
    del predictor
    gc.collect()
output = Path(__file__).with_name("candidate_verification.json")
output.write_text(json.dumps({"synthetic_unreviewed_data": True, "candidates": results}, indent=2) + "\n", encoding="utf-8")
print(json.dumps([{k: v for k, v in r.items() if k != "predictions"} for r in results], indent=2))
