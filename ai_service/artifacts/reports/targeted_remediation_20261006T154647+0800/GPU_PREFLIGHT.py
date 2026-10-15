from datetime import datetime
from pathlib import Path
from types import SimpleNamespace
import json
import sys
import time

import torch
from transformers import AutoModelForSequenceClassification, AutoTokenizer
from transformers.optimization import Adafactor

sys.path.insert(0, str(Path(__file__).resolve().parents[3]))
from src.ai_service.text_training import LABELS, experiment_splits, load_dataset, optimizer_step_with_amp_accounting, select_rows


ROOT = Path.cwd() / "ai_service"
REPORT = ROOT / "artifacts/reports/targeted_remediation_20261006T154647+0800"
LOCK = REPORT / "GPU_LOCK.json"
OUTPUT = REPORT / "GPU_PREFLIGHT.json"
DATA = ROOT / "artifacts/reports/xlmr_850_humanconfirmed_fulltrain_20261006_1411/model_data/model_development.csv"
SPLIT = ROOT / "artifacts/reports/xlmr_850_humanconfirmed_fulltrain_20261006_1411/training_split_manifest.json"
BASE_MODEL = "FacebookAI/xlm-roberta-base"
REVISION = "42f548f32366559214515ec137cdd16002968bf6"


def main():
    started = time.perf_counter()
    result = {"started_local": datetime.now().astimezone().isoformat(), "base_model": BASE_MODEL, "revision": REVISION, "status": "running"}
    lock_owned = False
    try:
        with LOCK.open("x", encoding="utf-8") as handle:
            json.dump({"pid": __import__("os").getpid(), "task": "full-backbone CUDA smoke test", "acquired_local": result["started_local"]}, handle)
        lock_owned = True
        if not torch.cuda.is_available():
            raise RuntimeError("CUDA unavailable")
        torch.manual_seed(42)
        torch.set_num_threads(2)
        device = torch.device("cuda")
        rows = select_rows(load_dataset(DATA), allow_draft=False)
        splits = experiment_splits(rows, SimpleNamespace(split_manifest=str(SPLIT)))
        sample = splits["train"][0]
        tokenizer = AutoTokenizer.from_pretrained(BASE_MODEL, revision=REVISION, trust_remote_code=False, local_files_only=True)
        model = AutoModelForSequenceClassification.from_pretrained(
            BASE_MODEL, revision=REVISION, num_labels=len(LABELS),
            id2label=dict(enumerate(LABELS)), label2id={label: index for index, label in enumerate(LABELS)},
            trust_remote_code=False, local_files_only=True,
        ).to(device)
        model.gradient_checkpointing_enable(gradient_checkpointing_kwargs={"use_reentrant": False})
        model.config.use_cache = False
        total = sum(parameter.numel() for parameter in model.parameters())
        trainable = sum(parameter.numel() for parameter in model.parameters() if parameter.requires_grad)
        if total != trainable:
            raise RuntimeError(f"Backbone is not fully trainable: {trainable}/{total}")
        probe_parameter = model.base_model.encoder.layer[0].attention.self.query.weight
        before = float(probe_parameter[0, 0].detach().float().cpu())
        optimizer = Adafactor(
            (parameter for parameter in model.parameters() if parameter.requires_grad),
            lr=2e-5, scale_parameter=False, relative_step=False, warmup_init=False, weight_decay=0.0,
        )
        scheduler = torch.optim.lr_scheduler.LambdaLR(optimizer, lambda step: min(1.0, (step + 1) / 14))
        scaler = torch.amp.GradScaler("cuda", enabled=True)
        encoded = tokenizer(sample["text"], padding=True, truncation=True, max_length=64, return_tensors="pt")
        encoded = {key: value.to(device) for key, value in encoded.items()}
        target = torch.tensor([LABELS.index(sample["label"])], device=device)
        model.train()
        optimizer.zero_grad(set_to_none=True)
        torch.cuda.reset_peak_memory_stats(device)
        with torch.autocast(device_type="cuda", dtype=torch.float16):
            logits = model(**encoded).logits
        loss = torch.nn.functional.cross_entropy(logits.float(), target)
        if not torch.isfinite(loss):
            raise RuntimeError("Smoke loss is non-finite")
        scaler.scale(loss).backward()
        scaler.unscale_(optimizer)
        gradient_norm = torch.nn.utils.clip_grad_norm_(model.parameters(), 1.0)
        backbone_grad_count = sum(parameter.grad is not None for parameter in model.base_model.parameters())
        all_grad_count = sum(parameter.grad is not None for parameter in model.parameters())
        gradient_norm_value = float(gradient_norm.detach().float().cpu())
        if not torch.isfinite(gradient_norm) or gradient_norm_value <= 0 or backbone_grad_count == 0:
            raise RuntimeError("No finite non-zero backbone gradient was observed")
        step = optimizer_step_with_amp_accounting(scaler, optimizer, scheduler)
        after = float(probe_parameter[0, 0].detach().float().cpu())
        if step["optimizer_step_skipped"] or before == after:
            raise RuntimeError("Optimizer smoke step was skipped or sampled backbone weight did not update")
        result.update({
            "status": "PASS", "train_case_id": sample["case_id"], "train_label": sample["label"],
            "total_parameters": total, "trainable_parameters": trainable, "frozen_parameters": total - trainable,
            "backbone_gradient_parameter_count": backbone_grad_count, "all_gradient_parameter_count": all_grad_count,
            "global_gradient_norm_before_clip": gradient_norm_value, "optimizer_step": step,
            "sampled_encoder_weight_changed": True, "learning_rate_after_step": optimizer.param_groups[0]["lr"],
            "peak_cuda_allocated_bytes": torch.cuda.max_memory_allocated(device),
            "peak_cuda_reserved_bytes": torch.cuda.max_memory_reserved(device),
            "duration_seconds": round(time.perf_counter() - started, 3),
        })
        del model, optimizer, scheduler, scaler
        torch.cuda.empty_cache()
    except Exception as error:
        result.update({"status": "FAILED", "error": f"{type(error).__name__}: {error}", "duration_seconds": round(time.perf_counter() - started, 3)})
    finally:
        result["finished_local"] = datetime.now().astimezone().isoformat()
        OUTPUT.write_text(json.dumps(result, indent=2) + "\n", encoding="utf-8")
        if lock_owned:
            LOCK.unlink(missing_ok=True)
        print(json.dumps(result, indent=2))
    return 0 if result["status"] == "PASS" else 1


if __name__ == "__main__":
    raise SystemExit(main())
