"""Regression checks for weighted accumulation, immutable splits and test isolation."""
import argparse
import copy
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

from src.ai_service.text_training import (_manifest, _save_reports, experiment_splits,
    _metadata, optimizer_step_with_amp_accounting, selection_key, split_groups,
    trainable_parameter_group_fingerprints, weighted_window_loss)
from test_text_training import sample_rows


class SplitAndSelectionTests(unittest.TestCase):
    def test_full_dataset_fit_metadata_has_no_claimed_holdout_or_approval(self):
        args = argparse.Namespace(
            fit_all=True, base_model="test-model", allow_draft=True, seed=42,
            validation_fraction=.15, test_fraction=.15, review_threshold=.65,
            evaluate_test=False, split_manifest=None,
        )
        metadata = _metadata(sample_rows(), args, "transformers")
        self.assertTrue(metadata["fit_all_data"])
        self.assertEqual(metadata["split_source"], "full_dataset_fit")
        self.assertEqual(metadata["validation_fraction"], 0.0)
        self.assertEqual(metadata["test_fraction"], 0.0)
        self.assertFalse(metadata["test_evaluated_during_training"])
        self.assertFalse(metadata["approved_for_deployment"])

    def test_frozen_manifest_preserves_rows_and_rejects_edits_and_leakage(self):
        rows = sample_rows()
        splits = split_groups(rows)
        with tempfile.TemporaryDirectory() as temporary:
            manifest = Path(temporary) / "split.json"
            manifest.write_text(json.dumps(_manifest(splits)), encoding="utf-8")
            args = argparse.Namespace(split_manifest=str(manifest))
            self.assertEqual(experiment_splits(list(reversed(rows)), args), splits)
            changed = copy.deepcopy(rows)
            changed[0]["notes"] = "changed source"
            with self.assertRaisesRegex(ValueError, "fingerprint"):
                experiment_splits(changed, args)
            with self.assertRaises(ValueError):
                experiment_splits(rows[1:], args)
            broken = _manifest(splits)
            broken["test"]["case_ids"][0] = broken["train"]["case_ids"][0]
            manifest.write_text(json.dumps(broken), encoding="utf-8")
            with self.assertRaises(ValueError):
                experiment_splits(rows, args)

    def test_selection_prioritizes_critical_recall_then_breaks_ties(self):
        def metric(recall, f1):
            return {"raw_classifier": {"macro_f1": f1, "per_class": {"critical": {"recall": recall}}}}
        selection = "critical_recall_then_macro_f1"
        self.assertGreater(selection_key(metric(.8, .3), selection), selection_key(metric(0, .95), selection))
        self.assertGreater(selection_key(metric(.8, .6), selection), selection_key(metric(.8, .3), selection))
        self.assertEqual(selection_key(metric(.8, .6), "macro_f1"), (.6,))

    def test_training_reports_do_not_score_test_without_explicit_flag(self):
        splits = split_groups(sample_rows())
        observed = []
        def predict(texts):
            observed.extend(texts)
            return [[.25] * 4 for _ in texts]
        metadata = {"review_threshold": .65, "all_data_reviewed": True, "version": "test-only"}
        with tempfile.TemporaryDirectory() as temporary:
            output = Path(temporary)
            _save_reports(output, splits, metadata, predict)
            self.assertEqual(observed, [row["text"] for row in splits["validation"]])
            self.assertFalse((output / "test_metrics.json").exists())
            self.assertTrue((output / "validation_predictions.json").exists())
            observed.clear()
            _save_reports(output, splits, dict(metadata, test_evaluated_during_training=True), predict)
            self.assertEqual(len(observed), len(splits["validation"]) + len(splits["test"]))


@unittest.skipUnless(importlib.util.find_spec("torch"), "PyTorch required for gradient checks")
class WeightedAccumulationTests(unittest.TestCase):
    def test_gradients_match_combined_batch_including_partial_windows(self):
        import torch
        torch.manual_seed(7)
        weights = torch.tensor([.5, 2., 4., 1.])
        values = torch.randn(11, 4)
        targets = torch.tensor([0, 2, 1, 2, 3, 0, 2, 1, 0, 3, 2])
        for microbatch in (1, 2, 3):
            for start in range(0, len(targets), microbatch * 3):
                end = min(start + microbatch * 3, len(targets))
                expected = values[start:end].clone().requires_grad_(True)
                labels = targets[start:end]
                combined_loss = torch.nn.functional.cross_entropy(expected, labels, weight=weights)
                combined_loss.backward()
                actual = values[start:end].clone().requires_grad_(True)
                denominator = weights[labels].sum().item()
                loss_total = 0.
                for offset in range(0, len(labels), microbatch):
                    loss = weighted_window_loss(actual[offset:offset + microbatch], labels[offset:offset + microbatch], weights, denominator)
                    loss_total += loss.item()
                    loss.backward()
                torch.testing.assert_close(actual.grad, expected.grad)
                self.assertAlmostEqual(loss_total, combined_loss.item(), places=6)

    def test_class_weight_affects_single_example_microbatch_gradients(self):
        import torch
        targets = torch.tensor([0, 2])
        gradients = []
        for weights in (torch.ones(4), torch.tensor([1., 1., 8., 1.])):
            logits = torch.zeros(2, 4, requires_grad=True)
            denominator = weights[targets].sum().item()
            for index in range(2):
                weighted_window_loss(logits[index:index + 1], targets[index:index + 1], weights, denominator).backward()
            gradients.append(logits.grad.clone())
        self.assertFalse(torch.allclose(*gradients))
        self.assertGreater(gradients[1][1].abs().sum(), gradients[0][1].abs().sum())

    def test_amp_overflow_does_not_advance_scheduler(self):
        class FakeOptimizer:
            def __init__(self):
                self.steps = 0
            def step(self):
                self.steps += 1

        class FakeScheduler:
            def __init__(self):
                self.steps = 0
            def step(self):
                self.steps += 1

        class FakeScaler:
            def __init__(self, optimizer, skip):
                self.scale = 8.0
                self.optimizer = optimizer
                self.skip = skip
            def get_scale(self):
                return self.scale
            def step(self, optimizer):
                if not self.skip:
                    optimizer.step()
            def update(self):
                if self.skip:
                    self.scale /= 2

        optimizer, scheduler = FakeOptimizer(), FakeScheduler()
        skipped = optimizer_step_with_amp_accounting(FakeScaler(optimizer, True), optimizer, scheduler)
        self.assertTrue(skipped["optimizer_step_skipped"])
        self.assertFalse(skipped["scheduler_advanced"])
        self.assertEqual((optimizer.steps, scheduler.steps), (0, 0))
        succeeded = optimizer_step_with_amp_accounting(FakeScaler(optimizer, False), optimizer, scheduler)
        self.assertFalse(succeeded["optimizer_step_skipped"])
        self.assertTrue(succeeded["scheduler_advanced"])
        self.assertEqual((optimizer.steps, scheduler.steps), (1, 1))

    def test_parameter_fingerprints_are_grouped_by_encoder_layer(self):
        import torch
        model = torch.nn.Module()
        model.roberta = torch.nn.Module()
        model.roberta.embeddings = torch.nn.Embedding(5, 3)
        model.roberta.encoder = torch.nn.Module()
        model.roberta.encoder.layer = torch.nn.ModuleList([torch.nn.Linear(3, 3), torch.nn.Linear(3, 3)])
        model.classifier = torch.nn.Linear(3, 4)
        before = trainable_parameter_group_fingerprints(model)
        with torch.no_grad():
            model.roberta.encoder.layer[1].weight.add_(1)
        after = trainable_parameter_group_fingerprints(model)
        self.assertEqual(set(before), set(after))
        self.assertEqual(before["roberta.encoder.layer.0"]["sha256"], after["roberta.encoder.layer.0"]["sha256"])
        self.assertNotEqual(before["roberta.encoder.layer.1"]["sha256"], after["roberta.encoder.layer.1"]["sha256"])


if __name__ == "__main__":
    unittest.main()
