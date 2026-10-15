"""Validate a returned Colab XLM-R run without activating its checkpoint."""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import zipfile


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def read_json(path: Path) -> dict:
    if not path.is_file():
        raise ValueError(f"Missing required file: {path}")
    return json.loads(path.read_text(encoding="utf-8"))


def validate(run_root: Path, bundle: Path | None = None) -> dict:
    run_root = run_root.resolve()
    complete = read_json(run_root / "RUN_COMPLETE.json")
    acceptance = read_json(run_root / "ACCEPTANCE_REPORT.json")
    best = read_json(run_root / "BEST_CHECKPOINT.json")
    if not complete.get("complete"):
        raise ValueError("Colab run is not marked complete")
    if complete.get("package_id") != acceptance.get("package_id"):
        raise ValueError("Package ID mismatch between run receipts")
    if any(item.get("approved_for_deployment") is not False for item in (complete, acceptance)):
        raise ValueError("Training artifacts must not self-approve deployment")
    if any(item.get("active_model_changed") is not False for item in (complete, acceptance)):
        raise ValueError("Training artifacts must not claim an active model change")

    best_seed = best.get("seed")
    if best_seed not in (41, 42, 43) or complete.get("best_seed") != best_seed:
        raise ValueError("Best seed is missing or inconsistent")
    model = run_root / f"xlmr_seed_{best_seed}"
    required = (
        "config.json", "model.safetensors", "tokenizer.json", "metadata.json",
        "split_manifest.json", "validation_metrics.json", "training_history.json",
    )
    missing = [name for name in required if not (model / name).is_file()]
    if missing:
        raise ValueError(f"Best checkpoint is incomplete: {', '.join(missing)}")
    metadata = read_json(model / "metadata.json")
    if metadata.get("base_model") != "FacebookAI/xlm-roberta-base":
        raise ValueError("Unexpected base model")
    if metadata.get("backbone_frozen") is not False:
        raise ValueError("Checkpoint is not a full-backbone fine-tune")
    if metadata.get("approved_for_deployment") is not False:
        raise ValueError("Checkpoint metadata improperly claims deployment approval")
    evaluation = read_json(run_root / "best_model_test_evaluation" / "evaluation_metrics.json")
    if evaluation.get("split") != "test":
        raise ValueError("Best model was not evaluated on the saved test split")

    bundle_result = None
    if bundle is not None:
        bundle = bundle.resolve()
        with zipfile.ZipFile(bundle) as archive:
            manifest = json.loads(archive.read("package_manifest.json"))
        receipt = read_json(run_root / "RUN_RECEIPT.json")
        if receipt.get("bundle_sha256") != sha256(bundle):
            raise ValueError("Returned run does not match the supplied bundle")
        if manifest.get("dataset_fingerprint") != metadata.get("dataset_fingerprint"):
            raise ValueError("Checkpoint dataset fingerprint differs from the sealed bundle")
        bundle_result = {"sha256": sha256(bundle), "dataset_fingerprint_match": True}

    return {
        "status": "validated_not_activated",
        "package_id": complete["package_id"],
        "best_seed": best_seed,
        "best_checkpoint": str(model),
        "best_checkpoint_model_sha256": sha256(model / "model.safetensors"),
        "test_macro_f1": acceptance["test_metrics"]["macro_f1"],
        "test_critical_recall": acceptance["test_metrics"]["critical_recall"],
        "all_data_reviewed": acceptance["all_data_reviewed"],
        "metrics_passed": acceptance["metrics_passed"],
        "bundle": bundle_result,
        "active_model_changed": False,
        "approved_for_deployment": False,
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Validate a downloaded Colab emergency-text run.")
    parser.add_argument("--run-root", type=Path, required=True)
    parser.add_argument("--bundle", type=Path)
    args = parser.parse_args(argv)
    try:
        print(json.dumps(validate(args.run_root, args.bundle), ensure_ascii=False, indent=2, allow_nan=False))
        return 0
    except (ValueError, OSError, KeyError, json.JSONDecodeError, zipfile.BadZipFile) as error:
        raise SystemExit(f"error: {error}")


if __name__ == "__main__":
    raise SystemExit(main())
