"""Verify one completed experimental candidate offline; never activate it.

Run only after training exits. The script loads one model, reads the original
cached tokenizer without a second model, and writes only its verification report.
Protected configuration contents are never printed or copied into the report.
"""
from __future__ import annotations

from collections import Counter
import gc
import hashlib
import json
import math
import os
from pathlib import Path
import statistics
import sys
import time


ROOT = Path(__file__).resolve().parents[3]
REPOSITORY = ROOT.parent
REPORT_DIRECTORY = Path(__file__).resolve().parent
CANDIDATE = ROOT / "artifacts/checkpoints/text_synthetic_9000_mbert_full_20260920"
DATA = ROOT / "datasets/emergency_text/experiments/synthetic_9000words_20260920/reports.csv"
ORIGINAL_REVISION = "3f076fdb1ab68d5b2880cb87a0886f315b8146f8"
ORIGINAL_TOKENIZER = (
    Path("C:/Users/Christian/.cache/huggingface/hub")
    / "models--google-bert--bert-base-multilingual-cased/snapshots"
    / ORIGINAL_REVISION / "tokenizer.json"
)
EXPECTED_PARAMETERS = 177_856_516
sys.path.insert(0, str(ROOT))


def require(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def read_json(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def file_hash(path: Path) -> str:
    result = hashlib.sha256()
    with path.open("rb") as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b""):
            result.update(block)
    return result.hexdigest()


def protected_files_unchanged(preflight: dict) -> dict[str, bool]:
    result = {}
    migration = json.loads((ROOT / "artifacts/reports/text_workflow_20260920/migration.json").read_text(encoding="utf-8-sig"))
    relocated = {item["old"].replace("\\", "/"): item["new"].replace("\\", "/")
                 for item in migration["moved_files"]}
    for relative, expected in preflight["protected_file_hashes"].items():
        normalized = relative.replace("\\", "/")
        path = (REPOSITORY / relocated.get(normalized, normalized)).resolve()
        require(path.is_relative_to(REPOSITORY.resolve()), "Protected path leaves the repository")
        require(path.is_file(), f"Protected file is missing: {relative}")
        result[relative] = file_hash(path) == expected
    require(all(result.values()), "A protected file differs from preflight; no contents have been printed")
    return result


def close_values(actual, expected, path: str = "metrics") -> None:
    """Compare all reconstructed metrics, tolerating only floating point noise."""
    if isinstance(actual, dict):
        require(isinstance(expected, dict), f"Expected a dictionary at {path}")
        for key, value in actual.items():
            require(key in expected, f"Missing saved metric: {path}.{key}")
            close_values(value, expected[key], f"{path}.{key}")
    elif isinstance(actual, list):
        require(isinstance(expected, list) and len(actual) == len(expected), f"List mismatch at {path}")
        for index, (left, right) in enumerate(zip(actual, expected, strict=True)):
            close_values(left, right, f"{path}[{index}]")
    elif isinstance(actual, float):
        require(isinstance(expected, (float, int)) and math.isclose(actual, expected, abs_tol=1e-9, rel_tol=1e-9), f"Numeric mismatch at {path}")
    else:
        require(actual == expected, f"Value mismatch at {path}")


def checked_splits(rows: list[dict], manifest: dict, preflight: dict) -> tuple[dict, dict]:
    from src.ai_service.text_training import dataset_fingerprint, digest, normalized_text

    by_id = {row["case_id"]: row for row in rows}
    splits, summaries = {}, {}
    sets = {kind: {} for kind in ("case_ids", "groups", "texts")}
    for name in ("train", "validation", "test"):
        declared = manifest[name]
        identifiers = declared["case_ids"]
        require(len(identifiers) == len(set(identifiers)), f"Duplicate case IDs in {name}")
        require(all(identifier in by_id for identifier in identifiers), f"Unknown case ID in {name}")
        selected = sorted((by_id[identifier] for identifier in identifiers), key=lambda row: row["case_id"])
        groups = {row["group_id"] for row in selected}
        texts = {digest(normalized_text(row["text"])) for row in selected}
        require(sorted(digest(group) for group in groups) == declared["group_hashes"], f"Group hash mismatch in {name}")
        require(sorted(texts) == declared["text_hashes"], f"Text hash mismatch in {name}")
        require(dataset_fingerprint(selected) == declared["summary"]["dataset_fingerprint"], f"Split fingerprint mismatch in {name}")
        summary = {
            "rows": len(selected), "groups": len(groups),
            "languages": dict(Counter(row["language"] for row in selected)),
            "labels": dict(Counter(row["label"] for row in selected)),
        }
        require(summary == preflight["splits"][name], f"Preflight split mismatch in {name}")
        splits[name], summaries[name] = selected, summary
        sets["case_ids"][name], sets["groups"][name], sets["texts"][name] = set(identifiers), groups, texts
    for kind, split_sets in sets.items():
        for first, second in (("train", "validation"), ("train", "test"), ("validation", "test")):
            require(not split_sets[first].intersection(split_sets[second]), f"{kind} leakage between {first} and {second}")
    require(set.union(*sets["case_ids"].values()) == set(by_id), "Split case IDs do not cover the source dataset")
    return splits, summaries


def main() -> int:
    # These process-local settings prevent downloads; no live AI flags are changed.
    os.environ["HF_HUB_OFFLINE"] = "1"
    os.environ["TRANSFORMERS_OFFLINE"] = "1"
    require(os.getenv("AI_TEXT_DEVICE", "cpu") == "cpu", "Run this CPU verification with AI_TEXT_DEVICE unset or cpu")

    from src.ai_service.text_inference import (
        TEXT_LABELS, TextModelUnavailable, TextSeverityPredictor, prediction_payload, read_metadata,
    )
    from src.ai_service.text_training import (
        dataset_fingerprint, load_dataset, prediction_metrics,
    )

    preflight = read_json(REPORT_DIRECTORY / "preflight.json")
    protected_before = protected_files_unchanged(preflight)
    metadata = read_json(CANDIDATE / "metadata.json")
    manifest = read_json(CANDIDATE / "split_manifest.json")
    history = read_json(CANDIDATE / "training_history.json")
    saved_metrics = read_json(CANDIDATE / "test_metrics.json")
    rows = load_dataset(DATA)
    fingerprint = dataset_fingerprint(rows)

    require(metadata["backbone_frozen"] is False, "Checkpoint was not trained with the full backbone")
    require(metadata["epochs"] == 3, "Expected three training epochs")
    require([item["epoch"] for item in history["epochs"]] == [1, 2, 3], "Training history does not contain three completed epochs")
    require(history["selection_metric"] == "validation_raw_macro_f1", "Checkpoint selection metric changed")
    require(metadata["best_epoch"] in (1, 2, 3), "Invalid selected epoch")
    require(metadata["total_parameters"] == EXPECTED_PARAMETERS, "Unexpected metadata parameter count")
    require(metadata["trainable_parameters"] == EXPECTED_PARAMETERS, "Not all expected parameters were trained")
    require(metadata["approved_for_deployment"] is False, "Candidate unexpectedly approved for deployment")
    require(metadata["all_data_reviewed"] is False and metadata["allow_draft"] is True, "Expected explicitly unreviewed draft training")
    require(metadata["deployment_status"] == "experimental", "Candidate must remain experimental")
    require(metadata["confidence_calibrated"] is False, "Uncalibrated draft scores must not be called calibrated")
    require(all(row["review_status"] == "draft" for row in rows), "Generated rows must remain draft")
    require(fingerprint == metadata["dataset_fingerprint"] == preflight["dataset_fingerprint"], "Dataset fingerprint changed")
    require(len(rows) == preflight["rows"] == 292, "Unexpected source row count")
    require(metadata["truncated_rows_by_split"] == {"train": 0, "validation": 0, "test": 0}, "Unexpected truncated examples")
    splits, split_summaries = checked_splits(rows, manifest, preflight)
    require(saved_metrics["model_version"] == metadata["version"], "Saved test metrics refer to another model")

    # Include the checkpoint bytes in the integrity check, without loading them twice.
    artifact_hashes_before = {
        path.name: file_hash(path) for path in CANDIDATE.iterdir() if path.is_file()
    }
    try:
        read_metadata(CANDIDATE, allow_experimental=False)
    except TextModelUnavailable:
        release_gate_blocks = True
    else:
        raise AssertionError("The ordinary release gate accepted an unreviewed candidate")

    import torch
    from tokenizers import Tokenizer

    torch.set_num_threads(2)
    started = time.perf_counter()
    predictor = TextSeverityPredictor(CANDIDATE, allow_experimental=True)
    load_seconds = time.perf_counter() - started
    try:
        parameter_count = sum(parameter.numel() for parameter in predictor.model.parameters())
        require(parameter_count == EXPECTED_PARAMETERS, "Loaded model parameter count differs")
        require(predictor.tokenizer.__class__.__name__ == "BertTokenizerFast", "Expected the BERT fast tokenizer")
        original_tokenizer = Tokenizer.from_file(str(ORIGINAL_TOKENIZER))
        require(original_tokenizer.pre_tokenizer.__class__.__name__ == "BertPreTokenizer", "Unexpected original pre-tokenizer")
        token_counts = []
        for row in rows:
            original_ids = original_tokenizer.encode(row["text"], add_special_tokens=True).ids
            candidate_ids = predictor.tokenizer.encode(row["text"], add_special_tokens=True, truncation=False)
            require(candidate_ids == original_ids, f"Original tokenizer encoding differs for {row['case_id']}")
            token_counts.append(len(candidate_ids))
        require(max(token_counts) == preflight["max_tokens"], "Maximum token count differs from preflight")
        require(max(token_counts) <= metadata["max_length"], "A source description exceeds the trained context")

        predictions, vectors = [], []
        for row in splits["test"]:
            started = time.perf_counter()
            result = predictor.predict(row["text"])
            latency = time.perf_counter() - started
            require(result["deployment_status"] == "experimental", "Prediction failed to disclose its experimental status")
            require(result["responder_review_required"] is True, "Experimental output must require responder verification")
            require(result["confidence_calibrated"] is False, "Prediction incorrectly labels its score as calibrated")
            require(set(result["probabilities"]) == set(TEXT_LABELS), "A fitting test description unexpectedly abstained without scores")
            vector = [result["probabilities"][label] for label in TEXT_LABELS]
            vectors.append(vector)
            predictions.append({
                "case_id": row["case_id"], "language": row["language"], "draft_label": row["label"],
                "latency_seconds": latency, "prediction": result,
            })

        reconstructed = prediction_metrics(splits["test"], vectors, metadata["review_threshold"])
        close_values(reconstructed, saved_metrics)
        raw_correct = sum(row["draft_label"] == row["prediction"]["predicted_label"] for row in predictions)
        require(math.isclose(raw_correct / len(predictions), saved_metrics["raw_classifier"]["accuracy"], abs_tol=1e-9), "Payload raw predictions differ from saved test accuracy")
        per_language = {}
        for language in ("en", "tl", "ceb", "mixed"):
            indices = [i for i, row in enumerate(splits["test"]) if row["language"] == language]
            if not indices:
                per_language[language] = {"count": 0, "raw_classifier": None, "review_routed": None, "median_inference_seconds": None}
                continue
            language_metrics = prediction_metrics(
                [splits["test"][i] for i in indices], [vectors[i] for i in indices], metadata["review_threshold"],
            )
            per_language[language] = {
                "count": len(indices), "raw_classifier": language_metrics["raw_classifier"],
                "review_routed": language_metrics["review_routed"], "coverage": language_metrics["coverage"],
                "raw_high_risk_undertriage_rate": language_metrics["raw_high_risk_undertriage_rate"],
                "high_risk_undertriage_rate": language_metrics["high_risk_undertriage_rate"],
                "high_risk_review_rate": language_metrics["high_risk_review_rate"],
                "median_inference_seconds": statistics.median(predictions[i]["latency_seconds"] for i in indices),
            }

        overlength = predictor.predict("accident " * (metadata["max_length"] + 20))
        require(overlength["severity"] is None and overlength["confidence"] is None, "Overlength text must not receive severity or confidence")
        require(overlength["probabilities"] == {} and overlength["status"] == "needs_review", "Overlength text must abstain without fabricated probabilities")
        require(overlength["responder_review_required"] is True, "Overlength text requires review")
        uniform = prediction_payload(dict.fromkeys(TEXT_LABELS, 0.25), predictor.metadata)
        require(uniform["severity"] is None and uniform["status"] == "needs_review", "Low-score payload must abstain")
        review_class = prediction_payload(dict(zip(TEXT_LABELS, (0.01, 0.01, 0.01, 0.97), strict=True)), predictor.metadata)
        require(review_class["severity"] is None and review_class["status"] == "needs_review", "The review label must never be treated as a severity")
    finally:
        del predictor
        gc.collect()

    artifact_hashes_after = {
        path.name: file_hash(path) for path in CANDIDATE.iterdir() if path.is_file()
    }
    require(artifact_hashes_before == artifact_hashes_after, "Verification changed a checkpoint artifact")
    protected_after = protected_files_unchanged(preflight)
    require(protected_before == protected_after, "Verification changed a protected file")
    report = {
        "candidate": CANDIDATE.name, "synthetic_unreviewed_data": True,
        "approved_for_deployment": False, "dataset_fingerprint": fingerprint,
        "full_backbone_training_verified": True, "epochs_completed": 3,
        "total_parameters": parameter_count, "trainable_parameters_in_training_metadata": metadata["trainable_parameters"],
        "split_summaries": split_summaries, "group_and_exact_text_split_overlap_count": 0,
        "release_gate_blocks": release_gate_blocks, "checkpoint_artifacts_unchanged": True,
        "protected_files_unchanged": protected_after,
        "tokenizer_verification": {
            "tokenizer_class": "BertTokenizerFast", "original_revision": ORIGINAL_REVISION,
            "all_source_encodings_match": True, "rows_checked": len(rows), "max_tokens": max(token_counts),
            "mistral_regex_modification_applied": False,
        },
        "saved_test_metrics_reproduced": True, "test_count": len(predictions), "test_raw_correct": raw_correct,
        "test_metrics_reconstructed": reconstructed, "per_language": per_language,
        "checkpoint_load_seconds": load_seconds, "inference_threads": 2,
        "median_inference_seconds": statistics.median(row["latency_seconds"] for row in predictions),
        "latency_scope": "Offline CPU single-description calls; includes tokenization, excludes checkpoint loading; not HTTP latency",
        "overlength_null_abstention_passed": True, "low_score_abstention_passed": True,
        "review_label_abstention_passed": True, "predictions": predictions,
        "limitations": [
            "Synthetic draft labels are not responder approval or field validation",
            "Group/exact-text disjointness does not prove semantic independence",
            "Per-language denominators are small; model scores are uncalibrated",
            "The final test set has now been inspected and is not a fresh future release holdout",
        ],
    }
    output = REPORT_DIRECTORY / "candidate_verification.json"
    output.write_text(json.dumps(report, ensure_ascii=False, indent=2, allow_nan=False) + "\n", encoding="utf-8")
    print(json.dumps({
        "output": str(output), "candidate": report["candidate"], "test_count": len(predictions),
        "test_raw_correct": raw_correct, "test_raw_macro_f1": reconstructed["raw_classifier"]["macro_f1"],
        "test_coverage": reconstructed["coverage"], "saved_test_metrics_reproduced": True,
        "tokenizer_rows_verified": len(rows), "protected_files_unchanged": True,
        "median_inference_seconds": report["median_inference_seconds"], "approved_for_deployment": False,
    }, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
