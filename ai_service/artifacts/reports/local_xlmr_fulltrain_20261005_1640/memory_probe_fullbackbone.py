import csv
import gc
import json
import time
import torch
from transformers import AutoModelForSequenceClassification, AutoTokenizer

MODEL = "FacebookAI/xlm-roberta-base"
REVISION = "42f548f32366559214515ec137cdd16002968bf6"
MAX_LENGTH = 96
LABELS = ["minor", "serious", "critical", "needs_review"]
CSV_PATH = "ai_service/datasets/emergency_text/experiments/xlmr_fulltrain_20261005_1628_pht/reports.csv"


def one_step(optimizer_kind: str) -> dict:
    started = time.perf_counter()
    print(json.dumps({"event": "probe_start", "optimizer": optimizer_kind}), flush=True)
    model = AutoModelForSequenceClassification.from_pretrained(
        MODEL, revision=REVISION, num_labels=len(LABELS),
        id2label=dict(enumerate(LABELS)),
        label2id={label: index for index, label in enumerate(LABELS)},
        low_cpu_mem_usage=True,
        trust_remote_code=False,
    )
    for parameter in model.parameters():
        parameter.requires_grad = True
    model.gradient_checkpointing_enable(gradient_checkpointing_kwargs={"use_reentrant": False})
    if hasattr(model.config, "use_cache"):
        model.config.use_cache = False
    model.to("cuda")
    total = sum(p.numel() for p in model.parameters())
    trainable = sum(p.numel() for p in model.parameters() if p.requires_grad)
    if optimizer_kind == "adamw":
        optimizer = torch.optim.AdamW(model.parameters(), lr=1e-5, weight_decay=0.01, foreach=False)
    else:
        from transformers.optimization import Adafactor
        optimizer = Adafactor(model.parameters(), lr=1e-5, scale_parameter=False,
                              relative_step=False, warmup_init=False, weight_decay=0.0)
    tokenizer = AutoTokenizer.from_pretrained(MODEL, revision=REVISION, use_fast=True, trust_remote_code=False)
    with open(CSV_PATH, encoding="utf-8-sig", newline="") as handle:
        rows = list(csv.DictReader(handle))
    row = max(rows, key=lambda item: len(tokenizer.encode(item["text"], add_special_tokens=True)))
    tokens = tokenizer(row["text"], add_special_tokens=True, truncation=False, return_tensors="pt")["input_ids"]
    length = min(MAX_LENGTH, tokens.shape[1])
    ids = torch.full((1, MAX_LENGTH), tokenizer.pad_token_id, dtype=torch.long)
    mask = torch.zeros((1, MAX_LENGTH), dtype=torch.long)
    ids[0, :length] = tokens[0, :length]
    mask[0, :length] = 1
    ids, mask = ids.cuda(), mask.cuda()
    labels = torch.tensor([LABELS.index(row["label"])], device="cuda")
    torch.cuda.empty_cache()
    torch.cuda.reset_peak_memory_stats()
    optimizer.zero_grad(set_to_none=True)
    print(json.dumps({"event": "forward_backward_start", "optimizer": optimizer_kind,
                      "trainable_parameters": trainable, "vram_free_bytes": torch.cuda.mem_get_info()[0]}), flush=True)
    with torch.autocast(device_type="cuda", dtype=torch.float16):
        logits = model(input_ids=ids, attention_mask=mask).logits
        loss = torch.nn.functional.cross_entropy(logits, labels)
    loss.backward()
    optimizer.step()
    torch.cuda.synchronize()
    result = {
        "optimizer": optimizer_kind,
        "status": "one_full_backbone_update_passed",
        "total_parameters": total,
        "trainable_parameters": trainable,
        "frozen_parameters": total - trainable,
        "trainable_percentage": 100 * trainable / total,
        "input_shape": list(ids.shape),
        "actual_text_tokens": int(length),
        "loss_finite": bool(torch.isfinite(loss).item()),
        "peak_allocated_bytes": torch.cuda.max_memory_allocated(),
        "peak_reserved_bytes": torch.cuda.max_memory_reserved(),
        "elapsed_seconds": round(time.perf_counter() - started, 2),
    }
    del model, optimizer, logits, loss, ids, mask, labels, tokens, tokenizer, rows, row
    gc.collect()
    torch.cuda.empty_cache()
    return result


results = []
for kind in ("adamw", "adafactor"):
    try:
        results.append(one_step(kind))
    except torch.cuda.OutOfMemoryError as error:
        results.append({"optimizer": kind, "status": "cuda_oom_during_full_backbone_step", "error": str(error)[:600]})
        gc.collect()
        torch.cuda.empty_cache()
    except Exception as error:
        results.append({"optimizer": kind, "status": "probe_error", "error": repr(error)[:600]})
        gc.collect()
        torch.cuda.empty_cache()
    if results[-1]["status"] == "probe_error":
        break

payload = {
    "base_model": MODEL,
    "revision": REVISION,
    "device": torch.cuda.get_device_name(0),
    "device_capability": list(torch.cuda.get_device_capability(0)),
    "total_vram_bytes": torch.cuda.get_device_properties(0).total_memory,
    "batch_size": 1,
    "max_length": MAX_LENGTH,
    "precision": "fp16_autocast",
    "gradient_checkpointing": True,
    "full_backbone_trainable": True,
    "results": results,
}
report_path = "ai_service/artifacts/reports/local_xlmr_fulltrain_20261005_1640/MEMORY_PROBE.json"
with open(report_path, "w", encoding="utf-8") as handle:
    json.dump(payload, handle, indent=2)
    handle.write("\n")
print(json.dumps(payload, indent=2), flush=True)
