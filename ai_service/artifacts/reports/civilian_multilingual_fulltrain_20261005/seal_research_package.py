"""Build and verify an immutable ZIP of the selected research artifact set."""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
import zipfile

AI = Path(__file__).resolve().parents[3]
REPORT = Path(__file__).resolve().parent
DATASET = AI / "datasets/emergency_text/experiments/civilian_multilingual_6000w_20261005"
CHECKPOINT = AI / "artifacts/checkpoints/text_xlmr_civilian_multilingual_20261005/trial_lr_2e-5_seed42"
PACKAGE_DIR = AI / "artifacts/packages"
PACKAGE_DIR.mkdir(parents=True, exist_ok=True)
PACKAGE = PACKAGE_DIR / "civilian_multilingual_6000w_20261005_xlmr_research.zip"
RECEIPT = REPORT / "SEALED_PACKAGE_RECEIPT.json"


def file_hash(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def main() -> None:
    if PACKAGE.exists():
        # Replace only this task-created archive after proving its internal ID.
        with zipfile.ZipFile(PACKAGE, "r") as old_archive:
            old_manifest = json.loads(old_archive.read("PACKAGE_CONTENTS_MANIFEST.json"))
        if old_manifest.get("package_id") != "civilian-multilingual-6000w-20261005-xlmr-research":
            raise SystemExit(f"Refusing to overwrite unrelated package: {PACKAGE}")
        PACKAGE.unlink()
    members: list[tuple[Path, str]] = []
    for base, prefix in ((CHECKPOINT, "checkpoint"), (DATASET, "dataset"), (REPORT, "report")):
        for path in sorted(base.rglob("*")):
            if path.is_file() and path.resolve() != PACKAGE.resolve() and path.resolve() != RECEIPT.resolve():
                members.append((path, f"{prefix}/{path.relative_to(base).as_posix()}"))
    manifest = {
        "package_id": "civilian-multilingual-6000w-20261005-xlmr-research",
        "classification": "synthetic_draft_experiment_only_not_approved_for_deployment",
        "selected_checkpoint": str(CHECKPOINT),
        "active_model_changed": False,
        "deployment_performed": False,
        "member_count": len(members),
        "members_sha256": {arc: file_hash(path) for path, arc in members},
    }
    PACKAGE_TEMP = PACKAGE.with_suffix(".zip.tmp")
    with zipfile.ZipFile(PACKAGE_TEMP, "w", compression=zipfile.ZIP_STORED, allowZip64=True) as archive:
        archive.writestr("PACKAGE_CONTENTS_MANIFEST.json", json.dumps(manifest, ensure_ascii=False, indent=2) + "\n")
        for path, arc in members:
            archive.write(path, arc)
    PACKAGE_TEMP.replace(PACKAGE)
    with zipfile.ZipFile(PACKAGE, "r") as archive:
        broken = archive.testzip()
        names = archive.namelist()
        if broken:
            raise RuntimeError(f"ZIP CRC validation failed: {broken}")
        if "PACKAGE_CONTENTS_MANIFEST.json" not in names or len(names) != len(members) + 1:
            raise RuntimeError("ZIP member inventory validation failed")
        embedded = json.loads(archive.read("PACKAGE_CONTENTS_MANIFEST.json"))
        for arc, expected in embedded["members_sha256"].items():
            if file_hash_from_zip(archive, arc) != expected:
                raise RuntimeError(f"Member SHA-256 validation failed: {arc}")
    result = {
        "package_path": str(PACKAGE),
        "package_bytes": PACKAGE.stat().st_size,
        "package_sha256": file_hash(PACKAGE),
        "member_count": len(members),
        "zip_testzip": "PASS",
        "per_member_sha256": "PASS",
        "active_model_changed": False,
        "deployment_performed": False,
        "approved_for_deployment": False,
    }
    RECEIPT.write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(result, ensure_ascii=False, indent=2))


def file_hash_from_zip(archive: zipfile.ZipFile, name: str) -> str:
    digest = hashlib.sha256()
    with archive.open(name, "r") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


if __name__ == "__main__":
    main()
