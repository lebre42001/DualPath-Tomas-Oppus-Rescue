"""Synthetic data bookkeeping and word budgets; no model or network needed."""
import csv
import json
from pathlib import Path
import tempfile
import unittest

from src.ai_service.text_synthetic_pack import PACK_LANGUAGES, build_rows, generate_pack, read_group_map, read_scenarios, select_whole_descriptions, word_count
from src.ai_service.text_training import LABELS, select_rows, split_groups


def scenarios():
    items = []
    for label_index, label in enumerate(LABELS):
        for number, size in enumerate((9, 10, 11)):
            item = {"id": f"case_{label_index}_{number}", "label": label, "rationale": "Synthetic test fixture only."}
            for language in PACK_LANGUAGES:
                item[language] = " ".join([language, f"case{label_index}{number}"] + ["token"] * (size - 2))
            items.append(item)
    return items


class SyntheticPackTests(unittest.TestCase):
    def test_exact_budgets_preserve_whole_text_groups_and_draft_status(self):
        seeds = scenarios()
        rows = build_rows(seeds, words_per_language=120)
        for language in PACK_LANGUAGES:
            self.assertEqual(sum(word_count(row["text"]) for row in rows if row["language"] == language), 120)
            for label in LABELS:
                self.assertEqual(sum(word_count(row["text"]) for row in rows if row["language"] == language and row["label"] == label), 30)
        originals = {item[language] for item in seeds for language in PACK_LANGUAGES}
        self.assertEqual({row["text"] for row in rows}, originals)
        self.assertEqual(len({row["group_id"] for row in rows}), 12)
        self.assertTrue(all(row["review_status"] == "draft" and row["reviewer"] == "" for row in rows))
        with self.assertRaisesRegex(ValueError, "No approved"):
            select_rows(rows)
        partitions = split_groups(select_rows(rows, allow_draft=True))
        grouped = [{row["group_id"] for row in items} for items in partitions.values()]
        for left, right in ((0, 1), (0, 2), (1, 2)):
            self.assertFalse(grouped[left] & grouped[right])

    def test_impossible_word_budget_fails_without_cutting_or_padding(self):
        with self.assertRaisesRegex(ValueError, "do not pad or truncate"):
            select_whole_descriptions(scenarios()[:1], "en", 8)

    def test_semantic_families_share_group_and_cannot_cross_labels(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "families.json"
            payload = {"schema_version": 1, "groups": [{"id": "shared_family", "members": ["case_0_0", "case_0_1"], "reason": "Close synthetic variants."}]}
            path.write_text(json.dumps(payload), encoding="utf-8")
            seeds = scenarios()
            aliases = read_group_map(path, seeds)
            rows = build_rows(seeds, words_per_language=120, group_aliases=aliases)
            self.assertEqual(sum(row["group_id"] == "synthetic_shared_family" for row in rows), 6)
            payload["groups"][0]["members"] = ["case_0_0", "case_1_0"]
            path.write_text(json.dumps(payload), encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "cross label"):
                read_group_map(path, seeds)

    def test_provenance_roundtrip_and_existing_output_protection(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / "source.json"
            source.write_text(json.dumps(scenarios()), encoding="utf-8")
            output = root / "pack"
            manifest = generate_pack([source], output, words_per_language=120)
            self.assertEqual(manifest["total_description_words"], 360)
            self.assertEqual(manifest["approved_rows"], 0)
            self.assertFalse(manifest["approved_for_deployment"])
            with (output / "reports.csv").open(encoding="utf-8", newline="") as handle:
                rows = list(csv.DictReader(handle))
            self.assertEqual(len(rows), 36)
            snapshot = (output / "reports.csv").read_bytes()
            with self.assertRaisesRegex(ValueError, "will not be overwritten"):
                generate_pack([source], output, words_per_language=120)
            self.assertEqual(snapshot, (output / "reports.csv").read_bytes())

    def test_duplicate_and_label_leakage_are_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory) / "source.json"
            payload = scenarios()
            payload[1]["en"] = payload[0]["en"]
            source.write_text(json.dumps(payload), encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "duplicate normalized"):
                read_scenarios([source])
            payload = scenarios()
            payload[0]["en"] += " critical"
            source.write_text(json.dumps(payload), encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "target-label"):
                read_scenarios([source])


if __name__ == "__main__":
    unittest.main()
