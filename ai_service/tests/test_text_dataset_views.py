"""Lossless severity views and safe refreshes; no model downloads required."""
from __future__ import annotations

import csv
import hashlib
import json
from pathlib import Path
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from src.ai_service.text_dataset_views import LANGUAGE_FOLDERS, LEGACY_FILES, export_views
from src.ai_service.text_training import COLUMNS, LABELS, LANGUAGES, load_dataset, select_rows


def example_rows():
    rows = []
    for label in LABELS:
        for status in ("draft", "approved", "rejected"):
            case_id = f"{label}-{status}"
            rows.append(dict(zip(COLUMNS, (
                case_id, f"group-{case_id}", "ceb",
                f'  Bangga sa dalan, {case_id}: "tabang" — usa ka pasahero.\nIkaduhang linya.  ',
                label, status, "  reviewer-01  " if status == "approved" else "",
                f'  Nota sa {case_id}, "orihinal"\nAyaw usba.  ',
            ))))
    # The validator trims labels, but the generated view must retain cell values.
    rows[0]["label"] = " minor "
    return rows


class TextDatasetViewTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        self.source = self.root / "reviewed_reports.csv"
        self.output = self.root / "by_severity"
        self.rows = example_rows()
        self.write_source(self.rows)

    def write_source(self, rows):
        with self.source.open("w", encoding="utf-8-sig", newline="") as handle:
            writer = csv.DictWriter(handle, fieldnames=COLUMNS)
            writer.writeheader()
            writer.writerows(rows)

    def read_view(self, label, language=None):
        directory = self.output / label
        if language is not None:
            directory /= LANGUAGE_FOLDERS[language]
        with (directory / "examples.csv").open(encoding="utf-8-sig", newline="") as handle:
            reader = csv.DictReader(handle)
            self.assertEqual(reader.fieldnames, list(COLUMNS))
            return list(reader)

    def snapshot(self, directory):
        return {
            path.relative_to(directory).as_posix(): (path.read_bytes(), path.stat().st_mtime_ns)
            for path in directory.rglob("*") if path.is_file()
        }

    def assert_manifest_hashes(self):
        manifest = json.loads((self.output / "manifest.json").read_text(encoding="utf-8"))
        self.assertEqual(manifest["schema_version"], 2)
        self.assertEqual(manifest["kind"], "emergency_text_severity_views")
        self.assertEqual(manifest["language_folders"], {"en": "english", "tl": "tagalog", "ceb": "bisaya", "mixed": "mixed"})
        self.assertEqual(manifest["source"]["sha256"], hashlib.sha256(self.source.read_bytes()).hexdigest())
        self.assertTrue({f"{label}/examples.csv" for label in LABELS} <= set(manifest["files"]))
        expected = {
            path.relative_to(self.output).as_posix(): hashlib.sha256(path.read_bytes()).hexdigest()
            for path in self.output.rglob("*") if path.is_file() and path.name != "manifest.json"
        }
        self.assertEqual(manifest["files"], expected)

    def write_legacy_export(self):
        """Simulate the original format using only its tracked aggregate files."""
        export_views(self.source, self.output)
        manifest_path = self.output / "manifest.json"
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        for relative in set(manifest["files"]) - set(LEGACY_FILES):
            (self.output / relative).unlink()
        manifest["schema_version"] = 1
        manifest.pop("language_folders")
        manifest["files"] = {relative: manifest["files"][relative] for relative in LEGACY_FILES}
        (self.output / "README.md").write_text("Original aggregate severity views; edit the master CSV.\n", encoding="utf-8")
        manifest["files"]["README.md"] = hashlib.sha256((self.output / "README.md").read_bytes()).hexdigest()
        manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    def test_preserves_every_field_and_review_status_without_changing_training_gates(self):
        original = self.source.read_bytes()
        export_views(self.source, self.output)
        exported = []
        training_rows = []
        for label in LABELS:
            actual = self.read_view(label)
            self.assertCountEqual(actual, [row for row in self.rows if row["label"].strip() == label])
            self.assertEqual({row["review_status"] for row in actual}, {"draft", "approved", "rejected"})
            exported.extend(actual)
            training_rows.extend(load_dataset(self.output / label / "examples.csv"))
        self.assertEqual(len(exported), len(self.rows))
        self.assertEqual(len({row["case_id"] for row in exported}), len(self.rows))
        self.assertEqual({row["case_id"] for row in select_rows(training_rows)},
                         {row["case_id"] for row in self.rows if row["review_status"] == "approved"})
        self.assertEqual({row["case_id"] for row in select_rows(training_rows, allow_draft=True)},
                         {row["case_id"] for row in self.rows if row["review_status"] != "rejected"})
        self.assertEqual(self.source.read_bytes(), original)
        self.assert_manifest_hashes()

    def test_labels_without_examples_have_header_only_csv(self):
        self.write_source([self.rows[0]])
        export_views(self.source, self.output)
        self.assertEqual(self.read_view("minor"), [self.rows[0]])
        for label in LABELS[1:]:
            self.assertEqual(self.read_view(label), [])
        for label in LABELS:
            for language in LANGUAGES:
                expected = [self.rows[0]] if label == "minor" and language == "ceb" else []
                self.assertEqual(self.read_view(label, language), expected)
        self.assert_manifest_hashes()
        export_views(self.source, self.output, check=True)

    def test_unchanged_export_and_successful_check_do_not_rewrite_files(self):
        export_views(self.source, self.output)
        before = self.snapshot(self.root)
        export_views(self.source, self.output)
        self.assertEqual(self.snapshot(self.root), before)
        export_views(self.source, self.output, check=True)
        self.assertEqual(self.snapshot(self.root), before)

    def test_valid_master_refresh_moves_row_without_duplication(self):
        export_views(self.source, self.output)
        moved = dict(self.rows[0], label="critical", language="en", notes="Label and language updated in the master.")
        self.rows[0] = moved
        self.write_source(self.rows)
        changed_master = self.source.read_bytes()
        export_views(self.source, self.output)
        self.assertNotIn(moved["case_id"], {row["case_id"] for row in self.read_view("minor")})
        self.assertIn(moved, self.read_view("critical"))
        self.assertNotIn(moved["case_id"], {row["case_id"] for row in self.read_view("minor", "ceb")})
        self.assertIn(moved, self.read_view("critical", "en"))
        exported = [row for label in LABELS for row in self.read_view(label)]
        self.assertEqual(len(exported), len(self.rows))
        self.assertEqual({row["case_id"] for row in exported}, {row["case_id"] for row in self.rows})
        self.assertEqual(self.source.read_bytes(), changed_master)
        self.assert_manifest_hashes()
        export_views(self.source, self.output, check=True)

    def test_each_language_view_is_lossless_and_manifest_totals_count_the_master_only(self):
        rows = [dict(row, case_id=f"{row['case_id']}-{language}", language=f" {language} ",
                     text=f"{row['text']} ({language})") for row in self.rows for language in LANGUAGES]
        self.write_source(rows)
        original = self.source.read_bytes()
        export_views(self.source, self.output)
        partition = []

        for label in LABELS:
            for language in LANGUAGES:
                expected = [row for row in rows if row["label"].strip() == label and row["language"].strip() == language]
                actual = self.read_view(label, language)
                self.assertEqual(actual, sorted(expected, key=lambda row: row["case_id"].strip()))
                self.assertEqual({row["review_status"] for row in actual}, {"draft", "approved", "rejected"})
                partition.extend(actual)

        self.assertCountEqual(partition, rows)
        self.assertEqual(len({row["case_id"] for row in partition}), len(rows))
        self.assertEqual(self.source.read_bytes(), original)
        self.assertEqual({row["group_id"] for row in partition}, {row["group_id"] for row in rows})
        manifest = json.loads((self.output / "manifest.json").read_text(encoding="utf-8"))
        self.assertEqual(manifest["summary"]["rows"], len(rows))
        self.assertEqual(manifest["summary"]["eligible_approved_rows"], sum(row["review_status"] == "approved" for row in rows))
        index = (self.output / "README.md").read_text(encoding="utf-8")
        for label in LABELS:
            for folder in LANGUAGE_FOLDERS.values():
                self.assertIn(f"({label}/{folder}/examples.csv)", index)
        self.assertIn("Do not concatenate combined and language copies", index)
        self.assert_manifest_hashes()

    def test_legacy_aggregate_export_upgrades_without_rewriting_master_or_aggregate_csvs(self):
        self.write_legacy_export()
        before = self.snapshot(self.root)
        with self.assertRaisesRegex(ValueError, "stale"):
            export_views(self.source, self.output, check=True)
        self.assertEqual(self.snapshot(self.root), before)
        result = export_views(self.source, self.output)
        self.assertEqual(result["status"], "refreshed")
        after = self.snapshot(self.root)
        for relative in ("reviewed_reports.csv", *(f"by_severity/{label}/examples.csv" for label in LABELS)):
            self.assertEqual(after[relative], before[relative])
        for label in LABELS:
            self.assertEqual(self.read_view(label, "ceb"), self.read_view(label))
        self.assert_manifest_hashes()
        export_views(self.source, self.output, check=True)

    def test_legacy_upgrade_never_overwrites_an_unmanaged_language_file(self):
        self.write_legacy_export()
        existing = self.output / "minor" / "english" / "examples.csv"
        existing.write_text("Personal examples; retain this file.\n", encoding="utf-8")
        before = self.snapshot(self.root)
        for check in (False, True):
            with self.assertRaisesRegex(ValueError, "Unmanaged file"):
                export_views(self.source, self.output, check=check)
            self.assertEqual(self.snapshot(self.root), before)

    def test_changed_or_missing_language_views_are_never_silently_recreated(self):
        export_views(self.source, self.output)
        target = self.output / "minor" / "bisaya" / "examples.csv"
        original = target.read_bytes()
        for content in (original + b"Manual local addition\r\n", None):
            if content is None:
                target.unlink()
            else:
                target.write_bytes(content)
            before = self.snapshot(self.root)
            for check in (False, True):
                with self.assertRaisesRegex(ValueError, "missing or modified"):
                    export_views(self.source, self.output, check=check)
                self.assertEqual(self.snapshot(self.root), before)

    def test_invalid_master_is_refused_before_any_output_writes(self):
        invalid_rows = (
            [self.rows[0], dict(self.rows[1], case_id=self.rows[0]["case_id"])],
            [dict(self.rows[1], reviewer="")],
            [dict(self.rows[0], label="urgent")],
            [self.rows[0], dict(self.rows[3], group_id=self.rows[0]["group_id"])],
            [],
        )
        for rows in invalid_rows:
            with self.subTest(rows=rows):
                self.write_source(rows)
                with self.assertRaises(ValueError):
                    export_views(self.source, self.output)
                self.assertFalse(self.output.exists())
        self.write_source(self.rows)
        export_views(self.source, self.output)
        before = self.snapshot(self.output)
        self.write_source([dict(self.rows[1], reviewer="")])
        with self.assertRaises(ValueError):
            export_views(self.source, self.output)
        self.assertEqual(self.snapshot(self.output), before)

    def test_check_refuses_stale_source_missing_views_and_absent_output_without_writing(self):
        export_views(self.source, self.output)
        self.write_source([dict(row, notes="Updated master note.") for row in self.rows])
        before = self.snapshot(self.root)
        with self.assertRaises((ValueError, OSError)):
            export_views(self.source, self.output, check=True)
        self.assertEqual(self.snapshot(self.root), before)
        self.write_source(self.rows)
        (self.output / "critical" / "examples.csv").unlink()
        before = self.snapshot(self.root)
        for check in (True, False):
            with self.subTest(check=check):
                with self.assertRaises((ValueError, OSError)):
                    export_views(self.source, self.output, check=check)
                self.assertEqual(self.snapshot(self.root), before)
        absent = self.root / "absent"
        with self.assertRaises((ValueError, OSError)):
            export_views(self.source, absent, check=True)
        self.assertFalse(absent.exists())

    def test_manual_export_edits_are_never_overwritten_even_when_master_changes(self):
        export_views(self.source, self.output)
        edited = self.output / "minor" / "examples.csv"
        edited.write_bytes(edited.read_bytes() + b"Manual local addition\r\n")
        for change_master in (False, True):
            with self.subTest(change_master=change_master):
                if change_master:
                    self.write_source([dict(row, notes="New master notes") for row in self.rows])
                before = self.snapshot(self.root)
                for check in (False, True):
                    with self.assertRaises((ValueError, OSError)):
                        export_views(self.source, self.output, check=check)
                    self.assertEqual(self.snapshot(self.root), before)

    def test_existing_unmanaged_files_are_not_adopted_or_overwritten(self):
        for relative in ("personal-notes.txt", "minor/examples.csv"):
            with self.subTest(relative=relative):
                output = self.root / relative.replace("/", "-").replace(".", "-")
                existing = output / relative
                existing.parent.mkdir(parents=True)
                existing.write_text("Keep this original file.\n", encoding="utf-8")
                before = self.snapshot(self.root)
                with self.assertRaises((ValueError, OSError)):
                    export_views(self.source, output)
                self.assertEqual(self.snapshot(self.root), before)

    def test_output_containing_source_and_output_equal_to_source_are_refused(self):
        before = self.snapshot(self.root)
        for output in (self.root, self.source):
            with self.subTest(output=output):
                with self.assertRaises((ValueError, OSError)):
                    export_views(self.source, output)
                self.assertEqual(self.snapshot(self.root), before)


if __name__ == "__main__":
    unittest.main()
