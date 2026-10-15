"""Run the single frozen XLM-R trial under an exclusive per-run GPU lock."""
from __future__ import annotations

import json
import subprocess
import sys
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
REPORT = Path(__file__).resolve().parent
LOCK = REPORT / "GPU_LOCK.json"
COMMAND = [
    sys.executable,
    str(ROOT / "ai_service" / "train_text.py"),
    "train",
    "--data", str(ROOT / "ai_service/artifacts/reports/xlmr_850_humanconfirmed_fulltrain_20261006_1411/model_data/model_development.csv"),
    "--output", str(ROOT / "ai_service/artifacts/checkpoints/text_xlmr_targeted_20261006T154647+0800/seed42"),
    "--seed", "42",
    "--split-manifest", str(ROOT / "ai_service/artifacts/reports/xlmr_850_humanconfirmed_fulltrain_20261006_1411/training_split_manifest.json"),
    "--base-model", "FacebookAI/xlm-roberta-base",
    "--revision", "42f548f32366559214515ec137cdd16002968bf6",
    "--epochs", "8",
    "--early-stopping-patience", "2",
    "--selection-metric", "balanced_safety",
    "--batch-size", "1",
    "--gradient-accumulation", "16",
    "--max-length", "64",
    "--unfreeze-backbone",
    "--gradient-checkpointing",
    "--optimizer", "adafactor",
    "--warmup-ratio", "0.05",
    "--learning-rate", "2e-5",
    "--threads", "2",
    "--device", "cuda",
    "--review-threshold", "0.65",
]

try:
    with LOCK.open("x", encoding="utf-8") as stream:
        json.dump({"owner": "targeted_remediation_seed42", "started_local": datetime.now().astimezone().isoformat()}, stream)
        stream.write("\n")
except FileExistsError as error:
    raise SystemExit(f"GPU lock already exists; refusing concurrent GPU work: {LOCK}") from error

try:
    print("GPU lock acquired:", LOCK, flush=True)
    print("Command:", subprocess.list2cmdline(COMMAND), flush=True)
    result = subprocess.run(COMMAND, cwd=ROOT, check=False)
    raise SystemExit(result.returncode)
finally:
    LOCK.unlink(missing_ok=True)
