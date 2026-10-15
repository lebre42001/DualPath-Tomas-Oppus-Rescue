"""Build a counted, multilingual synthetic review pack without inventing approval.

Whole descriptions are selected; text is never cut or padded to meet a word budget.
Translations keep their scenario group, including when only some fit the budget.
This utility never trains or activates a model.
"""
from __future__ import annotations

import argparse
from collections import Counter
import csv
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import random
import re

from .text_training import COLUMNS, LABELS, dataset_fingerprint, load_dataset, normalized_text

PACK_LANGUAGES = ("en", "tl", "ceb")
LANGUAGE_NAMES = {"en": "English", "tl": "Tagalog", "ceb": "Bisaya"}
SEED_FIELDS = {"id", "label", "en", "tl", "ceb", "rationale"}


def word_count(text: str) -> int:
    """Count whitespace-separated words in description text only."""
    return len(text.split())


def read_scenarios(paths: list[Path]) -> list[dict]:
    scenarios = []
    ids = set()
    seen = set()
    for path in paths:
        payload = json.loads(path.read_text(encoding="utf-8-sig"))
        if not isinstance(payload, list):
            raise ValueError(f"{path.name}: expected a JSON list of scenarios")
        for item in payload:
            if not isinstance(item, dict) or set(item) != SEED_FIELDS:
                raise ValueError(f"{path.name}: incorrect scenario fields")
            if any(not isinstance(value, str) or not value.strip() for value in item.values()):
                raise ValueError(f"{path.name}: every scenario field must be a nonempty string")
            item = {key: value.strip() for key, value in item.items()}
            if not re.fullmatch(r"[a-z0-9_]+", item["id"]) or item["id"] in ids:
                raise ValueError(f"Invalid or duplicate scenario ID: {item['id']}")
            ids.add(item["id"])
            if item["label"] not in LABELS:
                raise ValueError(f"Unsupported label: {item['label']}")
            for language in PACK_LANGUAGES:
                text = item[language]
                if len(text) > 1000 or word_count(text) < 8:
                    raise ValueError(f"{item['id']}/{language}: description length outside pack limits")
                if re.search(r"\b(minor|serious|critical|needs_review)\b", text, re.I):
                    raise ValueError(f"{item['id']}/{language}: explicit target-label word in description")
                key = normalized_text(text)
                if key in seen:
                    raise ValueError(f"{item['id']}/{language}: duplicate normalized description")
                seen.add(key)
            scenarios.append(item)
    return scenarios


def select_whole_descriptions(candidates: list[dict], language: str, target: int) -> list[dict]:
    # 0/1 knapsack: maximize scenario diversity among exact-word solutions.
    # Descending traversal ensures each description can appear at most once.
    states: dict[int, tuple[int, ...]] = {0: ()}
    for index, item in enumerate(candidates):
        size = word_count(item[language])
        for total in sorted(list(states), reverse=True):
            following = total + size
            if following > target:
                continue
            selection = states[total] + (index,)
            if following not in states or len(selection) > len(states[following]):
                states[following] = selection
    if target not in states:
        available = sum(word_count(item[language]) for item in candidates)
        raise ValueError(f"Cannot select {target} whole-description words for {language}; {available} available. Add natural scenarios; do not pad or truncate.")
    return [candidates[index] for index in states[target]]


def read_group_map(path: Path | None, scenarios: list[dict]) -> dict[str, str]:
    if path is None:
        return {}
    payload = json.loads(path.read_text(encoding="utf-8-sig"))
    if not isinstance(payload, dict) or payload.get("schema_version") != 1 or not isinstance(payload.get("groups"), list):
        raise ValueError("Invalid scenario group map")
    by_id = {item["id"]: item for item in scenarios}
    aliases = {}
    family_ids = set()
    for family in payload["groups"]:
        if not isinstance(family, dict) or set(family) != {"id", "members", "reason"}:
            raise ValueError("Each family needs id, members and reason")
        family_id = family["id"]
        members = family["members"]
        if (not isinstance(family_id, str) or not re.fullmatch(r"[a-z0-9_]+", family_id)
                or family_id in family_ids or family_id in by_id or not isinstance(family["reason"], str) or not family["reason"].strip()):
            raise ValueError("Invalid or duplicated scenario family")
        family_ids.add(family_id)
        if not isinstance(members, list) or len(members) < 2 or any(not isinstance(member, str) for member in members):
            raise ValueError("A family needs at least two scenario IDs")
        if any(member not in by_id or member in aliases for member in members) or len(set(members)) != len(members):
            raise ValueError("Unknown or multiply assigned scenario family member")
        if len({by_id[member]["label"] for member in members}) != 1:
            raise ValueError("A scenario family cannot cross label boundaries")
        aliases.update({member: family_id for member in members})
    return aliases


def build_rows(scenarios: list[dict], words_per_language: int = 3000, seed: int = 20260920, group_aliases: dict[str, str] | None = None) -> list[dict]:
    if words_per_language < len(LABELS) or words_per_language % len(LABELS):
        raise ValueError("Words per language must be positive and divisible by the four labels")
    target = words_per_language // len(LABELS)
    rows = []
    rng = random.Random(seed)
    for label in LABELS:
        candidates = sorted((item for item in scenarios if item["label"] == label), key=lambda item: item["id"])
        rng.shuffle(candidates)
        for language in PACK_LANGUAGES:
            for item in select_whole_descriptions(candidates, language, target):
                rows.append({
                    "case_id": f"SYN_{item['id'].upper()}_{language.upper()}",
                    "group_id": f"synthetic_{(group_aliases or {}).get(item['id'], item['id'])}",
                    "language": language,
                    "text": item[language],
                    "label": label,
                    "review_status": "draft",
                    "reviewer": "",
                    "notes": "AI-generated fictional road-report example; provisional label, no human approval. " + item["rationale"],
                })
    return sorted(rows, key=lambda row: row["case_id"])


def generate_pack(paths: list[Path], output: Path, words_per_language: int = 3000, seed: int = 20260920, group_map: Path | None = None) -> dict:
    if output.exists() and any(output.iterdir()):
        raise ValueError("Use a new or empty output directory; existing review data will not be overwritten")
    scenarios = read_scenarios(paths)
    group_aliases = read_group_map(group_map, scenarios)
    rows = build_rows(scenarios, words_per_language, seed, group_aliases)
    output.mkdir(parents=True, exist_ok=True)
    csv_path = output / "reports.csv"
    with csv_path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=COLUMNS, lineterminator="\n")
        writer.writeheader()
        writer.writerows(rows)
    checked = load_dataset(csv_path)
    counts = {language: {
        "words": sum(word_count(row["text"]) for row in rows if row["language"] == language),
        "descriptions": sum(row["language"] == language for row in rows),
        "by_label": {label: {
            "words": sum(word_count(row["text"]) for row in rows if row["language"] == language and row["label"] == label),
            "descriptions": sum(row["language"] == language and row["label"] == label for row in rows),
        } for label in LABELS},
    } for language in PACK_LANGUAGES}
    manifest = {
        "schema_version": 1,
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "source_type": "AI-generated synthetic fictional descriptions",
        "word_count_rule": "Whitespace-separated tokens in the text column only; metadata, headings and duplicate folder copies excluded",
        "requested_words_per_language": words_per_language,
        "total_description_words": sum(value["words"] for value in counts.values()),
        "rows": len(rows),
        "groups": len({row["group_id"] for row in rows}),
        "available_seed_groups": len(scenarios),
        "by_language": counts,
        "by_label": dict(Counter(row["label"] for row in rows)),
        "selection": "Deterministic whole-description knapsack per label/language; no truncation, padding or target-label text",
        "seed": seed,
        "sources": [{"name": path.name, "sha256": hashlib.sha256(path.read_bytes()).hexdigest()} for path in paths],
        "semantic_group_map": {"name": group_map.name, "sha256": hashlib.sha256(group_map.read_bytes()).hexdigest(), "merged_seed_scenarios": len(group_aliases)} if group_map else None,
        "dataset_fingerprint": dataset_fingerprint(checked),
        "approved_rows": 0,
        "approved_for_deployment": False,
        "limitations": [
            "No responder or native-speaker approval is implied by generated labels.",
            "Balanced synthetic words do not represent real incident frequency or prove clinical accuracy.",
            "Translations/paraphrases must stay grouped across train/validation/test; assess semantic overlap separately.",
            "Scope is road/vehicle accidents. Needs review is an abstention label, not low urgency.",
            "Some seed translations may be excluded by the exact word budget; included translations retain the same group ID.",
        ],
    }
    (output / "generation_manifest.json").write_text(json.dumps(manifest, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    table = ["| Language | Description words | Descriptions |", "| --- | ---: | ---: |"]
    table += [f"| {LANGUAGE_NAMES[language]} | {counts[language]['words']} | {counts[language]['descriptions']} |" for language in PACK_LANGUAGES]
    readme = "\n".join([
        "# Synthetic road-accident text review pack", "",
        f"**{manifest['total_description_words']:,} description words** across {len(rows)} complete reports and {manifest['groups']} scenario groups.",
        "**All rows are draft. Zero approved examples; not a validated emergency triage model.**", "",
        *table, "",
        f"Each language has {words_per_language // len(LABELS)} words in each of minor, serious, critical and needs_review.",
        "Words are counted with Python `len(text.split())` in the master text column; CSV metadata, README headings and browsing copies do not add to the count.", "",
        "- Editable master: [reports.csv](reports.csv). Preserve review notes and actual reviewer identity when reviewing.",
        "- Browsing: [severity and language folders](by_severity/README.md). These are derived copies; edit the master, then refresh them.",
        "- Provenance and exact counts: [generation_manifest.json](generation_manifest.json).",
        "- Label policy and human review: [annotation guide](../annotation_guide.md).",
        "- Keep translations of a scenario in the same group; folder or language separation is not a train/test split.",
        "- Original 80-row draft/review master files are preserved separately.", "",
        "Training on this pack is an explicitly synthetic experiment. It must not enable live severity predictions or fabricate expert approval.", "",
    ])
    (output / "README.md").write_text(readme, encoding="utf-8")
    return manifest


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--sources", nargs="+", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    parser.add_argument("--words-per-language", type=int, default=3000)
    parser.add_argument("--seed", type=int, default=20260920)
    parser.add_argument("--group-map", type=Path, help="Conservative semantic family map, applied before splitting")
    args = parser.parse_args(argv)
    try:
        manifest = generate_pack(args.sources, args.output, args.words_per_language, args.seed, args.group_map)
    except (ValueError, OSError, json.JSONDecodeError) as error:
        parser.exit(1, f"error: {error}\n")
    print(json.dumps({key: manifest[key] for key in ("rows", "groups", "total_description_words", "by_language", "dataset_fingerprint")}, indent=2))
    return 0
