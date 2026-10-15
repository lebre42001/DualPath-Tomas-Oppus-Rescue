"""Run one-at-a-time GPU diagnostics after frozen validation selection."""
from __future__ import annotations

import json
import subprocess
import sys
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
REPORT = Path(__file__).resolve().parent
LOCK = REPORT / "GPU_LOCK.json"
RUN = "20261006T154647+0800"
MODEL = ROOT / f"ai_service/artifacts/checkpoints/text_xlmr_targeted_{RUN}/seed42"
BASE = ROOT / "ai_service/artifacts/reports/xlmr_850_humanconfirmed_fulltrain_20261006_1411"
JOBS = [
    ("EXPOSED_TEST", BASE / "model_data/model_development.csv", "test"),
    ("CALIBRATION_DIAGNOSTIC", BASE / "frozen_splits/calibration.csv", "external"),
    ("EXPOSED_CHALLENGE", BASE / "frozen_splits/challenge.csv", "external"),
]

try:
    with LOCK.open("x", encoding="utf-8") as stream:
        json.dump({"owner": "post-selection-sequential-evaluation", "started_local": datetime.now().astimezone().isoformat()}, stream)
        stream.write("\n")
except FileExistsError as error:
    raise SystemExit(f"GPU lock already exists; refusing concurrent GPU work: {LOCK}") from error

try:
    evaluator = ROOT / "ai_service/train_text.py"
    for name, data, split in JOBS:
        output = REPORT / "EVALUATIONS_WITH_PREDICTIONS" / name
        if (output / "evaluation_metrics.json").is_file():
            raise SystemExit(f"Refusing to overwrite completed evaluation: {output}")
        output.parent.mkdir(parents=True, exist_ok=True)
        command = [
            sys.executable, str(evaluator), "evaluate", "--data", str(data),
            "--model", str(MODEL), "--output", str(output), "--split", split,
            "--batch-size", "16", "--device", "cuda",
        ]
        print(f"START {name}: {subprocess.list2cmdline(command)}", flush=True)
        result = subprocess.run(command, cwd=ROOT, check=False)
        if result.returncode:
            raise SystemExit(f"Evaluation {name} failed with exit code {result.returncode}")
        print(f"DONE {name}", flush=True)
finally:
    LOCK.unlink(missing_ok=True)
