"""An explicit active-hazard floor cannot downgrade a Critical model signal."""
import unittest

from src.ai_service.text_inference import combine_assessments


class MultiincidentPolicyFloorTests(unittest.TestCase):
    def rule(self):
        return {'severity': 'serious', 'predicted_label': 'serious', 'rule_priority': 'minimum',
                'confidence': None, 'probabilities': None, 'assessment_method': 'text_rules'}

    def model(self, label, accepted=True):
        return {'severity': label if accepted else None, 'predicted_label': label,
                'confidence': .8 if accepted else .4, 'probabilities': {'fixture': 1}}

    def test_explicit_current_hazard_raises_a_minor_output_without_inventing_rule_confidence(self):
        original = self.model('minor')
        result = combine_assessments(original, self.rule())
        self.assertEqual(result['severity'], 'serious')
        self.assertIsNone(result['confidence'])
        self.assertEqual(result['model_assessment'], original)

    def test_accepted_and_uncertain_critical_signals_are_preserved(self):
        for accepted in (True, False):
            with self.subTest(accepted=accepted):
                original = self.model('critical', accepted)
                self.assertEqual(combine_assessments(original, self.rule()), original)

    def test_already_serious_output_retains_its_actual_model_scores(self):
        original = self.model('serious')
        self.assertEqual(combine_assessments(original, self.rule()), original)

    def test_unseen_category_still_abstains_before_any_hazard_floor(self):
        original = {'severity': None, 'predicted_label': 'needs_review', 'confidence': None,
                    'probabilities': {}, 'assessment_method': 'category_scope_gate'}
        self.assertEqual(combine_assessments(original, self.rule()), original)


if __name__ == '__main__':
    unittest.main()
