from __future__ import annotations

import hashlib
import json
import zipfile
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
RUN = Path(__file__).resolve().parent
PACKAGE_DIR = ROOT / "ai_service/artifacts/packages"
PACKAGE_DIR.mkdir(parents=True, exist_ok=True)
PACKAGE = PACKAGE_DIR / "manual_only_release_source_20261006T154647+0800.zip"
MANIFEST_NAME = "MANUAL_ONLY_RELEASE_MANIFEST.json"
SKIP_DIRS = {".git", ".github", ".cache", "node_modules", "vendor", "__pycache__", ".pytest_cache", ".mypy_cache"}


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def safe_web_file(path: Path) -> bool:
    rel = path.relative_to(ROOT / "web_system")
    parts = set(rel.parts)
    if parts & SKIP_DIRS:
        return False
    lower_name = path.name.lower()
    if lower_name == ".env" or (lower_name.startswith(".env.") and lower_name not in {".env.example", ".env.render.example"}):
        return False
    if "storage" in parts or "public" in parts and ("build" in parts or "storage" in parts):
        return False
    if "database" in parts and lower_name.endswith((".sqlite", ".sqlite3", ".db")):
        return False
    if "bootstrap" in parts and "cache" in parts and lower_name.endswith(".php"):
        return False
    if lower_name.endswith((".pyc", ".log")) or lower_name in {"npm-debug.log", "yarn-error.log"}:
        return False
    return True


def safe_ai_file(path: Path) -> bool:
    rel = path.relative_to(ROOT / "ai_service")
    if set(rel.parts) & SKIP_DIRS:
        return False
    if rel.parts[0] in {".venv", "datasets", "artifacts"} or rel.parts[0].startswith(".venv"):
        return False
    if "runtime" in rel.parts or path.suffix.lower() in {".pyc", ".log"}:
        return False
    lower_name = path.name.lower()
    if lower_name == ".env" or (lower_name.startswith(".env.") and lower_name not in {".env.example", ".env.render.example"}):
        return False
    return True


entries: dict[str, Path] = {}
for p in (ROOT / "web_system").rglob("*"):
    if p.is_file() and safe_web_file(p):
        entries[f"web_system/{p.relative_to(ROOT / 'web_system').as_posix()}"] = p
for p in (ROOT / "ai_service").rglob("*"):
    if p.is_file() and safe_ai_file(p):
        entries[f"ai_service/{p.relative_to(ROOT / 'ai_service').as_posix()}"] = p
for p in [ROOT / "render.yaml", ROOT / "RELEASE_MANUAL_ONLY.md", ROOT / "ROLLBACK.md"]:
    if p.is_file():
        entries[p.relative_to(ROOT).as_posix()] = p
for rel in [
    "web_system/tests",
    "ai_service/tests",
]:
    test_root = ROOT / rel
    for p in test_root.rglob("*"):
        if p.is_file() and "__pycache__" not in p.parts and p.suffix not in {".pyc", ".log"}:
            entries[f"qa/{rel}/{p.relative_to(test_root).as_posix()}"] = p
manual_test_report = RUN / "MANUAL_MODE_TEST_REPORT.json"
if manual_test_report.is_file():
    entries["release_evidence/MANUAL_MODE_TEST_REPORT.json"] = manual_test_report

for name in entries:
    base = Path(name).name.lower()
    if base == ".env" or base.startswith(".env.") and base not in {".env.example", ".env.render.example"}:
        raise RuntimeError(f"Potential secret file is not allowed in release source package: {name}")

created = datetime.now().astimezone().isoformat()
manifest = {
    "package_id": "manual-only-release-source-20261006T154647+0800",
    "classification": "SOURCE_ONLY_NOT_BUILT_NOT_STAGED_NOT_DEPLOYED",
    "created_at_local": created,
    "application_mode": {"laravel": "AI_TEXT_MODE=MANUAL_ONLY", "fastapi": "AI_OPERATION_MODE=MANUAL_ONLY"},
    "contains_credentials": False,
    "contains_user_uploads_or_local_databases": False,
    "contains_training_datasets_or_model_checkpoints": False,
    "build_verified": False,
    "build_blocker": "Docker executable unavailable in this environment; isolated staging target/database not configured.",
    "files": [
        {"path": name, "size_bytes": path.stat().st_size, "sha256": sha256(path)}
        for name, path in sorted(entries.items())
    ],
}
with zipfile.ZipFile(PACKAGE, "w", compression=zipfile.ZIP_STORED, allowZip64=True) as z:
    for name, path in sorted(entries.items()):
        z.write(path, name)
    z.writestr(MANIFEST_NAME, json.dumps(manifest, indent=2, ensure_ascii=False) + "\n")
with zipfile.ZipFile(PACKAGE, "r") as z:
    bad = z.testzip()
    if bad:
        raise RuntimeError(f"ZIP CRC validation failed at {bad}")
    contents = z.namelist()
    if any(Path(name).name.lower() == ".env" or Path(name).name.lower().startswith(".env.") and Path(name).name.lower() not in {".env.example", ".env.render.example"} for name in contents):
        raise RuntimeError("A non-template environment file is present")
    if any(name.startswith("ai_service/datasets/") or name.startswith("ai_service/artifacts/") or "/storage/" in name or name.endswith((".sqlite", ".sqlite3", ".db")) for name in contents):
        raise RuntimeError("Local data, storage or checkpoint leaked into release package")
receipt = {
    "package": str(PACKAGE.relative_to(ROOT)),
    "classification": manifest["classification"],
    "sha256": sha256(PACKAGE),
    "size_bytes": PACKAGE.stat().st_size,
    "internal_manifest": MANIFEST_NAME,
    "zip_read_test": "PASS",
    "manifest_file_count": len(entries),
    "secret_and_private_data_exclusion_check": "PASS",
    "docker_build": "NOT_RUN; docker executable unavailable",
    "staging": "NOT_RUN; isolated target not configured",
    "created_at_local": created,
}
(PACKAGE_DIR / "manual_only_release_source_20261006T154647+0800.receipt.json").write_text(
    json.dumps(receipt, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
)
print(json.dumps(receipt, indent=2))
