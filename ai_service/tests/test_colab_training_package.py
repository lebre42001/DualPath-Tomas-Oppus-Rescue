"""Static and integrity checks for the sealed Colab XLM-R workflow."""
from __future__ import annotations

import argparse
import ast
import hashlib
import json
from pathlib import Path
import sys
import tempfile
import unittest
import zipfile


AI_ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(AI_ROOT))
from tools.prepare_colab_text_training import prepare


def bytes_sha256(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


class ColabPackageTests(unittest.TestCase):
    def build(self, directory: str, mode: str = "provisional_research"):
        root = Path(directory)
        output = root / "package"
        notebook = root / "notebook.ipynb"
        args = argparse.Namespace(
            data=AI_ROOT / "datasets" / "emergency_text" / "working" / "reports.csv",
            output=output,
            notebook=notebook,
            mode=mode,
            minimum_reviewed=800,
        )
        return prepare(args), output, notebook

    def test_provisional_package_is_sealed_and_never_self_approves(self):
        with tempfile.TemporaryDirectory() as directory:
            receipt, output, notebook_path = self.build(directory)
            self.assertEqual(receipt["dataset_rows"], 400)
            self.assertFalse(receipt["human_review_complete"])
            self.assertFalse(receipt["training_executed"])
            self.assertFalse(receipt["active_model_changed"])
            self.assertFalse(receipt["approved_for_deployment"])

            bundle = output / "emergency_text_xlmr_provisional_20261004.zip"
            with zipfile.ZipFile(bundle) as archive:
                self.assertIsNone(archive.testzip())
                names = set(archive.namelist())
                self.assertIn("package_manifest.json", names)
                self.assertIn("data/frozen_split_manifest.json", names)
                self.assertIn("src/ai_service/text_training.py", names)
                self.assertNotIn("active_config.txt", names)
                self.assertFalse(any(".env" in name or "checkpoint" in name for name in names))
                manifest = json.loads(archive.read("package_manifest.json"))
                for relative, expected in manifest["files_sha256"].items():
                    self.assertEqual(bytes_sha256(archive.read(relative)), expected)
                self.assertEqual(manifest["mode"], "provisional_research")
                self.assertFalse(manifest["labels_are_human_reviewed"])
                self.assertFalse(manifest["approved_for_deployment"])
                self.assertEqual(sum(manifest["split_rows"].values()), 400)
                split = json.loads(archive.read("data/frozen_split_manifest.json"))
                groups = [set(split[name]["group_hashes"]) for name in ("train", "validation", "test")]
                texts = [set(split[name]["text_hashes"]) for name in ("train", "validation", "test")]
                for left in range(3):
                    for right in range(left + 1, 3):
                        self.assertFalse(groups[left] & groups[right])
                        self.assertFalse(texts[left] & texts[right])

            notebook = json.loads(notebook_path.read_text(encoding="utf-8"))
            self.assertEqual(notebook["nbformat"], 4)
            code = "\n".join("".join(cell["source"]) for cell in notebook["cells"] if cell["cell_type"] == "code")
            self.assertIn('BASE_MODEL = "FacebookAI/xlm-roberta-base"', code)
            self.assertIn('BASE_MODEL_REVISION = "42f548f32366559214515ec137cdd16002968bf6"', code)
            self.assertIn('SEEDS = [41, 42, 43]', code)
            self.assertIn('"--unfreeze-backbone"', code)
            self.assertIn('"--revision", BASE_MODEL_REVISION', code)
            self.assertIn('"--split-manifest"', code)
            self.assertIn('"--split", "test"', code)
            self.assertIn('"approved_for_deployment": False', code)
            self.assertIn('"active_model_changed": False', code)
            self.assertIn('language: value["macro_f1"]', code)
            self.assertIn('if value["count"] and value["macro_f1"] is not None', code)
            self.assertNotIn('value["raw_classifier"]["macro_f1"]', code)
            self.assertNotIn("active_config.txt", code)
            self.assertTrue(all(not cell.get("outputs") for cell in notebook["cells"] if cell["cell_type"] == "code"))
            for cell in notebook["cells"]:
                if cell["cell_type"] == "code":
                    ast.parse("".join(cell["source"]))

    def test_reviewed_release_rejects_current_unreviewed_dataset(self):
        with tempfile.TemporaryDirectory() as directory:
            with self.assertRaisesRegex(ValueError, "No approved|reviewed_release"):
                self.build(directory, mode="reviewed_release")


if __name__ == "__main__":
    unittest.main()
