"""Engineering fixtures for private feedback conversion; not accuracy evidence."""
import hashlib
import json
from pathlib import Path
import tempfile
from threading import Event
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch

from src.ai_service import feedback_learning as learning


def feedback(identifier="one", **changes):
    text = "A rider has a reported leg wound near the junction."
    item = {"schema_version": 1, "feedback_id": identifier, "group_id": "report-" + identifier,
            "description": text, "incident_type": "road_accident", "label": "serious",
            "language": "en", "reviewer": "reviewer-pseudonym", "reviewed_at": "2026-01-01T00:00:00+00:00",
            "review_scope": "description_only", "review_method": "corrected_ai", "approved_for_training": True,
            "description_sha256": hashlib.sha256(text.encode()).hexdigest()}
    item.update(changes)
    if "description" in changes and "description_sha256" not in changes:
        item["description_sha256"] = hashlib.sha256(item["description"].encode()).hexdigest()
    return item


class FeedbackLearningTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.export = self.root / "feedback.jsonl"

    def save(self, *items):
        self.export.write_text("".join(json.dumps(item) + "\n" for item in items), encoding="utf-8")

    def args(self, *extra):
        return learning.build_parser().parse_args(["--feedback", str(self.export), "--state-dir", str(self.root / "state"),
                          "--base-model", str(self.root / "base"), "--min-new", "1", *extra])

    def test_explicit_valid_review_converts_without_contact_or_raw_identity_fields(self):
        self.save(feedback(incident_type="Fire Incident"))
        rows, status = learning.reviewed_feedback(self.export)
        self.assertEqual(status["eligible"], 1)
        self.assertEqual(rows[0]["incident_type"], "fire_incident")
        self.assertEqual(rows[0]["review_status"], "approved")
        self.assertNotIn("reporter_name", rows[0])
        self.assertNotIn("one", rows[0]["case_id"])

    def test_no_guessing_of_language_category_or_approval(self):
        self.save(feedback("one", language=None), feedback("two", incident_type=None),
                  feedback("three", approved_for_training=False))
        rows, status = learning.reviewed_feedback(self.export)
        self.assertFalse(rows)
        self.assertEqual(sum(status["excluded"].values()), 3)

    def test_demo_contact_hash_and_scope_are_rejected(self):
        self.save(feedback("one", description="DEMO ONLY: A motorcycle fell."),
                  feedback("two", description="Send the report to person@example.com."),
                  feedback("three", description_sha256="0" * 64),
                  feedback("four", review_scope="photo_and_text"))
        self.assertEqual(learning.reviewed_feedback(self.export)[1]["eligible"], 0)

    def test_conflicting_labels_quarantined_and_duplicates_not_counted_twice(self):
        self.save(feedback("one"), feedback("two"))
        rows, status = learning.reviewed_feedback(self.export)
        self.assertEqual(len(rows), 1)
        self.assertEqual(status["excluded"]["duplicate_text"], 1)
        self.save(feedback("one"), feedback("two", label="minor"))
        self.assertFalse(learning.reviewed_feedback(self.export)[0])

    def test_repeated_identifier_rejects_export(self):
        self.save(feedback(), feedback())
        with self.assertRaises(ValueError):
            learning.reviewed_feedback(self.export)

    def test_export_replacement_removes_revoked_data_instead_of_appending(self):
        self.save(feedback())
        first = learning.run(self.args())
        self.save()
        second = learning.run(self.args())
        self.assertEqual(first["conversion"]["eligible"], 1)
        self.assertEqual(second["conversion"]["eligible"], 0)
        self.assertNotIn("reported leg wound", (self.root / "state/reviewed_feedback.csv").read_text())

    def test_minimum_batch_does_not_train_or_claim_accuracy(self):
        self.save(feedback())
        with patch.object(learning, "train_candidate") as train:
            result = learning.run(self.args("--train", "--min-new", "50"))
        train.assert_not_called()
        self.assertEqual(result["status"], "waiting_for_reviewed_examples")
        self.assertFalse(result["activated"])
        self.assertNotIn("accuracy", result)

    def test_large_family_provenance_stays_private_and_latest_status_is_bounded(self):
        self.save(*(feedback(str(index), description=f"A rider reports a distinct leg wound in scenario {index}.")
                    for index in range(1100)))

        def record_gate(_candidate, _state, _state_path, _rows, _args, result):
            result.update(status="candidate_ready_for_release_review", acceptance_gate={
                "meets_configured_targets": True, "eligible_for_manual_release_review": True,
                "approved_for_deployment": False, "activated": False,
                "private_evidence": result["conversion"]["family_groups"],
            })

        with patch.object(learning, "train_candidate", side_effect=self.fake_train), \
                patch.object(learning, "inspect_candidate", side_effect=record_gate):
            result = learning.run(self.args("--train", "--holdout", "engineering-fixture.csv"))
        self.assertGreater(len(json.dumps(result["conversion"]).encode()), 65536)
        latest_bytes = (self.root / "state/latest_status.json").read_bytes()
        self.assertLess(len(latest_bytes), 16384)
        latest = json.loads(latest_bytes)
        self.assertEqual(latest["status"], "candidate_ready_for_release_review")
        self.assertEqual(latest["conversion"]["eligible"], 1100)
        self.assertEqual(latest["new_examples"], 1100)
        self.assertTrue(latest["acceptance_gate"]["meets_configured_targets"])
        self.assertFalse(latest["activated"])
        self.assertNotIn("family_groups", latest_bytes.decode())
        self.assertNotIn("private_evidence", latest["acceptance_gate"])
        self.assertNotIn("candidate", latest)
        for receipt in (self.root / "state/conversion_receipt.json", Path(result["candidate"]) / "feedback_receipt.json"):
            private = json.loads(receipt.read_text(encoding="utf-8"))
            self.assertEqual(private["conversion"]["family_groups"], result["conversion"]["family_groups"])

    def fake_train(self, dataset, output):
        from src.ai_service.incident_types import CATEGORY_INPUT_FORMAT
        rows = learning.load_dataset(dataset)
        self.make_model(output, rows)
        # Match the real trainer's category-aware capability contract.
        learning.write_json(output / "metadata.json", {
            "approved_for_deployment": False, "input_format": CATEGORY_INPUT_FORMAT,
            "supported_incident_types": sorted({row["incident_type"] for row in rows}),
        })

    def make_model(self, output, rows=()):
        output.mkdir(parents=True)
        learning.write_json(output / "metadata.json", {"approved_for_deployment": False})
        learning.write_json(output / "baseline.json", {"engineering_fixture": True})
        learning.write_json(output / "split_manifest.json", {"train": {
            "group_hashes": [learning.digest(row["group_id"]) for row in rows],
            "text_hashes": [learning.digest(learning.normalized_text(row["text"])) for row in rows],
            "case_ids": [row["case_id"] for row in rows]}})

    def holdout_row(self, identifier="holdout-one", **changes):
        row = {"case_id": identifier, "group_id": "independent-source-" + identifier,
               "text": "One passenger has a reported arm wound at an independent location.",
               "incident_type": "road_accident", "label": "serious", "language": "en",
               "review_status": "approved", "reviewer": "independent-reviewer", "notes": "Engineering fixture"}
        row.update(changes)
        return row

    def save_holdout(self, rows):
        path = self.root / "holdout.csv"
        learning.write_dataset(path, rows)
        learning.write_json(Path(str(path) + ".manifest.json"), {
            "schema_version": 1, "independent_review": True, "ai_blinded": True,
            "reviewer": "independent-reviewer", "dataset_sha256": hashlib.sha256(path.read_bytes()).hexdigest()})
        return path

    def evaluation(self, rows):
        def pair(subset):
            metrics = learning.decision_metrics(subset, [row["label"] for row in subset])
            return {"candidate": metrics, "baseline": metrics}
        return {"supported_incident_types": list(learning.INCIDENT_TYPES),
                "baseline_supported_incident_types": ["road_accident"], "overall": pair(rows),
                "per_incident_type": {kind: pair([row for row in rows if row["incident_type"] == kind]) for kind in learning.INCIDENT_TYPES},
                "per_language": {lang: pair([row for row in rows if row["language"] == lang]) for lang in learning.LANGUAGES},
                "per_category_language": {kind: {lang: pair([row for row in rows if row["incident_type"] == kind and row["language"] == lang])
                      for lang in learning.LANGUAGES} for kind in learning.INCIDENT_TYPES}}

    def test_candidate_runs_once_per_snapshot_never_changes_live_selector(self):
        self.save(feedback())
        selector = self.root / "active_text_config.json"
        selector.write_text('{"model_directory":"original"}')
        before = selector.read_bytes()
        with patch.object(learning, "train_candidate", side_effect=self.fake_train) as train:
            first = learning.run(self.args("--train"))
            second = learning.run(self.args("--train"))
        self.assertEqual(train.call_count, 1)
        self.assertEqual(first["status"], "candidate_trained_waiting_for_independent_holdout")
        self.assertEqual(second["status"], "no_new_snapshot")
        self.assertEqual(selector.read_bytes(), before)

    def test_failed_snapshot_not_retrained_each_scheduled_tick(self):
        self.save(feedback())
        with patch.object(learning, "train_candidate", side_effect=ValueError("Fixture coverage missing")) as train:
            first = learning.run(self.args("--train"))
            second = learning.run(self.args("--train"))
        self.assertEqual(train.call_count, 1)
        self.assertEqual(first["status"], "candidate_blocked")
        self.assertEqual(second["status"], "waiting_for_changed_snapshot")

    def test_late_holdout_can_inspect_existing_candidate_without_retraining(self):
        self.save(feedback())
        with patch.object(learning, "train_candidate", side_effect=self.fake_train):
            learning.run(self.args("--train"))
        with patch.object(learning, "train_candidate") as train, patch.object(learning, "inspect_candidate") as inspect:
            learning.run(self.args("--train", "--holdout", "later.csv"))
        train.assert_not_called()
        inspect.assert_called_once()

    def test_gate_cannot_pass_overall_only_or_missing_categories(self):
        perfect_rows = [{"label": label, "language": language, "incident_type": category}
                        for category in learning.INCIDENT_TYPES for language in learning.LANGUAGES for label in learning.LABELS]
        metrics = learning.decision_metrics(perfect_rows, [row["label"] for row in perfect_rows])
        perfect = {"candidate": metrics, "baseline": metrics}
        evaluation = {"overall": perfect, "supported_incident_types": list(learning.INCIDENT_TYPES),
                      "baseline_supported_incident_types": ["road_accident"],
                      "per_incident_type": {kind: perfect for kind in learning.INCIDENT_TYPES},
                      "per_language": {lang: perfect for lang in learning.LANGUAGES},
                      "per_category_language": {kind: {lang: perfect for lang in learning.LANGUAGES} for kind in learning.INCIDENT_TYPES}}
        gate = learning.acceptance_gate(evaluation, target=.8, min_cell=4, critical_recall=.95)
        self.assertTrue(gate["meets_configured_targets"])
        self.assertFalse(gate["approved_for_deployment"])
        self.assertFalse(gate["activated"])
        evaluation["supported_incident_types"] = ["road_accident"]
        self.assertFalse(learning.acceptance_gate(evaluation, target=.8, min_cell=4, critical_recall=.95)["meets_configured_targets"])

    def test_urgent_abstentions_are_counted_not_hidden_as_zero_errors(self):
        rows = [{"label": "critical"}, {"label": "serious"}]
        metrics = learning.decision_metrics(rows, ["needs_review", "minor"])
        self.assertEqual(metrics["urgent_review_count"], 1)
        self.assertEqual(metrics["urgent_lower_category_count"], 1)
        self.assertEqual(metrics["per_class"]["critical"]["recall"], 0)

    def test_source_paraphrases_and_duplicate_bridge_stay_in_one_group(self):
        self.save(feedback("one", group_id="source-a", description="A rider has a leg wound."),
                  feedback("two", group_id="source-a", description="One rider has an injured leg."),
                  feedback("three", group_id="source-b", description="One rider has an injured leg."),
                  feedback("four", group_id="source-b", description="A wounded rider is awaiting review."))
        rows, status = learning.reviewed_feedback(self.export)
        self.assertEqual(len(rows), 3)
        self.assertEqual(len({row["group_id"] for row in rows}), 1)
        self.assertEqual(len(next(iter(status["family_groups"].values()))), 2)
        path = self.root / "union.csv"
        learning.write_dataset(path, rows)
        self.assertEqual(len(learning.load_dataset(path)), 3)

    def test_conflicting_cross_category_text_and_source_families_are_quarantined(self):
        self.save(feedback("one"), feedback("two", incident_type="medical_emergency"))
        rows, status = learning.reviewed_feedback(self.export)
        self.assertFalse(rows)
        self.assertEqual(status["excluded"]["conflicting_exact_text_incident_types"], 2)
        self.save(feedback("one", group_id="same-source"),
                  feedback("two", group_id="same-source", description="A different wording of the same event.", label="minor"))
        rows, status = learning.reviewed_feedback(self.export)
        self.assertFalse(rows)
        self.assertEqual(status["excluded"]["conflicting_source_family_labels"], 2)

    def test_snapshot_withdrawal_invalidates_candidate_without_training(self):
        self.save(feedback())
        with patch.object(learning, "train_candidate", side_effect=self.fake_train):
            first = learning.run(self.args("--train"))
        learning.write_json(Path(first["candidate"]) / "acceptance_gate.json",
                            {"meets_configured_targets": True, "eligible_for_manual_release_review": True})
        self.save()
        with patch.object(learning, "train_candidate") as train:
            result = learning.run(self.args())
        train.assert_not_called()
        self.assertEqual(result["removed_examples"], 1)
        candidate = Path(first["candidate"])
        self.assertTrue((candidate / "feedback_snapshot_superseded.json").exists())
        old_gate = json.loads((candidate / "acceptance_gate.json").read_text())
        self.assertFalse(old_gate["eligible_for_manual_release_review"])
        self.assertIsNone(json.loads((self.root / "state/state.json").read_text())["candidate_path"])

    def test_small_addition_invalidates_before_minimum_batch_gate(self):
        self.save(feedback())
        with patch.object(learning, "train_candidate", side_effect=self.fake_train):
            first = learning.run(self.args("--train"))
        self.save(feedback(), feedback("second", description="A passenger has an arm wound near the bridge."))
        with patch.object(learning, "train_candidate") as train:
            result = learning.run(self.args("--train", "--min-new", "50"))
        train.assert_not_called()
        self.assertEqual(result["status"], "waiting_for_reviewed_examples")
        self.assertTrue((Path(first["candidate"]) / "feedback_snapshot_superseded.json").exists())

    def test_duplicate_new_source_alias_invalidates_unchanged_retained_rows(self):
        identifiers = sorted(("first", "second", "third", "fourth"), key=learning.fingerprint)
        sources = sorted(("source-first", "source-second", "source-third", "source-fourth"), key=learning.fingerprint)
        original = feedback(identifiers[0], group_id=sources[0])
        duplicate = feedback(identifiers[-1], group_id=sources[-1])
        self.save(original)
        before_rows, before_conversion = learning.reviewed_feedback(self.export)
        with patch.object(learning, "train_candidate", side_effect=self.fake_train) as train:
            first = learning.run(self.args("--train"))
            self.save(original, duplicate)
            after_rows, after_conversion = learning.reviewed_feedback(self.export)
            # Both its case ID and source group sort after the retained original.
            self.assertEqual(after_rows, before_rows)
            self.assertNotEqual(after_conversion["family_groups"], before_conversion["family_groups"])
            second = learning.run(self.args("--train", "--min-new", "50"))
        self.assertEqual(train.call_count, 2)
        self.assertEqual(second["new_examples"], 0)
        self.assertEqual(second["removed_examples"], 0)
        self.assertEqual(second["changed_examples"], 1)
        self.assertNotEqual(second["snapshot"], first["snapshot"])
        self.assertNotEqual(second["candidate"], first["candidate"])
        self.assertTrue((Path(first["candidate"]) / "feedback_snapshot_superseded.json").exists())
        self.assertFalse(second["activated"])

    def test_same_identifier_revision_trains_and_reverting_does_not_reactivate_old_candidate(self):
        self.save(feedback())
        with patch.object(learning, "train_candidate", side_effect=self.fake_train) as train:
            first = learning.run(self.args("--train"))
            self.save(feedback(label="minor"))
            changed = learning.run(self.args("--train", "--min-new", "50"))
            self.save(feedback())
            reverted = learning.run(self.args("--train", "--min-new", "50"))
        self.assertEqual(train.call_count, 3)
        self.assertEqual(changed["changed_examples"], 1)
        self.assertEqual(changed["new_examples"], 0)
        self.assertNotEqual(first["candidate"], reverted["candidate"])
        self.assertTrue((Path(first["candidate"]) / "feedback_snapshot_superseded.json").exists())

    def test_durable_superseded_marker_survives_interrupted_state_update(self):
        self.save(feedback())
        with patch.object(learning, "train_candidate", side_effect=self.fake_train) as train:
            first = learning.run(self.args("--train"))
            # Emulate a shutdown after the marker, before state.pointer clears.
            learning.write_json(Path(first["candidate"]) / "feedback_snapshot_superseded.json", {"status": "superseded"})
            second = learning.run(self.args("--train"))
        self.assertEqual(train.call_count, 2)
        self.assertNotEqual(first["candidate"], second["candidate"])

    def test_holdout_manifest_is_bound_to_exact_dataset_bytes(self):
        path = self.save_holdout([self.holdout_row()])
        self.make_model(self.root / "base")
        learning.independent_holdout(path, [], self.root / "base", [])
        learning.write_dataset(path, [self.holdout_row(notes="Changed after review")])
        with self.assertRaisesRegex(ValueError, "dataset_sha256"):
            learning.independent_holdout(path, [], self.root / "base", [])

    def test_inspected_text_cannot_be_reused_by_metadata_or_identifier_changes(self):
        self.make_model(self.root / "base")
        original = self.holdout_row()
        ledger = learning.exposure([original])
        for changes in ({"notes": "Changed notes"}, {"case_id": "changed-id", "group_id": "changed-group"}):
            with self.subTest(changes=changes):
                path = self.save_holdout([{**original, **changes}])
                with self.assertRaisesRegex(ValueError, "previously exposed"):
                    learning.independent_holdout(path, [], self.root / "base", [], prior_exposure=ledger)
        related = self.holdout_row("new-case", text="A paraphrase from the already inspected source.",
                                   group_id=original["group_id"])
        path = self.save_holdout([related])
        with self.assertRaisesRegex(ValueError, "previously exposed"):
            learning.independent_holdout(path, [], self.root / "base", [], prior_exposure=ledger)

    def test_current_source_alias_and_prior_candidate_training_are_excluded_from_holdout(self):
        self.make_model(self.root / "base")
        self.save(feedback("one", group_id="source-a", description="A rider has an injury."),
                  feedback("two", group_id="source-b", description="A rider has an injury."))
        training, status = learning.reviewed_feedback(self.export)
        for source in ("source-a", "source-b"):
            path = self.save_holdout([self.holdout_row(group_id=source)])
            with self.assertRaisesRegex(ValueError, "overlaps"):
                learning.independent_holdout(path, training, self.root / "base", [], family_groups=status["family_groups"])
        previous = self.root / "previous-candidate"
        row = self.holdout_row()
        self.make_model(previous, [row])
        path = self.save_holdout([row])
        with self.assertRaisesRegex(ValueError, "prior training"):
            learning.independent_holdout(path, [], self.root / "base", [], prior_models=[previous])

    def test_identical_candidate_holdout_and_policy_returns_cached_gate(self):
        self.save(feedback())
        self.make_model(self.root / "base")
        holdout = [self.holdout_row(f"case-{category}-{language}-{label}",
                   text=f"Independently reviewed engineering case {category} {language} {label}.",
                   incident_type=category, language=language, label=label)
                   for category in learning.INCIDENT_TYPES for language in learning.LANGUAGES for label in learning.LABELS]
        path = self.save_holdout(holdout)
        args = self.args("--train", "--holdout", str(path), "--min-cell", "4")
        with patch.object(learning, "train_candidate", side_effect=self.fake_train) as train, \
             patch.object(learning, "evaluate_candidate", return_value=self.evaluation(holdout)) as evaluate:
            first = learning.run(args)
            second = learning.run(args)
            args.target_accuracy = .9
            changed_gate = learning.run(args)
        self.assertEqual(train.call_count, 1)
        self.assertEqual(evaluate.call_count, 1)
        self.assertEqual(first["status"], "candidate_ready_for_release_review")
        self.assertEqual(second["status"], first["status"])
        self.assertTrue(second["evaluation_cached"])
        self.assertEqual(second["acceptance_gate"], first["acceptance_gate"])
        self.assertEqual(changed_gate["status"], "candidate_blocked")
        self.assertFalse(second["activated"])

    def test_inspected_holdout_cannot_be_reused_for_a_different_candidate(self):
        self.save(feedback())
        self.make_model(self.root / "base")
        holdout = [self.holdout_row()]
        path = self.save_holdout(holdout)
        args = self.args("--train", "--holdout", str(path))
        with patch.object(learning, "train_candidate", side_effect=self.fake_train) as train, \
             patch.object(learning, "evaluate_candidate", return_value=self.evaluation(holdout)) as evaluate:
            first = learning.run(args)
            self.save(feedback(), feedback("second", description="An injured rider was reported near another crossing."))
            second = learning.run(args)
        self.assertEqual(train.call_count, 2)
        self.assertEqual(evaluate.call_count, 1)
        self.assertNotEqual(first["candidate"], second["candidate"])
        self.assertEqual(second["status"], "candidate_blocked")
        self.assertFalse(second["activated"])

    def test_cached_evaluation_is_not_reused_after_baseline_or_rules_change(self):
        self.save(feedback())
        self.make_model(self.root / "base")
        holdout = [self.holdout_row()]
        path = self.save_holdout(holdout)
        args = self.args("--train", "--holdout", str(path))
        with patch.object(learning, "train_candidate", side_effect=self.fake_train), \
             patch.object(learning, "evaluate_candidate", return_value=self.evaluation(holdout)) as evaluate:
            learning.run(args)
            with patch("src.ai_service.text_cues.RULE_VERSION", "different-policy-fixture"):
                changed_rule = learning.run(args)
            learning.write_json(self.root / "base/baseline.json", {"different": "baseline fixture"})
            changed_base = learning.run(args)
        self.assertEqual(evaluate.call_count, 1)
        self.assertEqual(changed_rule["status"], "candidate_blocked")
        self.assertEqual(changed_base["status"], "candidate_blocked")

    def test_feedback_evaluation_uses_explicit_profile_and_never_fills_unsupported_scope(self):
        from src.ai_service.incident_types import CATEGORY_INPUT_FORMAT
        from src.ai_service.text_inference import MULTIINCIDENT_RULE_PROFILE
        model = {"severity": "minor", "predicted_label": "minor", "confidence": .94,
                 "probabilities": {"minor": .94, "serious": .02, "critical": .02, "needs_review": .02}}
        rule = {"severity": "critical", "predicted_label": "critical", "rule_priority": "override"}
        legacy = SimpleNamespace(metadata={"input_format": CATEGORY_INPUT_FORMAT,
                     "supported_incident_types": ["road_accident", "fire_incident"]}, predict=Mock(return_value=model))
        expanded = SimpleNamespace(metadata={**legacy.metadata, "text_rule_profile": MULTIINCIDENT_RULE_PROFILE,
                     "text_rule_incident_types": ["fire_incident"]}, predict=Mock(return_value=model))
        unsupported = SimpleNamespace(metadata={}, predict=Mock(side_effect=AssertionError("outside trained scope")))
        row = {"text": "A person has a grave danger sign.", "incident_type": "fire_incident"}
        with patch("src.ai_service.multiincident_cues.analyze_multiincident_cues", return_value=rule) as analyze:
            self.assertEqual(learning.policy_predict(legacy, row), "minor")
            analyze.assert_not_called()
            self.assertEqual(learning.policy_predict(expanded, row), "critical")
            analyze.assert_called_once_with(row["text"], "fire_incident")
            self.assertEqual(learning.policy_predict(unsupported, row), "needs_review")
        unsupported.predict.assert_not_called()

    def test_candidate_comparison_reports_each_artifacts_actual_rule_profile(self):
        from src.ai_service.incident_types import CATEGORY_INPUT_FORMAT
        from src.ai_service.text_inference import MULTIINCIDENT_RULE_PROFILE, LEGACY_RULE_PROFILE
        model = {"severity": "minor", "predicted_label": "minor", "confidence": .94,
                 "probabilities": {"minor": .94, "serious": .02, "critical": .02, "needs_review": .02}}
        candidate = SimpleNamespace(metadata={"input_format": CATEGORY_INPUT_FORMAT,
                    "supported_incident_types": ["fire_incident"], "text_rule_profile": MULTIINCIDENT_RULE_PROFILE,
                    "text_rule_incident_types": ["fire_incident"]}, predict=Mock(return_value=model))
        baseline = SimpleNamespace(metadata={}, predict=Mock(side_effect=AssertionError("legacy road only")))
        row = self.holdout_row(incident_type="fire_incident", label="critical")
        with patch("src.ai_service.text_inference.TfidfSeverityPredictor", return_value=candidate), \
                patch("src.ai_service.text_inference.load_predictor", return_value=baseline), \
                patch("src.ai_service.multiincident_cues.analyze_multiincident_cues", return_value={
                    "severity": "critical", "predicted_label": "critical", "rule_priority": "override"}):
            result = learning.evaluate_candidate(self.root / "candidate", self.root / "base", [row])
        self.assertEqual(result["candidate_policy"]["text_rule_profile"], MULTIINCIDENT_RULE_PROFILE)
        self.assertEqual(result["baseline_policy"]["text_rule_profile"], LEGACY_RULE_PROFILE)
        self.assertEqual(result["overall"]["candidate"]["accuracy"], 1)
        self.assertEqual(result["overall"]["baseline"]["accuracy"], 0)

    def test_cached_evaluation_cannot_reuse_holdout_after_new_profile_version_change(self):
        from src.ai_service.incident_types import CATEGORY_INPUT_FORMAT
        from src.ai_service.text_inference import MULTIINCIDENT_RULE_PROFILE
        self.save(feedback())
        self.make_model(self.root / "base")
        learning.write_json(self.root / "base/metadata.json", {
            "input_format": CATEGORY_INPUT_FORMAT, "supported_incident_types": list(learning.INCIDENT_TYPES),
            "text_rule_profile": MULTIINCIDENT_RULE_PROFILE,
            "text_rule_incident_types": list(learning.INCIDENT_TYPES), "approved_for_deployment": False})
        holdout = [self.holdout_row()]
        path = self.save_holdout(holdout)
        args = self.args("--train", "--holdout", str(path))
        with patch.object(learning, "train_candidate", side_effect=self.fake_train), \
                patch.object(learning, "evaluate_candidate", return_value=self.evaluation(holdout)) as evaluate:
            first = learning.run(args)
            second = learning.run(args)
            with patch("src.ai_service.multiincident_cues.RULE_VERSION", "changed-expanded-profile-fixture"):
                changed = learning.run(args)
        self.assertEqual(evaluate.call_count, 1)
        self.assertTrue(second["evaluation_cached"])
        self.assertEqual(changed["status"], "candidate_blocked")
        self.assertFalse(first["activated"])

    def test_watch_waits_for_export_then_runs_and_stops_without_private_output(self):
        args = self.args("--watch")
        stop = Event()
        def next_tick(_interval):
            self.save(feedback())
            return False
        def run_once(_args, **_kwargs):
            stop.set()
            return {"status": "prepared_training_not_requested", "activated": False, "private": "never print this"}
        with patch.object(stop, "wait", side_effect=next_tick), patch.object(learning, "run", side_effect=run_once) as run, \
             patch("builtins.print") as printer:
            self.assertEqual(learning.watch(args, stop_event=stop), 0)
        self.assertEqual(run.call_count, 1)
        output = " ".join(call.args[0] for call in printer.call_args_list)
        self.assertIn("waiting_for_feedback_export", output)
        self.assertIn("stopped", output)
        self.assertNotIn("never print this", output)
        self.assertNotIn(str(self.export), output)

    def test_watch_signal_and_keyboard_interrupt_end_interruptible_wait(self):
        args = self.args("--watch")
        stop = Event()
        with learning.shutdown_signals(stop):
            learning.signal.getsignal(learning.signal.SIGTERM)(learning.signal.SIGTERM, None)
        self.assertTrue(stop.is_set())
        with patch.object(learning.Event, "wait", side_effect=KeyboardInterrupt), patch("builtins.print"):
            self.assertEqual(learning.watch(args), 0)
        with self.assertRaisesRegex(ValueError, "at least 60"):
            learning.watch(self.args("--watch", "--interval", "0"))


if __name__ == "__main__":
    unittest.main()
