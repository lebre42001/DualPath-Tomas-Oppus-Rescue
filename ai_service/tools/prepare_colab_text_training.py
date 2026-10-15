"""Build a sealed Google Colab package for emergency-text XLM-R training.

The package never changes active_config.txt and never claims deployment approval.
The default package intentionally includes the current draft dataset only as a
provisional research experiment.
"""
from __future__ import annotations

import argparse
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import shutil
import sys
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parents[2]
AI_ROOT = ROOT / "ai_service"
sys.path.insert(0, str(AI_ROOT))

from src.ai_service.text_training import (
    _manifest,
    dataset_fingerprint,
    dataset_summary,
    load_dataset,
    select_rows,
    split_groups,
)


DEFAULT_DATA = AI_ROOT / "datasets" / "emergency_text" / "working" / "reports.csv"
DEFAULT_OUTPUT = AI_ROOT / "artifacts" / "colab_packages" / "emergency_text_xlmr_provisional_20261004"
DEFAULT_NOTEBOOK = AI_ROOT / "notebooks" / "EMERGENCY_TEXT_XLMR_COLAB.ipynb"
PACKAGE_ID = "emergency-text-xlmr-provisional-20261004"
ZIP_NAME = "emergency_text_xlmr_provisional_20261004.zip"
BASE_MODEL = "FacebookAI/xlm-roberta-base"
BASE_MODEL_REVISION = "42f548f32366559214515ec137cdd16002968bf6"


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def write_json(path: Path, value: object) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2, allow_nan=False) + "\n", encoding="utf-8")


def display_path(path: Path) -> str:
    try:
        return path.resolve().relative_to(ROOT).as_posix()
    except ValueError:
        return str(path.resolve())


def text_cell(cell_type: str, source: str) -> dict:
    cell = {"cell_type": cell_type, "metadata": {}, "source": source.splitlines(keepends=True)}
    if cell_type == "code":
        cell.update({"execution_count": None, "outputs": []})
    return cell


def build_notebook(bundle_sha256: str, package_manifest_sha256: str) -> dict:
    settings = f'''# Fixed, audited experiment settings. Do not change labels or split after training starts.
from pathlib import Path
from datetime import datetime, timezone
import hashlib, json, os, shutil, subprocess, sys, zipfile

PACKAGE_ID = "{PACKAGE_ID}"
BUNDLE_NAME = "{ZIP_NAME}"
EXPECTED_BUNDLE_SHA256 = "{bundle_sha256}"
EXPECTED_MANIFEST_SHA256 = "{package_manifest_sha256}"
BASE_MODEL = "{BASE_MODEL}"
BASE_MODEL_REVISION = "{BASE_MODEL_REVISION}"
SEEDS = [41, 42, 43]
EPOCHS = 8
EARLY_STOPPING_PATIENCE = 2
BATCH_SIZE = 8
GRADIENT_ACCUMULATION = 2
MAX_LENGTH = 128
LEARNING_RATE = 2e-5
REVIEW_THRESHOLD = 0.65
USE_GOOGLE_DRIVE = True
RUN_ID = PACKAGE_ID

def file_sha256(path):
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()
'''
    upload = '''from google.colab import files

uploaded = files.upload()
if BUNDLE_NAME not in uploaded:
    raise RuntimeError(f"Upload the exact bundle named {BUNDLE_NAME}")
bundle_path = Path("/content") / BUNDLE_NAME
bundle_path.write_bytes(uploaded[BUNDLE_NAME])
actual_bundle_sha256 = file_sha256(bundle_path)
if actual_bundle_sha256 != EXPECTED_BUNDLE_SHA256:
    raise RuntimeError("Bundle SHA-256 mismatch; stop and rebuild the package")

BUNDLE_DIR = Path("/content/emergency_text_xlmr_bundle")
if BUNDLE_DIR.exists():
    shutil.rmtree(BUNDLE_DIR)
BUNDLE_DIR.mkdir(parents=True)
with zipfile.ZipFile(bundle_path) as archive:
    root = BUNDLE_DIR.resolve()
    for member in archive.infolist():
        destination = (BUNDLE_DIR / member.filename).resolve()
        if root not in destination.parents and destination != root:
            raise RuntimeError("Unsafe path in ZIP package")
    archive.extractall(BUNDLE_DIR)

manifest_path = BUNDLE_DIR / "package_manifest.json"
if file_sha256(manifest_path) != EXPECTED_MANIFEST_SHA256:
    raise RuntimeError("Package manifest checksum mismatch")
manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
for relative, expected in manifest["files_sha256"].items():
    path = BUNDLE_DIR / relative
    if not path.is_file() or file_sha256(path) != expected:
        raise RuntimeError(f"Missing or changed package file: {relative}")
print(json.dumps({"package": PACKAGE_ID, "bundle_verified": True, "dataset": manifest["dataset"]}, indent=2))
'''
    install = '''subprocess.run([
    sys.executable, "-m", "pip", "install", "--quiet", "--disable-pip-version-check",
    "-r", str(BUNDLE_DIR / "requirements-text-colab.txt")
], check=True)

import torch, transformers, sklearn, safetensors
if not torch.cuda.is_available():
    raise RuntimeError("GPU is required. In Colab choose Runtime > Change runtime type > GPU, then run again.")
print({
    "gpu": torch.cuda.get_device_name(0),
    "torch": torch.__version__,
    "transformers": transformers.__version__,
    "scikit_learn": sklearn.__version__,
    "safetensors": safetensors.__version__,
})
'''
    drive_setup = '''if USE_GOOGLE_DRIVE:
    from google.colab import drive
    drive.mount("/content/drive")
    OUTPUT_BASE = Path("/content/drive/MyDrive/emergency_text_xlmr_runs")
else:
    OUTPUT_BASE = Path("/content/emergency_text_xlmr_runs")

RUN_ROOT = OUTPUT_BASE / RUN_ID
RUN_ROOT.mkdir(parents=True, exist_ok=True)
receipt_path = RUN_ROOT / "RUN_RECEIPT.json"
receipt = {
    "package_id": PACKAGE_ID,
    "bundle_sha256": EXPECTED_BUNDLE_SHA256,
    "mode": manifest["mode"],
    "started_at": datetime.now(timezone.utc).isoformat(),
    "active_model_changed": False,
    "approved_for_deployment": False,
}
if receipt_path.exists():
    existing = json.loads(receipt_path.read_text(encoding="utf-8"))
    if existing.get("bundle_sha256") != EXPECTED_BUNDLE_SHA256:
        raise RuntimeError("RUN_ID already belongs to a different package")
else:
    receipt_path.write_text(json.dumps(receipt, indent=2) + "\\n", encoding="utf-8")
print("Run output:", RUN_ROOT)
'''
    preflight = '''DATA = BUNDLE_DIR / "data/reports.csv"
SPLIT = BUNDLE_DIR / "data/frozen_split_manifest.json"
TRAIN = BUNDLE_DIR / "train_text.py"

subprocess.run([sys.executable, str(TRAIN), "validate", "--data", str(DATA)], cwd=BUNDLE_DIR, check=True)

split = json.loads(SPLIT.read_text(encoding="utf-8"))
group_sets = [set(split[name]["group_hashes"]) for name in ("train", "validation", "test")]
text_sets = [set(split[name]["text_hashes"]) for name in ("train", "validation", "test")]
if any(group_sets[i] & group_sets[j] for i in range(3) for j in range(i + 1, 3)):
    raise RuntimeError("Frozen split has group leakage")
if any(text_sets[i] & text_sets[j] for i in range(3) for j in range(i + 1, 3)):
    raise RuntimeError("Frozen split has normalized-text leakage")
print({name: split[name]["summary"] for name in ("train", "validation", "test")})
'''
    helpers = '''def run_checked(command):
    print("RUN:", " ".join(map(str, command)))
    subprocess.run([str(item) for item in command], cwd=BUNDLE_DIR, check=True)

def completed_model(path):
    required = ["metadata.json", "split_manifest.json", "validation_metrics.json"]
    return all((path / name).is_file() for name in required)

def run_then_publish(command, final_output):
    if completed_model(final_output):
        print("Already complete; skipping:", final_output)
        return
    if final_output.exists() and any(final_output.iterdir()):
        raise RuntimeError(f"Incomplete output exists and was not overwritten: {final_output}")
    local_output = Path("/content/current_training_output")
    if local_output.exists():
        shutil.rmtree(local_output)
    command = [str(local_output) if str(item) == "__OUTPUT__" else item for item in command]
    run_checked(command)
    if not completed_model(local_output):
        raise RuntimeError("Training command finished without a complete artifact")
    shutil.copytree(local_output, final_output)
    shutil.rmtree(local_output)
'''
    baseline = '''baseline_output = RUN_ROOT / "baseline_seed_42"
run_then_publish([
    sys.executable, TRAIN, "baseline", "--data", DATA, "--output", "__OUTPUT__",
    "--allow-draft", "--seed", "42", "--split-manifest", SPLIT,
    "--review-threshold", str(REVIEW_THRESHOLD)
], baseline_output)
print(json.loads((baseline_output / "validation_metrics.json").read_text(encoding="utf-8"))["raw_classifier"])
'''
    training = '''for seed in SEEDS:
    final_output = RUN_ROOT / f"xlmr_seed_{seed}"
    run_then_publish([
        sys.executable, TRAIN, "train", "--data", DATA, "--output", "__OUTPUT__",
        "--allow-draft", "--seed", str(seed), "--split-manifest", SPLIT,
        "--base-model", BASE_MODEL, "--revision", BASE_MODEL_REVISION, "--epochs", str(EPOCHS),
        "--early-stopping-patience", str(EARLY_STOPPING_PATIENCE),
        "--selection-metric", "critical_recall_then_macro_f1",
        "--batch-size", str(BATCH_SIZE), "--gradient-accumulation", str(GRADIENT_ACCUMULATION),
        "--max-length", str(MAX_LENGTH), "--unfreeze-backbone",
        "--learning-rate", str(LEARNING_RATE), "--threads", "2", "--device", "cuda",
        "--review-threshold", str(REVIEW_THRESHOLD)
    ], final_output)
'''
    selection = '''candidates = []
for seed in SEEDS:
    path = RUN_ROOT / f"xlmr_seed_{seed}"
    metrics = json.loads((path / "validation_metrics.json").read_text(encoding="utf-8"))
    raw = metrics["raw_classifier"]
    score = (raw["per_class"]["critical"]["recall"], raw["macro_f1"])
    candidates.append((score, seed, path))
candidates.sort(reverse=True, key=lambda item: (item[0], -item[1]))
best_score, best_seed, best_model = candidates[0]
(RUN_ROOT / "BEST_CHECKPOINT.json").write_text(json.dumps({
    "seed": best_seed, "path": str(best_model),
    "validation_critical_recall": best_score[0], "validation_macro_f1": best_score[1],
    "selection_rule": "critical_recall_then_macro_f1",
}, indent=2) + "\\n", encoding="utf-8")

test_output = RUN_ROOT / "best_model_test_evaluation"
if not (test_output / "evaluation_metrics.json").is_file():
    if test_output.exists() and any(test_output.iterdir()):
        raise RuntimeError("Incomplete test evaluation exists and was not overwritten")
    run_checked([
        sys.executable, TRAIN, "evaluate", "--data", DATA, "--model", best_model,
        "--output", test_output, "--split", "test", "--allow-draft",
        "--batch-size", "16", "--device", "cuda"
    ])
print("Best checkpoint:", best_model)
'''
    acceptance = '''metrics = json.loads((test_output / "evaluation_metrics.json").read_text(encoding="utf-8"))
raw = metrics["raw_classifier"]
confusion = raw["confusion_matrix"]["rows_actual_columns_predicted"]
labels = raw["confusion_matrix"]["labels"]
serious_i, critical_i = labels.index("serious"), labels.index("critical")
urgent_support = sum(confusion[serious_i]) + sum(confusion[critical_i])
urgent_detected = sum(confusion[serious_i][i] + confusion[critical_i][i] for i in (serious_i, critical_i))
urgent_recall = urgent_detected / urgent_support if urgent_support else 0.0
prediction_rows = json.loads((test_output / "evaluation_predictions.json").read_text(encoding="utf-8"))["rows"]
def raw_macro_f1(selected_rows):
    actual = [row["label"] for row in selected_rows]
    predicted = [labels[max(range(len(row["probabilities"])), key=lambda i: row["probabilities"][i])] for row in selected_rows]
    if not actual:
        return None
    scores = []
    for label in labels:
        true_positive = sum(a == label and p == label for a, p in zip(actual, predicted))
        support = sum(a == label for a in actual)
        predicted_count = sum(p == label for p in predicted)
        precision = true_positive / predicted_count if predicted_count else 0.0
        recall = true_positive / support if support else 0.0
        scores.append(2 * precision * recall / (precision + recall) if precision + recall else 0.0)
    return sum(scores) / len(labels)

language_f1 = {
    language: raw_macro_f1([row for row in prediction_rows if row["language"] == language])
    for language in sorted({row["language"] for row in prediction_rows})
}
metric_checks = {
    "overall_macro_f1_at_least_0_80": raw["macro_f1"] >= 0.80,
    "critical_recall_at_least_0_95": raw["per_class"]["critical"]["recall"] >= 0.95,
    "serious_or_critical_detection_recall_at_least_0_90": urgent_recall >= 0.90,
    "every_observed_language_macro_f1_at_least_0_75": all(value >= 0.75 for value in language_f1.values()),
    "raw_high_risk_undertriage_is_zero": metrics["raw_high_risk_undertriage_count"] == 0,
}
all_data_reviewed = bool(metrics.get("all_data_reviewed"))
report = {
    "package_id": PACKAGE_ID,
    "mode": manifest["mode"],
    "best_seed": best_seed,
    "best_checkpoint": str(best_model),
    "test_metrics": {
        "macro_f1": raw["macro_f1"],
        "critical_recall": raw["per_class"]["critical"]["recall"],
        "serious_or_critical_detection_recall": urgent_recall,
        "per_language_macro_f1": language_f1,
        "raw_high_risk_undertriage_count": metrics["raw_high_risk_undertriage_count"],
    },
    "metric_checks": metric_checks,
    "all_data_reviewed": all_data_reviewed,
    "metrics_passed": all(metric_checks.values()),
    "approved_for_deployment": False,
    "active_model_changed": False,
    "status": "provisional_research_complete_not_deployable" if not all_data_reviewed else "reviewed_experiment_requires_human_promotion_decision",
    "completed_at": datetime.now(timezone.utc).isoformat(),
}
(RUN_ROOT / "ACCEPTANCE_REPORT.json").write_text(json.dumps(report, indent=2) + "\\n", encoding="utf-8")
(RUN_ROOT / "RUN_COMPLETE.json").write_text(json.dumps({
    "complete": True, "package_id": PACKAGE_ID, "best_seed": best_seed,
    "approved_for_deployment": False, "active_model_changed": False,
    "completed_at": datetime.now(timezone.utc).isoformat(),
}, indent=2) + "\\n", encoding="utf-8")
print(json.dumps(report, indent=2))
'''
    summary = '''summary_root = Path("/content/emergency_text_xlmr_summary")
if summary_root.exists():
    shutil.rmtree(summary_root)
summary_root.mkdir()
for path in RUN_ROOT.rglob("*.json"):
    relative = path.relative_to(RUN_ROOT)
    destination = summary_root / relative
    destination.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(path, destination)
summary_zip = shutil.make_archive("/content/emergency_text_xlmr_summary", "zip", summary_root)
print("Metrics summary ZIP:", summary_zip)
print("Full best model remains in Google Drive:", best_model)
'''
    return {
        "nbformat": 4,
        "nbformat_minor": 5,
        "metadata": {
            "colab": {"name": "EMERGENCY_TEXT_XLMR_COLAB.ipynb", "provenance": []},
            "kernelspec": {"display_name": "Python 3", "language": "python", "name": "python3"},
            "language_info": {"name": "python"},
            "accelerator": "GPU",
        },
        "cells": [
            text_cell("markdown", "# Emergency Text XLM-R Full Fine-Tuning\n\nRuns a sealed, family-disjoint multilingual road-accident text experiment. The included dataset is synthetic/draft, so every result remains provisional and the active model is never changed.\n"),
            text_cell("code", settings),
            text_cell("markdown", "## 1. Upload and verify the sealed bundle\n"),
            text_cell("code", upload),
            text_cell("markdown", "## 2. Install pinned-compatible dependencies and require a GPU\n"),
            text_cell("code", install),
            text_cell("markdown", "## 3. Mount Drive and create or resume this exact run\n"),
            text_cell("code", drive_setup),
            text_cell("markdown", "## 4. Validate dataset and frozen split before any training\n"),
            text_cell("code", preflight),
            text_cell("code", helpers),
            text_cell("markdown", "## 5. Train the required TF-IDF baseline\n"),
            text_cell("code", baseline),
            text_cell("markdown", "## 6. Full fine-tune XLM-R with three seeds\n\nCompleted seed directories are reused on rerun. An interrupted in-memory seed is repeated without overwriting a completed artifact.\n"),
            text_cell("code", training),
            text_cell("markdown", "## 7. Select on validation, then evaluate the untouched test split once\n"),
            text_cell("code", selection),
            text_cell("markdown", "## 8. Apply acceptance gates without activating or deploying the model\n"),
            text_cell("code", acceptance),
            text_cell("markdown", "## 9. Create a small metrics-only download\n"),
            text_cell("code", summary),
        ],
    }


def add_to_zip(archive: zipfile.ZipFile, path: Path, relative: str) -> None:
    info = zipfile.ZipInfo(relative, date_time=(2026, 10, 4, 0, 0, 0))
    info.compress_type = zipfile.ZIP_DEFLATED
    info.external_attr = 0o644 << 16
    archive.writestr(info, path.read_bytes())


def prepare(args: argparse.Namespace) -> dict:
    data_path = args.data.resolve()
    rows = load_dataset(data_path)
    allow_draft = args.mode == "provisional_research"
    selected = select_rows(rows, allow_draft=allow_draft)
    if args.mode == "reviewed_release":
        if not all(row["review_status"] == "approved" and row["reviewer"] for row in selected):
            raise ValueError("reviewed_release requires every selected row to be approved by an accountable reviewer")
        if len(selected) < args.minimum_reviewed:
            raise ValueError(f"reviewed_release requires at least {args.minimum_reviewed} reviewed rows")
    splits = split_groups(selected, seed=42, validation_fraction=0.15, test_fraction=0.15)

    output = args.output.resolve()
    notebook_path = args.notebook.resolve()
    if output.exists() and any(output.iterdir()):
        raise ValueError(f"Output directory must be new or empty: {output}")
    output.mkdir(parents=True, exist_ok=True)
    notebook_path.parent.mkdir(parents=True, exist_ok=True)

    with tempfile.TemporaryDirectory() as temporary:
        staging = Path(temporary)
        files = {
            "train_text.py": AI_ROOT / "train_text.py",
            "src/ai_service/__init__.py": AI_ROOT / "src" / "ai_service" / "__init__.py",
            "src/ai_service/text_training.py": AI_ROOT / "src" / "ai_service" / "text_training.py",
            "data/reports.csv": data_path,
            "data/provenance.json": data_path.parent / "provenance.json",
        }
        for relative, source in files.items():
            destination = staging / relative
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(source, destination)
        requirements = (
            "# Torch is supplied by the Colab GPU runtime and is version-checked in the notebook.\n"
            "transformers==4.57.6\n"
            "safetensors==0.8.0\n"
            "scikit-learn==1.9.1\n"
            "sentencepiece>=0.2,<1.0\n"
        )
        (staging / "requirements-text-colab.txt").write_text(requirements, encoding="utf-8")
        write_json(staging / "data" / "frozen_split_manifest.json", _manifest(splits))
        (staging / "README.txt").write_text(
            "Sealed Colab input package. This package is synthetic/draft and is only for provisional research.\n"
            "It cannot activate a checkpoint or establish production approval.\n",
            encoding="utf-8",
        )
        file_hashes = {
            path.relative_to(staging).as_posix(): sha256(path)
            for path in sorted(staging.rglob("*")) if path.is_file()
        }
        package_manifest = {
            "package_id": PACKAGE_ID,
            "created_at": datetime.now(timezone.utc).isoformat(),
            "mode": args.mode,
            "base_model": BASE_MODEL,
            "base_model_revision": BASE_MODEL_REVISION,
            "dataset": dataset_summary(selected),
            "dataset_fingerprint": dataset_fingerprint(selected),
            "source_data_sha256": sha256(data_path),
            "active_config_sha256_at_packaging": sha256(AI_ROOT / "active_config.txt"),
            "split_seed": 42,
            "split_fractions": {"train": 0.70, "validation": 0.15, "test": 0.15},
            "split_rows": {name: len(items) for name, items in splits.items()},
            "split_groups": {name: len({row["group_id"] for row in items}) for name, items in splits.items()},
            "labels_are_human_reviewed": all(row["review_status"] == "approved" for row in selected),
            "approved_for_deployment": False,
            "active_model_change_authorized": False,
            "files_sha256": file_hashes,
        }
        write_json(staging / "package_manifest.json", package_manifest)
        manifest_sha = sha256(staging / "package_manifest.json")
        zip_path = output / ZIP_NAME
        with zipfile.ZipFile(zip_path, "w") as archive:
            for path in sorted(staging.rglob("*")):
                if path.is_file():
                    add_to_zip(archive, path, path.relative_to(staging).as_posix())

    notebook = build_notebook(sha256(zip_path), manifest_sha)
    write_json(notebook_path, notebook)
    receipt = {
        "status": "colab_package_ready_not_executed",
        "package_id": PACKAGE_ID,
        "created_at": datetime.now(timezone.utc).isoformat(),
        "mode": args.mode,
        "bundle": display_path(zip_path),
        "bundle_sha256": sha256(zip_path),
        "notebook": display_path(notebook_path),
        "notebook_sha256": sha256(notebook_path),
        "dataset_rows": len(selected),
        "dataset_groups": len({row["group_id"] for row in selected}),
        "human_review_complete": all(row["review_status"] == "approved" for row in selected),
        "training_executed": False,
        "active_model_changed": False,
        "approved_for_deployment": False,
    }
    write_json(output / "PACKAGE_RECEIPT.json", receipt)
    return receipt


def refresh_notebook(output: Path, notebook_path: Path) -> dict:
    output = output.resolve()
    notebook_path = notebook_path.resolve()
    zip_path = output / ZIP_NAME
    receipt_path = output / "PACKAGE_RECEIPT.json"
    if not zip_path.is_file() or not receipt_path.is_file():
        raise ValueError("Existing bundle and PACKAGE_RECEIPT.json are required for notebook-only refresh")
    receipt = json.loads(receipt_path.read_text(encoding="utf-8"))
    bundle_sha = sha256(zip_path)
    if receipt.get("bundle_sha256") != bundle_sha:
        raise ValueError("Existing bundle does not match PACKAGE_RECEIPT.json")
    with zipfile.ZipFile(zip_path) as archive:
        manifest_bytes = archive.read("package_manifest.json")
    manifest_sha = hashlib.sha256(manifest_bytes).hexdigest()
    write_json(notebook_path, build_notebook(bundle_sha, manifest_sha))
    receipt["notebook_sha256"] = sha256(notebook_path)
    receipt["notebook_refreshed_at"] = datetime.now(timezone.utc).isoformat()
    write_json(receipt_path, receipt)
    return receipt


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Create a sealed emergency-text XLM-R Colab package.")
    parser.add_argument("--data", type=Path, default=DEFAULT_DATA)
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT)
    parser.add_argument("--notebook", type=Path, default=DEFAULT_NOTEBOOK)
    parser.add_argument("--mode", choices=("provisional_research", "reviewed_release"), default="provisional_research")
    parser.add_argument("--minimum-reviewed", type=int, default=800)
    parser.add_argument("--refresh-notebook-only", action="store_true")
    return parser


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    try:
        result = refresh_notebook(args.output, args.notebook) if args.refresh_notebook_only else prepare(args)
        print(json.dumps(result, ensure_ascii=False, indent=2, allow_nan=False))
        return 0
    except (ValueError, OSError, KeyError, zipfile.BadZipFile) as error:
        raise SystemExit(f"error: {error}")


if __name__ == "__main__":
    raise SystemExit(main())
