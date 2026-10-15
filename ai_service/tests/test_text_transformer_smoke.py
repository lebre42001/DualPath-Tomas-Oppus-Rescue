"""Opt-in offline smoke: tiny random BERT verifies plumbing, never model quality."""

import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch


@unittest.skipUnless(os.getenv("RUN_TEXT_TRANSFORMER_SMOKE") == "1"
                     and importlib.util.find_spec("transformers"),
                     "Set RUN_TEXT_TRANSFORMER_SMOKE=1 in the optional text environment")
class TransformerRoundTripTests(unittest.TestCase):
    def test_train_save_evaluate_and_load_without_network(self):
        from transformers import BertConfig, BertForSequenceClassification, BertTokenizerFast
        from src.ai_service.text_training import main
        from src.ai_service.text_inference import TEXT_LABELS, TextModelUnavailable, TextSeverityPredictor

        with tempfile.TemporaryDirectory(prefix="stitch-text-smoke-") as temporary:
            root = Path(temporary)
            base = root / "random-test-model"
            base.mkdir()
            (base / "vocab.txt").write_text(
                "[PAD]\n[UNK]\n[CLS]\n[SEP]\n[MASK]\ncar\nmotor\ncrash\nno\ninjury\nnaay\nwala\nang\nmay\nhindi\n.\n",
                encoding="utf-8",
            )
            tokenizer = BertTokenizerFast(vocab_file=str(base / "vocab.txt"), do_lower_case=False)
            tokenizer.save_pretrained(base)
            config = BertConfig(vocab_size=len(tokenizer), hidden_size=16, num_hidden_layers=1,
                                num_attention_heads=2, intermediate_size=32, max_position_embeddings=128,
                                num_labels=4, id2label=dict(enumerate(TEXT_LABELS)),
                                label2id={label: i for i, label in enumerate(TEXT_LABELS)})
            BertForSequenceClassification(config).save_pretrained(base, safe_serialization=True)
            output = root / "trained-test-model"
            data = Path(__file__).resolve().parents[1] / "datasets/emergency_text/archive/initial_80/draft_reports.csv"
            # Force tied selection scores to exercise patience and saved-best reload.
            with patch("src.ai_service.text_training.selection_key", return_value=(0., 0.)):
                result = main(["train", "--data", str(data), "--output", str(output),
                               "--base-model", str(base), "--epochs", "6", "--batch-size", "4",
                               "--early-stopping-patience", "2", "--gradient-accumulation", "2",
                               "--threads", "1", "--max-length", "64", "--device", "cpu", "--allow-draft"])
            self.assertEqual(result, 0)
            meta = json.loads((output / "metadata.json").read_text(encoding="utf-8"))
            self.assertFalse(meta["approved_for_deployment"])
            self.assertFalse(meta["all_data_reviewed"])
            self.assertTrue(meta["backbone_frozen"])
            self.assertEqual(meta["epochs_completed"], 3)
            self.assertEqual(meta["best_epoch"], 1)
            self.assertFalse((output / "test_metrics.json").exists())
            self.assertEqual(meta["labels"], list(TEXT_LABELS))
            self.assertEqual(main(["evaluate", "--data", str(data), "--model", str(output),
                                   "--output", str(root / "evaluation"), "--allow-draft", "--device", "cpu"]), 0)
            with self.assertRaises(TextModelUnavailable):
                TextSeverityPredictor(output)
            predictor = TextSeverityPredictor(output, allow_experimental=True)
            prediction = predictor.predict("Nabangga ang motor.")
            self.assertEqual(prediction["deployment_status"], "experimental")
            self.assertTrue(prediction["responder_review_required"])
            self.assertIn(prediction["predicted_label"], TEXT_LABELS)
            over_limit = predictor.predict("car " * 100)
            self.assertIsNone(over_limit["severity"])
            self.assertIsNone(over_limit["confidence"])
            meta["max_length"] = 256
            (output / "metadata.json").write_text(json.dumps(meta), encoding="utf-8")
            with self.assertRaisesRegex(TextModelUnavailable, "context limit"):
                TextSeverityPredictor(output, allow_experimental=True)


if __name__ == "__main__":
    unittest.main()
