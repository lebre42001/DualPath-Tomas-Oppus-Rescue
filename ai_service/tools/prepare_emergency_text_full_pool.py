"""Create a non-destructive, deduplicated research pool from canonical text datasets."""
from __future__ import annotations

import csv
import hashlib
import json
import sys
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "src"))
from ai_service.text_training import LABELS, dataset_summary, normalized_text  # noqa: E402

OUTPUT = ROOT / "datasets/emergency_text/experiments/full_pool_20261011"
SOURCES = [
    ("responder850", ROOT / "datasets/emergency_text/reviewed/civilian_multilingual_850_responder_reconciled_20261007/reports.csv"),
    ("working400", ROOT / "datasets/emergency_text/working/reports.csv"),
    ("initial80", ROOT / "datasets/emergency_text/archive/initial_80/draft_reports.csv"),
    ("southern_leyte36", ROOT / "datasets/emergency_text/experiments/southern_leyte_bisaya_dialect_20261008/accident_descriptions_ai_draft.csv"),
]
COLUMNS = ("case_id", "group_id", "language", "text", "label", "review_status", "reviewer", "notes")


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def main() -> None:
    if OUTPUT.exists() and any(OUTPUT.iterdir()):
        raise SystemExit(f"Refusing to overwrite non-empty output: {OUTPUT}")
    OUTPUT.mkdir(parents=True, exist_ok=True)
    by_text: dict[str, dict[str, str]] = {}
    source_report = []
    for source_id, path in SOURCES:
        if not path.is_file():
            raise SystemExit(f"Missing canonical source: {path}")
        with path.open("r", encoding="utf-8-sig", newline="") as handle:
            rows = list(csv.DictReader(handle))
        if not rows:
            raise SystemExit(f"Empty source: {path}")
        before = len(by_text)
        for index, original in enumerate(rows, 1):
            text = (original.get("text") or "").strip()
            label = (original.get("label") or "").strip()
            if not text or label not in LABELS:
                raise SystemExit(f"Invalid text/label in {path.name}, row {index}")
            key = normalized_text(text)
            if key in by_text:
                previous = by_text[key]
                if previous["label"] != label:
                    raise SystemExit(f"Conflicting labels for duplicate text in {path.name}, row {index}")
                continue
            case_id = f"{source_id.upper()}-{(original.get('case_id') or str(index)).strip()}"
            group_id = f"{source_id}:{(original.get('group_id') or original.get('case_id') or str(index)).strip()}"
            by_text[key] = {
                "case_id": case_id,
                "group_id": group_id,
                "language": (original.get("language") or "").strip(),
                "text": text,
                "label": label,
                "review_status": (original.get("review_status") or "draft").strip(),
                "reviewer": (original.get("reviewer") or "").strip(),
                "notes": (original.get("notes") or "").strip(),
            }
        source_report.append({
            "source_id": source_id,
            "relative_path": str(path.relative_to(ROOT)).replace("\\", "/"),
            "source_sha256": sha256(path),
            "source_rows": len(rows),
            "new_unique_rows": len(by_text) - before,
            "review_status_counts": dict(Counter((r.get("review_status") or "").strip() for r in rows)),
        })
    merged = sorted(by_text.values(), key=lambda row: row["case_id"])
    with (OUTPUT / "reports.csv").open("w", encoding="utf-8-sig", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=COLUMNS, lineterminator="\n")
        writer.writeheader()
        writer.writerows(merged)
    manifest = {
        "experiment_id": "emergency-text-full-pool-20261011",
        "created_at_utc": datetime.now(timezone.utc).isoformat(),
        "purpose": "Offline provisional research only; not approved for live use.",
        "sources": source_report,
        "excluded": [
            "Duplicate exports of canonical sources (850 humanconfirmed/reconciled copies; 6000w report copy; 292-row synthetic subset; duplicate 400-row XLM-R copy).",
            "Split exports (train/validation/fresh_test/challenge/calibration), severity-folder browsing views, review worksheets, and non-row metadata.",
            "short_panic_v1 candidates: proposed labels remain under review and were not accepted as final labels.",
        ],
        "deduplication": "Exact normalized text, case-folded and whitespace-collapsed; conflicting duplicate labels stop preparation.",
        "training_scope": "All unique labeled descriptions in the selected canonical files, including draft-labeled rows, may be used only in this explicitly requested diagnostic experiment.",
        "deployment_approved": False,
        "all_rows_human_reviewed": all(row["review_status"] == "approved" for row in merged),
        "dataset": dataset_summary(merged),
        "merged_csv_sha256": sha256(OUTPUT / "reports.csv"),
    }
    (OUTPUT / "source_manifest.json").write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"output": str(OUTPUT), "manifest": manifest}, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
