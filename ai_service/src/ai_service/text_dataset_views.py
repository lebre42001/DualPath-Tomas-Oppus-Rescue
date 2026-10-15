"""Lossless, generated severity/language views of one master CSV (stdlib only)."""
from __future__ import annotations

import argparse
import csv
import hashlib
import io
import json
import os
from pathlib import Path
import tempfile

from .text_training import COLUMNS, LABELS, LANGUAGES, DEFAULT_DATA, dataset_summary, load_dataset

DEFAULT_SOURCE = DEFAULT_DATA
MANIFEST = "manifest.json"
KIND = "emergency_text_severity_views"
LANGUAGE_FOLDERS = {"en": "english", "tl": "tagalog", "ceb": "bisaya", "mixed": "mixed"}
LEGACY_FILES = ("README.md", *(f"{label}/examples.csv" for label in LABELS))
FILES = (*LEGACY_FILES, *(f"{label}/{LANGUAGE_FOLDERS[language]}/examples.csv"
                          for label in LABELS for language in LANGUAGES))


def _sha(content: bytes) -> str:
    return hashlib.sha256(content).hexdigest()


def _json_bytes(payload: dict) -> bytes:
    return (json.dumps(payload, ensure_ascii=False, indent=2) + "\n").encode("utf-8")


def _plain_path(path: Path) -> None:
    # Refuse junctions/symlinks in generated destinations or their ancestors.
    for item in (path, *path.parents):
        if item.is_symlink() or item.is_junction():
            raise ValueError(f"Linked paths are not supported for dataset views: {item}")


def _existing_manifest(output: Path) -> dict | None:
    manifest_path = output / MANIFEST
    if not manifest_path.exists():
        if output.exists() and any(output.iterdir()):
            raise ValueError("Output is not an empty or managed export folder; use a new --output directory.")
        return None
    _plain_path(manifest_path)
    try:
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    except (OSError, ValueError) as exc:
        raise ValueError("Export manifest is unreadable; preserve this folder and use a new --output directory.") from exc
    if not isinstance(manifest, dict):
        raise ValueError("Output manifest is not a supported severity export.")
    version = manifest.get("schema_version")
    expected_files = LEGACY_FILES if version == 1 else FILES if version == 2 else None
    if (expected_files is None or manifest.get("kind") != KIND
            or not isinstance(manifest.get("files"), dict)
            or set(manifest["files"]) != set(expected_files)
            or (manifest.get("schema_version") == 2 and manifest.get("language_folders") != LANGUAGE_FOLDERS)):
        raise ValueError("Output manifest is not a supported severity export.")
    for relative in expected_files:
        target = output / relative
        _plain_path(target)
        if not target.is_file() or _sha(target.read_bytes()) != manifest["files"][relative]:
            raise ValueError(
                f"Generated file is missing or modified: {relative}. "
                "Preserve any edits, apply intended changes to the master CSV, "
                "then export to a new --output directory. Nothing was overwritten."
            )
    # A v1 export owns only the aggregate files. Never adopt or replace a
    # pre-existing language file while upgrading the managed format.
    for relative in set(FILES) - set(expected_files):
        target = output / relative
        _plain_path(target)
        if target.exists():
            raise ValueError(f"Unmanaged file occupies a new language view: {relative}. Use a new --output directory.")
    return manifest


def _csv_bytes(rows: list[dict]) -> bytes:
    buffer = io.StringIO(newline="")
    writer = csv.DictWriter(buffer, fieldnames=COLUMNS)
    writer.writeheader()
    writer.writerows(rows)
    # BOM helps Windows Excel open Tagalog/Cebuano Unicode correctly.
    return buffer.getvalue().encode("utf-8-sig")


def _build_files(source: Path, source_bytes: bytes, rows: list[dict], output: Path) -> tuple[dict, dict]:
    # The trainer normalizes surrounding whitespace for validation. Export the
    # original fields so Unicode, whitespace, commas, and multiline text survive.
    raw = list(csv.DictReader(io.StringIO(source_bytes.decode("utf-8-sig"), newline="")))
    files, summaries = {}, {}
    for label in LABELS:
        selected = [row for row in raw if row["label"].strip() == label]
        selected.sort(key=lambda row: (LANGUAGES.index(row["language"].strip()), row["case_id"].strip()))
        files[f"{label}/examples.csv"] = _csv_bytes(selected)
        for language in LANGUAGES:
            subset = [row for row in selected if row["language"].strip() == language]
            files[f"{label}/{LANGUAGE_FOLDERS[language]}/examples.csv"] = _csv_bytes(subset)
        summaries[label] = dataset_summary([row for row in rows if row["label"] == label])
    summary = dataset_summary(rows)
    master_link = Path(os.path.relpath(source, output)).as_posix()
    lines = [
        "# Emergency text by severity — generated review copies", "",
        f"**Edit the [master CSV]({master_link}).** These folder copies are for browsing only.",
        "Ayaw diri pag-edit. Sa master CSV ibutang ang corrections, dayon i-refresh ang folders.", "",
        f"Total: **{summary['rows']} examples**, **{summary['groups']} groups**, "
        f"**{summary['eligible_approved_rows']} approved rows**. A filename or folder is not review approval.", "",
        "| Label / folder | Examples | English | Tagalog | Bisaya | Mixed | Draft | Approved | Rejected |",
        "| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |",
    ]
    for label in LABELS:
        item = summaries[label]
        counts = [item["rows"], *(f"[{item['by_language'].get(lang, 0)}]({label}/{LANGUAGE_FOLDERS[lang]}/examples.csv)"
                                 for lang in LANGUAGES),
                  *(item["by_review_status"].get(status, 0) for status in ("draft", "approved", "rejected"))]
        lines.append(f"| [{label}]({label}/examples.csv) | " + " | ".join(map(str, counts)) + " |")
    lines.extend([
        "", "Each severity folder keeps its combined `examples.csv` and separate language browsing folders:",
        "`english/` (`en`), `tagalog/` (`tl`), `bisaya/` (`ceb`), and `mixed/` (`mixed`).",
        "Every `examples.csv` retains all eight original master columns, including language, group, review status, reviewer, and notes.",
        "Combined files are sorted by language, then case ID; language files are sorted by case ID.",
        "These are overlapping views of the same examples, not additional training rows. Do not concatenate combined and language copies.",
        "Empty language/severity combinations have a header-only CSV. Folder names do not translate or relabel text.",
        "Draft, approved, and rejected rows are included for review; training still uses the master and its approval rules.",
        "`needs_review` means the description cannot support a severity decision. It is independent of `review_status`.",
        "Keep translations/paraphrases under the same `group_id`; the trainer splits whole groups.", "",
        "From the repository's `ai_service` directory, refresh the default master views:", "",
        "```powershell", "& 'C:\\laragon\\bin\\python\\python-3.13\\python.exe' organize_text.py",
        "& 'C:\\laragon\\bin\\python\\python-3.13\\python.exe' organize_text.py --check", "```", "",
        "For a custom source/output, repeat its `--data` and `--output` arguments when refreshing or checking.",
        "The command detects changed/missing generated files and refuses to overwrite them.",
        "After an interrupted refresh, preserve the folder and export to a new `--output` directory.",
        "Training reads one master CSV containing all labels; folder names never become model input.",
        "No model training, label approval, or live activation is performed by this exporter.", "",
    ])
    files["README.md"] = "\n".join(lines).encode("utf-8")
    manifest = {
        "schema_version": 2, "kind": KIND,
        "source": {"name": source.name, "sha256": _sha(source_bytes),
                   "dataset_fingerprint": summary["dataset_fingerprint"]},
        "summary": summary, "by_label": summaries, "language_folders": LANGUAGE_FOLDERS,
        "files": {relative: _sha(files[relative]) for relative in FILES},
    }
    return files, manifest


def export_views(source: Path, output: Path, *, check: bool = False) -> dict:
    source = Path(source).absolute()
    output = Path(output).absolute()
    _plain_path(output)
    if source.resolve().is_relative_to(output.resolve()):
        raise ValueError("The source master CSV must be outside the output directory.")
    if output.exists() and not output.is_dir():
        raise ValueError("Output must be a directory.")
    source_bytes = source.read_bytes()
    rows = load_dataset(source)
    if source.read_bytes() != source_bytes:
        raise ValueError("The master CSV changed during validation; retry after saving it.")
    files, manifest = _build_files(source, source_bytes, rows, output)
    existing = _existing_manifest(output)
    for relative in (*FILES, MANIFEST):
        _plain_path(output / relative)
    current = existing == manifest
    if check and not current:
        raise ValueError("Severity views are missing or stale. Run organize_text.py with the same source/output to refresh.")
    if not check and not current:
        output.parent.mkdir(parents=True, exist_ok=True)
        # Stage complete files on the same volume; replace individual files
        # atomically and the manifest last. A partial refresh fails --check.
        with tempfile.TemporaryDirectory(prefix=".severity-export-", dir=output.parent) as stage_dir:
            staged = Path(stage_dir)
            for relative, content in {**files, MANIFEST: _json_bytes(manifest)}.items():
                path = staged / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(content)
            if source.read_bytes() != source_bytes or _existing_manifest(output) != existing:
                raise ValueError("Source or exported files changed during staging; retry after saving them.")
            for relative in (*FILES, MANIFEST):
                target = output / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                replacement = staged / relative
                if not target.exists() or target.read_bytes() != replacement.read_bytes():
                    replacement.replace(target)
        if _existing_manifest(output) != manifest or source.read_bytes() != source_bytes:
            raise ValueError("Source or exported files changed during export; run --check before using these views.")
    return {
        "status": "checked" if check else "up_to_date" if current else "refreshed" if existing else "created",
        "source": str(source), "output": str(output),
        "rows": len(rows), "by_label": manifest["summary"]["by_label"],
        "eligible_approved_rows": manifest["summary"]["eligible_approved_rows"],
        "source_sha256": manifest["source"]["sha256"],
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Export severity/language browsing folders from one master CSV; no training or model changes.")
    parser.add_argument("--data", type=Path, default=DEFAULT_SOURCE, help="Master CSV; defaults to datasets/emergency_text/working/reports.csv in this repository")
    parser.add_argument("--output", type=Path, help="Generated folder; defaults to by_severity beside the selected master")
    parser.add_argument("--check", action="store_true", help="Verify source freshness and generated file integrity without writes")
    args = parser.parse_args(argv)
    try:
        result = export_views(args.data, args.output or args.data.parent / "by_severity", check=args.check)
        print(json.dumps(result, ensure_ascii=False, indent=2))
        return 0
    except (ValueError, OSError) as exc:
        parser.exit(2, f"error: {exc}\n")


if __name__ == "__main__":
    raise SystemExit(main())
