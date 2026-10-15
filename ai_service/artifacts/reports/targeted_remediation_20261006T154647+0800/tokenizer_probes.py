"""CPU-only pinned-XLM-R tokenizer identity and multilingual probe check."""
from __future__ import annotations

import hashlib
import json
from pathlib import Path

from transformers import AutoTokenizer

ROOT = Path(__file__).resolve().parents[4]
CHECKPOINT = ROOT / "ai_service/artifacts/checkpoints/text_xlmr_targeted_20261006T154647+0800/seed42"
BASE_MODEL = "FacebookAI/xlm-roberta-base"
REVISION = "42f548f32366559214515ec137cdd16002968bf6"
PROBES = [
    ("ceb_negation_person_nonresponse", "Ang pasahero dili motubag human mabangga ang motorsiklo."),
    ("ceb_current_bleeding", "Kusog ang dugo nga nag-agas karon sa samad sa drayber."),
    ("ceb_no_breathing", "Walay ginhawa ang pasahero; wala siya motubag."),
    ("tl_negation_person_nonresponse", "Hindi sumasagot ang pasahero matapos mabangga ang motorsiklo."),
    ("tl_controlled_bleeding", "Maliit na hiwa lamang at huminto na ang pagdurugo."),
    ("en_object_vs_person", "The phone is not responding, but the driver is awake and speaking."),
    ("mixed_timeline", "Walay ginhawa siya karon after sa crash; call ambulance now."),
    ("unicode_punctuation", "Naaksidente siya—gising pa, pero hindi na sumasagot ngayon."),
]

base = AutoTokenizer.from_pretrained(BASE_MODEL, revision=REVISION, local_files_only=True, trust_remote_code=False)
saved = AutoTokenizer.from_pretrained(CHECKPOINT, local_files_only=True, trust_remote_code=False)
results = []
for name, text in PROBES:
    base_ids = base.encode(text, add_special_tokens=True, truncation=False)
    saved_ids = saved.encode(text, add_special_tokens=True, truncation=False)
    results.append({
        "probe": name,
        "base_token_count": len(base_ids),
        "saved_token_count": len(saved_ids),
        "token_ids_equal": base_ids == saved_ids,
        "base_ids_sha256": hashlib.sha256(json.dumps(base_ids, separators=(",", ":")).encode()).hexdigest(),
        "saved_ids_sha256": hashlib.sha256(json.dumps(saved_ids, separators=(",", ":")).encode()).hexdigest(),
    })
report = {
    "base_model": BASE_MODEL,
    "revision": REVISION,
    "base_tokenizer_class": type(base).__name__,
    "saved_tokenizer_class": type(saved).__name__,
    "base_is_fast": bool(base.is_fast),
    "saved_is_fast": bool(saved.is_fast),
    "probe_count": len(results),
    "all_token_ids_equal": all(item["token_ids_equal"] for item in results),
    "probes": results,
    "scope_note": "Eight targeted strings compare pinned base tokenizer with saved tokenizer. This does not prove correctness on all future text or establish language fluency. The library's generic Mistral regex warning was not patched because both classes identify as XLM-R tokenizers and no mismatch was observed.",
}
output = Path(__file__).with_name("TOKENIZER_PROBES.json")
output.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
print(json.dumps(report, indent=2))
if not report["all_token_ids_equal"]:
    raise SystemExit(1)
