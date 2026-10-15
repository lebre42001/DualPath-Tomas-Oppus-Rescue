from __future__ import annotations

import hashlib
import json
import zipfile
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
RUN = Path(__file__).resolve().parent
CHECKPOINT = ROOT / "ai_service/artifacts/checkpoints/text_xlmr_targeted_20261006T154647+0800/seed42"
SOURCE = ROOT / "ai_service/artifacts/reports/xlmr_850_humanconfirmed_fulltrain_20261006_1411/model_data/model_development.csv"
SPLIT = ROOT / "ai_service/artifacts/reports/xlmr_850_humanconfirmed_fulltrain_20261006_1411/training_split_manifest.json"
PACKAGE_DIR = ROOT / "ai_service/artifacts/packages"
PACKAGE_DIR.mkdir(parents=True, exist_ok=True)
PACKAGE = PACKAGE_DIR / "emergency_text_xlmr_targeted_20261006T154647+0800_research_only.zip"
MANIFEST_NAME = "RESEARCH_PACKAGE_MANIFEST.json"


def digest(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


files: list[tuple[Path, str]] = []
for p in RUN.rglob("*"):
    if not p.is_file() or "__pycache__" in p.parts:
        continue
    if p == PACKAGE:
        continue
    files.append((p, f"run/{p.relative_to(RUN).as_posix()}"))
for p in CHECKPOINT.rglob("*"):
    if p.is_file():
        files.append((p, f"checkpoint/seed42/{p.relative_to(CHECKPOINT).as_posix()}"))
files.extend([
    (SOURCE, "data/model_development.csv"),
    (SPLIT, "data/frozen_training_split_manifest.json"),
    (ROOT / "ai_service/src/ai_service/text_training.py", "source/ai_service/src/ai_service/text_training.py"),
    (ROOT / "ai_service/tests/test_text_training.py", "source/ai_service/tests/test_text_training.py"),
    (ROOT / "ai_service/requirements.txt", "source/ai_service/requirements.txt"),
])
dedup = {archive_name: path for path, archive_name in files}
file_records = [
    {"path": name, "size_bytes": path.stat().st_size, "sha256": digest(path)}
    for name, path in sorted(dedup.items())
]
manifest = {
    "package_id": "emergency-text-xlmr-targeted-20261006T154647+0800",
    "classification": "RESEARCH_ONLY_NOT_DEPLOYABLE",
    "created_at_local": datetime.now().astimezone().isoformat(),
    "active_selector_changed": False,
    "contains_credentials": False,
    "contains_personal_reports": False,
    "dataset_provenance": "Synthetic-origin, owner-attested batch; not independently corroborated responder testimony; prior project development exposure remains.",
    "model_status": "NO_ELIGIBLE_CHECKPOINT; not approved for deployment",
    "files": file_records,
}
with zipfile.ZipFile(PACKAGE, "w", compression=zipfile.ZIP_STORED, allowZip64=True) as z:
    for archive_name, path in sorted(dedup.items()):
        z.write(path, archive_name)
    z.writestr(MANIFEST_NAME, json.dumps(manifest, indent=2, ensure_ascii=False) + "\n")

with zipfile.ZipFile(PACKAGE, "r") as z:
    bad = z.testzip()
    if bad:
        raise RuntimeError(f"ZIP CRC validation failed at {bad}")
    embedded = json.loads(z.read(MANIFEST_NAME).decode("utf-8"))
    if len(embedded["files"]) != len(file_records):
        raise RuntimeError("ZIP manifest row count mismatch")
    names = set(z.namelist())
    if any(Path(n).name.lower() == ".env" or Path(n).name.lower().startswith(".env.") for n in names):
        raise RuntimeError("Unexpected environment/secrets file in research ZIP")

receipt = {
    "package": str(PACKAGE.relative_to(ROOT)),
    "classification": "RESEARCH_ONLY_NOT_DEPLOYABLE",
    "sha256": digest(PACKAGE),
    "size_bytes": PACKAGE.stat().st_size,
    "internal_manifest": MANIFEST_NAME,
    "zip_read_test": "PASS",
    "manifest_file_count": len(file_records),
    "model_weights_sha256": digest(CHECKPOINT / "model.safetensors"),
    "created_at_local": manifest["created_at_local"],
}
(PACKAGE_DIR / "emergency_text_xlmr_targeted_20261006T154647+0800_research_only.receipt.json").write_text(
    json.dumps(receipt, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
)
print(json.dumps(receipt, indent=2))
