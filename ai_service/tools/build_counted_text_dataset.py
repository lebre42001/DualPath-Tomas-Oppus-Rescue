"""Select whole synthetic descriptions for an exact four-language word budget.

This creates a review subset, not new examples, training, or model activation.
Run from ai_service; only standard-library dataset utilities are required.
"""
from __future__ import annotations

import argparse
from collections import Counter
import csv
import hashlib
import json
import os
from pathlib import Path
import random
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from src.ai_service.text_synthetic_pack import select_whole_descriptions, word_count
from src.ai_service.text_training import COLUMNS, LABELS, LANGUAGES, dataset_fingerprint, load_dataset, normalized_text

NAMES = {"en": "English", "tl": "Tagalog", "ceb": "Bisaya", "mixed": "Mixed languages"}


def write_csv(path: Path, rows: list[dict]) -> None:
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=COLUMNS, lineterminator="\n")
        writer.writeheader()
        writer.writerows(rows)


def build(source: Path, output: Path, words_per_language: int, seed: int) -> dict:
    if words_per_language <= 0 or words_per_language % len(LABELS):
        raise ValueError("Word budget must be positive and divisible by four labels")
    if output.exists() and (not output.is_dir() or any(output.iterdir())):
        raise ValueError("Use a new or empty output folder; existing data is never overwritten")
    source_bytes = source.read_bytes()
    source_rows = load_dataset(source)
    unique_rows, seen = [], set()
    for row in sorted(source_rows, key=lambda item: item["case_id"]):
        key = normalized_text(row["text"])
        if row["review_status"] == "rejected" or key in seen or len(row["text"]) > 1000:
            continue
        seen.add(key)
        unique_rows.append(row)
    selected = []
    rng = random.Random(seed)
    for language in LANGUAGES:
        for label in LABELS:
            candidates = [{language: row["text"], "row": row} for row in unique_rows
                          if row["language"] == language and row["label"] == label]
            rng.shuffle(candidates)
            chosen = select_whole_descriptions(candidates, language, words_per_language // len(LABELS))
            for item in chosen:
                row = item["row"]
                selected.append({**row, "review_status": "draft", "reviewer": "",
                    "notes": "Existing synthetic corpus subset; source case/group/text/label preserved. "
                             "Provisional label; no new human review or approval. Not a fresh evaluation set."})
    selected.sort(key=lambda item: item["case_id"])
    if len({normalized_text(row["text"]) for row in selected}) != len(selected):
        raise ValueError("Duplicate normalized descriptions in selection")
    by_language = {}
    for language in LANGUAGES:
        items = [row for row in selected if row["language"] == language]
        counts = {label: {"descriptions": sum(row["label"] == label for row in items),
                          "words": sum(word_count(row["text"]) for row in items if row["label"] == label)}
                  for label in LABELS}
        actual = sum(word_count(row["text"]) for row in items)
        if actual != words_per_language:
            raise ValueError(f"Unexpected word total for {language}: {actual}")
        by_language[language] = {"words": actual, "descriptions": len(items), "by_label": counts}
    manifest = {
        "schema_version": 1,
        "kind": "Counted review subset of existing synthetic descriptions",
        "words_per_language": words_per_language,
        "total_description_words": words_per_language * len(LANGUAGES),
        "descriptions": len(selected),
        "scenario_groups": len({row["group_id"] for row in selected}),
        "by_language": by_language,
        "by_label": dict(Counter(row["label"] for row in selected)),
        "word_count_rule": "len(text.split()): whitespace-delimited tokens in the text column only; metadata and file copies excluded",
        "selection": "Deterministic whole-description knapsack per language/label, maximizing description count; no truncation or padding",
        "seed": seed,
        "source": {"file": source.name, "path_relative_to_output": Path(os.path.relpath(source.resolve(), output.resolve())).as_posix(),
                   "sha256": hashlib.sha256(source_bytes).hexdigest(),
                   "dataset_fingerprint": dataset_fingerprint(source_rows), "descriptions": len(source_rows),
                   "words_by_language": {language: sum(word_count(row["text"]) for row in source_rows if row["language"] == language)
                                         for language in LANGUAGES}},
        "dataset_fingerprint": dataset_fingerprint(selected),
        "approved_rows": 0,
        "approved_for_deployment": False,
        "new_unique_training_examples": 0,
        "fresh_holdout": False,
        "active_model_changed": False,
        "limitations": [
            "Synthetic-origin, draft labels; neither fluent-speaker nor responder approval is implied.",
            "This is smaller than the source corpus; do not concatenate it with source data or count browsing copies as new examples.",
            "Source data was already used in model development. This subset cannot be an independent accuracy test.",
            "Case IDs and scenario groups are preserved; keep related translations/paraphrases together in any future split.",
            "Exact word balance does not imply equal row counts, natural incident frequencies, or 80-85% field accuracy.",
            "Descriptions are selected independently per language; not every translation of a selected group is included.",
        ],
    }
    output.mkdir(parents=True, exist_ok=True)
    write_csv(output / "reports.csv", selected)
    if dataset_fingerprint(load_dataset(output / "reports.csv")) != manifest["dataset_fingerprint"]:
        raise ValueError("Saved dataset failed schema/fingerprint validation")
    for language in LANGUAGES:
        write_csv(output / f"{language}.csv", [row for row in selected if row["language"] == language])
    (output / "generation_manifest.json").write_bytes((json.dumps(manifest, indent=2, ensure_ascii=False) + "\n").encode("utf-8"))
    table = ["| Language | Description words | Descriptions |", "| --- | ---: | ---: |"]
    table += [f"| {NAMES[language]} | {by_language[language]['words']:,} | {by_language[language]['descriptions']} |" for language in LANGUAGES]
    readme = "\n".join([
        "# Counted synthetic text review pack - 2026-10-16", "",
        f"**{words_per_language:,} description words per language; {manifest['total_description_words']:,} total words in {len(selected)} complete descriptions.**", "",
        *table, "",
        f"Each language has {words_per_language // len(LABELS):,} words for each proposed label: minor, serious, critical and needs_review.",
        "Counts use whitespace-separated tokens from the `text` column only. CSV headers, notes, labels and the same rows in language files do not add to the count.", "",
        "## Files", "",
        "- [reports.csv](reports.csv): master selection with the existing training schema.",
        "- [English](en.csv), [Tagalog](tl.csv), [Bisaya](ceb.csv), [mixed](mixed.csv): derived browsing/review copies, not extra training rows.",
        "- [generation_manifest.json](generation_manifest.json): exact word/label counts, source hash and limitations.", "",
        "## Source and review status", "",
        "Selected from [the existing 1,366-row synthetic corpus](../full_pool_20261011/reports.csv). Every description, case ID, group ID and proposed label is preserved; text is not cut, padded or duplicated to reach the budget.",
        "The source already exceeds 2,000 words in every language. This is a counted subset, not an expansion or newly authored data. Do not combine it with the source as extra examples.",
        "**All selected rows are draft, with blank reviewer fields.** Historical approval claims are not carried forward as verified approvals. Follow [the annotation guide](../../annotation_guide.md) for actual language and responder review.", "",
        "## Model and evaluation", "",
        "Selection itself does not train or activate a model. Any subsequent retraining needs its own evaluation/release record.",
        "The source was already used in model development. This is not an independent holdout and cannot establish 80-85% real-report accuracy. Keep scenario groups together if designing future experiments; language files are not train/test splits.", "",
        "## Reproduce", "",
        "From `C:\\laragon\\www\\TomasOppusRescue-main\\ai_service`, use a new output directory:", "",
        "```powershell",
        ".\\.venv-text\\Scripts\\python.exe tools/build_counted_text_dataset.py --source datasets/emergency_text/experiments/full_pool_20261011/reports.csv --output datasets/emergency_text/experiments/counted_2000words_reproduction --words-per-language 2000 --seed 20261016",
        "```", "",
    ])
    (output / "README.md").write_bytes(readme.encode("utf-8"))
    return manifest


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--words-per-language", type=int, default=2000)
    parser.add_argument("--seed", type=int, default=20261016)
    args = parser.parse_args()
    try:
        result = build(args.source, args.output, args.words_per_language, args.seed)
    except (ValueError, OSError) as error:
        parser.exit(1, f"error: {error}\n")
    print(json.dumps({key: result[key] for key in ("descriptions", "scenario_groups", "total_description_words", "by_language")}, indent=2))


if __name__ == "__main__":
    main()
