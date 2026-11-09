"""A new reviewed-data fit must retain explicit policy scope without approval."""
import json
from pathlib import Path
import tempfile
import unittest

from src.ai_service.feedback_learning import inherit_rule_profile
from src.ai_service.incident_types import CATEGORY_INPUT_FORMAT, INCIDENT_TYPES


class FeedbackRuleInheritanceTests(unittest.TestCase):
    def test_expanded_policy_is_limited_to_newly_trained_categories_without_copying_approval(self):
        with tempfile.TemporaryDirectory() as directory:
            base, candidate = Path(directory) / 'base', Path(directory) / 'candidate'
            base.mkdir()
            candidate.mkdir()
            (base / 'metadata.json').write_text(json.dumps({
                'input_format': CATEGORY_INPUT_FORMAT, 'supported_incident_types': list(INCIDENT_TYPES),
                'text_rule_profile': 'multiincident_common_v1', 'text_rule_incident_types': list(INCIDENT_TYPES),
                'approved_for_deployment': True, 'review_threshold': .5,
            }))
            original = {'input_format': CATEGORY_INPUT_FORMAT, 'supported_incident_types': ['fire_incident'],
                        'approved_for_deployment': False, 'all_data_reviewed': True, 'review_threshold': .65}
            path = candidate / 'metadata.json'
            path.write_text(json.dumps(original))
            inherit_rule_profile(candidate, base)
            result = json.loads(path.read_text())
            self.assertEqual(result['text_rule_incident_types'], ['fire_incident'])
            self.assertEqual(result['text_rule_profile'], 'multiincident_common_v1')
            self.assertFalse(result['approved_for_deployment'])
            self.assertEqual(result['review_threshold'], .65)

    def test_legacy_baseline_does_not_grant_wider_rule_scope(self):
        with tempfile.TemporaryDirectory() as directory:
            base, candidate = Path(directory) / 'base', Path(directory) / 'candidate'
            base.mkdir()
            candidate.mkdir()
            (base / 'metadata.json').write_text('{}')
            path = candidate / 'metadata.json'
            body = json.dumps({'input_format': CATEGORY_INPUT_FORMAT, 'supported_incident_types': list(INCIDENT_TYPES)})
            path.write_text(body)
            inherit_rule_profile(candidate, base)
            self.assertEqual(path.read_text(), body)

    def test_invalid_base_scope_is_not_propagated(self):
        with tempfile.TemporaryDirectory() as directory:
            base, candidate = Path(directory) / 'base', Path(directory) / 'candidate'
            base.mkdir()
            candidate.mkdir()
            (base / 'metadata.json').write_text(json.dumps({
                'text_rule_profile': 'multiincident_common_v1', 'text_rule_incident_types': ['fire_incident'],
            }))
            with self.assertRaises(ValueError):
                inherit_rule_profile(candidate, base)


if __name__ == '__main__':
    unittest.main()
