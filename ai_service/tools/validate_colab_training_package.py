"""Verify the sealed Colab input bundle and notebook before upload."""
from __future__ import annotations

import argparse
import ast
import hashlib
import json
from pathlib import Path
import zipfile


ROOT = Path(__file__).resolve().parents[2]
AI_ROOT = ROOT / "ai_service"
DEFAULT_PACKAGE = AI_ROOT / "artifacts" / "colab_packages" / "emergency_text_xlmr_provisional_20261004"
DEFAULT_NOTEBOOK = AI_ROOT / "notebooks" / "EMERGENCY_TEXT_XLMR_COLAB.ipynb"


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def validate(package: Path, notebook_path: Path) -> dict:
    package = package.resolve()
    notebook_path = notebook_path.resolve()
    receipt = json.loads((package / "PACKAGE_RECEIPT.json").read_text(encoding="utf-8"))
    bundle = package / "emergency_text_xlmr_provisional_20261004.zip"
    if sha256(bundle) != receipt["bundle_sha256"]:
        raise ValueError("Bundle hash differs from PACKAGE_RECEIPT.json")
    if sha256(notebook_path) != receipt["notebook_sha256"]:
        raise ValueError("Notebook hash differs from PACKAGE_RECEIPT.json")
    with zipfile.ZipFile(bundle) as archive:
        bad = archive.testzip()
        if bad is not None:
            raise ValueError(f"Corrupt ZIP member: {bad}")
        manifest_bytes = archive.read("package_manifest.json")
        manifest = json.loads(manifest_bytes)
        for relative, expected in manifest["files_sha256"].items():
            actual = hashlib.sha256(archive.read(relative)).hexdigest()
            if actual != expected:
                raise ValueError(f"Bundle member hash mismatch: {relative}")
        split = json.loads(archive.read("data/frozen_split_manifest.json"))
    groups = [set(split[name]["group_hashes"]) for name in ("train", "validation", "test")]
    texts = [set(split[name]["text_hashes"]) for name in ("train", "validation", "test")]
    for left in range(3):
        for right in range(left + 1, 3):
            if groups[left] & groups[right] or texts[left] & texts[right]:
                raise ValueError("Frozen split leaks groups or normalized text")
    if sha256(AI_ROOT / "active_config.txt") != manifest["active_config_sha256_at_packaging"]:
        raise ValueError("active_config.txt changed after package creation")

    notebook = json.loads(notebook_path.read_text(encoding="utf-8"))
    if notebook.get("nbformat") != 4:
        raise ValueError("Notebook must use nbformat 4")
    code = ""
    for cell in notebook.get("cells", []):
        if cell.get("cell_type") == "code":
            if cell.get("outputs") or cell.get("execution_count") is not None:
                raise ValueError("Notebook must be distributed without execution output")
            source = "".join(cell.get("source", []))
            ast.parse(source)
            code += source + "\n"
    if receipt["bundle_sha256"] not in code:
        raise ValueError("Notebook does not pin the bundle SHA-256")
    for required in ("--unfreeze-backbone", "--split-manifest", '"--split", "test"'):
        if required not in code:
            raise ValueError(f"Notebook is missing required control: {required}")
    if "active_config.txt" in code:
        raise ValueError("Notebook must not read or change the live active config")

    return {
        "status": "passed",
        "package_id": receipt["package_id"],
        "bundle_sha256": receipt["bundle_sha256"],
        "notebook_sha256": receipt["notebook_sha256"],
        "dataset_rows": manifest["dataset"]["rows"],
        "dataset_groups": manifest["dataset"]["groups"],
        "split_rows": manifest["split_rows"],
        "split_groups": manifest["split_groups"],
        "group_leakage": 0,
        "normalized_text_leakage": 0,
        "human_review_complete": receipt["human_review_complete"],
        "training_executed": False,
        "active_model_changed": False,
        "approved_for_deployment": False,
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Validate the sealed Colab package before upload.")
    parser.add_argument("--package", type=Path, default=DEFAULT_PACKAGE)
    parser.add_argument("--notebook", type=Path, default=DEFAULT_NOTEBOOK)
    args = parser.parse_args(argv)
    try:
        print(json.dumps(validate(args.package, args.notebook), ensure_ascii=False, indent=2, allow_nan=False))
        return 0
    except (ValueError, OSError, KeyError, json.JSONDecodeError, zipfile.BadZipFile, SyntaxError) as error:
        raise SystemExit(f"error: {error}")


if __name__ == "__main__":
    raise SystemExit(main())
