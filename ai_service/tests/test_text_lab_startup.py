"""Local Lab configuration stays separate from the live model; no service start."""
import ast
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest


SERVICE_ROOT = Path(__file__).resolve().parents[1]


def configuration_function():
    # Exercise the real pure selector without executing process-only env changes.
    tree = ast.parse((SERVICE_ROOT / "serve_text_test.py").read_text(encoding="utf-8"))
    nodes = [node for node in tree.body if isinstance(node, ast.FunctionDef)
             and node.name == "checkpoint_configuration"]
    namespace = {"Path": Path, "os": os, "SERVICE_ROOT": SERVICE_ROOT,
                 "LEGACY_CHECKPOINT": "artifacts/checkpoints/full_pool_20261011/xlmr_all_rows_final_fit"}
    exec(compile(ast.Module(body=nodes, type_ignores=[]), "serve_text_test.py", "exec"), namespace)
    return namespace["checkpoint_configuration"]


class TextLabStartupTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name) / "ai_service"
        self.release = self.root / "releases/fixture"
        self.release.mkdir(parents=True)
        (self.release / "metadata.json").write_text("{}", encoding="utf-8")
        self.select = configuration_function()

    def test_explicit_repo_checkpoint_defaults_to_cpu(self):
        path, explicit, device = self.select({"AI_TEXT_TEST_MODEL_DIR": "releases/fixture"}, self.root)
        self.assertEqual(path, self.release.resolve())
        self.assertTrue(explicit)
        self.assertEqual(device, "cpu")

    def test_absolute_inside_path_is_allowed_but_escape_is_rejected(self):
        self.assertEqual(self.select({"AI_TEXT_TEST_MODEL_DIR": str(self.release)}, self.root)[0], self.release.resolve())
        for path in ("../outside", str(self.root.parent / "outside")):
            with self.subTest(path=path), self.assertRaisesRegex(ValueError, "inside this repository"):
                self.select({"AI_TEXT_TEST_MODEL_DIR": path}, self.root)

    def test_missing_path_or_metadata_fails_with_a_specific_configuration_error(self):
        with self.assertRaisesRegex(FileNotFoundError, "directory does not exist"):
            self.select({"AI_TEXT_TEST_MODEL_DIR": "releases/missing"}, self.root)
        (self.release / "metadata.json").unlink()
        with self.assertRaisesRegex(FileNotFoundError, "no metadata.json"):
            self.select({"AI_TEXT_TEST_MODEL_DIR": "releases/fixture"}, self.root)

    def test_explicit_device_override_is_bounded(self):
        base = {"AI_TEXT_TEST_MODEL_DIR": "releases/fixture"}
        self.assertEqual(self.select({**base, "AI_TEXT_TEST_DEVICE": "cuda"}, self.root)[2], "cuda")
        with self.assertRaisesRegex(ValueError, "cpu or cuda"):
            self.select({**base, "AI_TEXT_TEST_DEVICE": "automatic"}, self.root)

    def test_unset_preserves_existing_cuda_fallback_without_loading_it(self):
        path, explicit, device = self.select({}, self.root)
        self.assertEqual(path, self.root.resolve() / "artifacts/checkpoints/full_pool_20261011/xlmr_all_rows_final_fit")
        self.assertFalse(explicit)
        self.assertEqual(device, "cuda")

    def test_child_import_does_not_load_model_gpu_or_server_or_modify_parent_selector(self):
        # Existing numeric artifact is only a directory-selection fixture; no inference.
        fixture = SERVICE_ROOT / "releases/triage-text-tfidf-20261016-v2"
        self.assertTrue((fixture / "metadata.json").is_file())
        selector = SERVICE_ROOT / "active_text_config.json"
        before = selector.read_bytes()
        parent_directory = os.environ.get("AI_TEXT_MODEL_DIR")
        child_env = {**os.environ, "AI_TEXT_TEST_MODEL_DIR": str(fixture),
                     "AI_TEXT_MODEL_DIR": "live-model-sentinel", "AI_OPERATION_MODE": "MANUAL_ONLY",
                     "AI_TEXT_RULES_ENABLED": "false"}
        child_env.pop("AI_TEXT_TEST_DEVICE", None)
        child_env.pop("AI_TEXT_TEST_RULES_ENABLED", None)
        code = '''import json,os,sys
import serve_text_test as lab
print(json.dumps({"explicit":lab.EXPLICIT_TEST_MODEL,"device":os.environ["AI_TEXT_DEVICE"],
"model_selected":os.environ["AI_TEXT_MODEL_DIR"]==str(lab.CHECKPOINT),
"lab_rules":os.environ["AI_TEXT_RULES_ENABLED"],
"model_or_server_loaded":any(name in sys.modules for name in ("torch","transformers","uvicorn")),
"image_route":any(path in ("/predict","/predict/image") for path in lab.app.openapi()["paths"])}))'''
        result = subprocess.run([sys.executable, "-B", "-c", code], cwd=SERVICE_ROOT,
                                env=child_env, capture_output=True, text=True, check=True, timeout=30)
        state = json.loads(result.stdout)
        self.assertEqual(state, {"explicit": True, "device": "cpu", "model_selected": True,
                                 "lab_rules": "true", "model_or_server_loaded": False, "image_route": False})
        self.assertEqual(os.environ.get("AI_TEXT_MODEL_DIR"), parent_directory)
        self.assertEqual(selector.read_bytes(), before)


if __name__ == "__main__":
    unittest.main()
