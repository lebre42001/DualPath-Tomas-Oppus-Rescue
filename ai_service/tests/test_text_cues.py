"""Synthetic behavior checks, not an independent accuracy evaluation."""
import os
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch

from src.ai_service.text_cues import analyze_cues
from src.ai_service.text_inference import combine_assessments, predict_text

CASES = [
    ('en', 'The driver is dead.', 'critical'),
    ('en', 'The victim is fifty-fifty.', 'critical'),
    ('en', 'The patient is 50/50.', 'critical'),
    ('en', 'The victim is unconscious.', 'critical'),
    ('en', 'The rider is unresponsive.', 'critical'),
    ('en', 'A passenger is not breathing.', 'critical'),
    ('en', 'The victim has heavy bleeding.', 'critical'),
    ('en', 'One person was injured on both legs while snowboarding.', 'serious'),
    ('en', 'There is a wound on the rider leg.', 'serious'),
    ('en', 'The person has a broken arm.', 'serious'),
    ('en', 'Small scratch on the car, no injuries.', 'minor'),
    ('en', 'Everyone is uninjured. The bumper has a small dent.', 'minor'),
    ('en', 'No one died but the rider has a wound.', 'serious'),
    ('en', 'The patient is not unconscious, but has an injured leg.', 'serious'),
    ('en', 'The patient is not dead.', None),
    ('en', 'The car battery is dead.', None),
    ('en', 'The victim is dead tired.', None),
    ('en', 'Fifty-fifty profit sharing for the business.', None),
    ('en', 'Fifty-fifty chance of winning the game.', None),
    ('en', 'A person is drowning.', 'critical'),
    ('en', 'A victim is trapped in a burning car.', 'critical'),
    ('en', 'There is a fire. A small scratch on the car, no injuries.', None),
    ('en', 'If the driver is unconscious, what should happen?', None),
    ('en', 'The victim was unconscious yesterday but is now awake.', None),
    ('en', 'I am not sure whether someone is injured.', None),
    ('en', 'The driver is unconscious but the driver is not unconscious.', None),
    ('tl', 'Patay na ang drayber.', 'critical'),
    ('tl', 'Namatay ang pasahero.', 'critical'),
    ('tl', 'Nasawi ang isang biktima.', 'critical'),
    ('tl', 'Fifty-fifty ang kalagayan ng biktima.', 'critical'),
    ('tl', 'Walang malay ang drayber.', 'critical'),
    ('tl', 'Nawalan ng malay ang pasahero.', 'critical'),
    ('tl', 'Hindi na humihinga ang biktima.', 'critical'),
    ('tl', 'Nag-aagaw-buhay ang pasahero.', 'critical'),
    ('tl', 'Malakas ang pagdurugo ng biktima.', 'critical'),
    ('tl', 'Nalulunod ang bata.', 'critical'),
    ('tl', 'Nasugatan ang dalawang binti ng biktima.', 'serious'),
    ('tl', 'May sugat sa kamay ang pasahero.', 'serious'),
    ('tl', 'Bali ang braso ng drayber.', 'serious'),
    ('tl', 'Gasgas lang ang sasakyan at walang nasaktan.', 'minor'),
    ('tl', 'Walang patay pero may nasugatan.', 'serious'),
    ('tl', 'Hindi patay ang drayber pero nasaktan siya.', 'serious'),
    ('tl', 'Walang patay.', None),
    ('tl', 'Patay ang makina.', None),
    ('tl', 'Hindi sigurado kung may nasugatan.', None),
    ('tl', 'Halimbawa, patay ang drayber.', None),
    ('tl', 'Namatay ang pasahero kahapon.', None),
    ('ceb', 'Naa nay patay nga drayber.', 'critical'),
    ('ceb', 'Namatay ang pasahero.', 'critical'),
    ('ceb', 'Fifty fifty ang kahimtang sa nasamdan.', 'critical'),
    ('ceb', 'Walay panimuot ang drayber.', 'critical'),
    ('ceb', 'Dili na moginhawa ang biktima.', 'critical'),
    ('ceb', 'Wala nay ginhawa ang pasahero.', 'critical'),
    ('ceb', 'Grabe ang pagdugo sa biktima.', 'critical'),
    ('ceb', 'Dili mohunong ang kusog nga pagdugo sa samad sa pasahero.', 'critical'),
    ('ceb', 'Bug-at nga dugo ang nag-agas sa samad ug dili kini mohunong bisan gipugngan.', 'critical'),
    ('ceb', 'Naghingalo ang pasahero.', 'critical'),
    ('ceb', 'Nalumos ang bata.', 'critical'),
    ('ceb', 'Nasamdan ang duha ka bitiis.', 'serious'),
    ('ceb', 'Nasamad ang biktima sa pagkahulog.', 'serious'),
    ('ceb', 'Naay samad sa kamot.', 'serious'),
    ('ceb', 'Nabali ang bitiis sa drayber.', 'serious'),
    ('ceb', 'Garas ra ang sakyanan ug walay nasamdan.', 'minor'),
    ('ceb', 'Walay patay apan adunay nasamdan.', 'serious'),
    ('ceb', 'Dili patay ang drayber pero naay samad.', 'serious'),
    ('ceb', 'Walay patay.', None),
    ('ceb', 'Patay na ang baterya.', None),
    ('ceb', 'Wala ko kabalo kung naay nasamdan.', None),
    ('ceb', 'Pananglitan, walay panimuot ang drayber.', None),
    ('ceb', 'Namatay ang pasahero kagahapon.', None),
]


class TextCueTests(unittest.TestCase):
    def test_multilingual_asserted_negated_and_ambiguous_descriptions(self):
        for language, text, expected in CASES:
            with self.subTest(language=language, text=text):
                result = analyze_cues(text)
                self.assertIsNotNone(result)
                self.assertEqual(result['severity'], expected)
                self.assertIsNone(result['confidence'])
                self.assertIsNone(result['probabilities'])
                self.assertTrue(result['responder_review_required'])
                self.assertEqual(result['assessment_method'], 'text_rules')
                self.assertTrue(result['matched_cues'])

    def test_unknown_words_are_not_matched_as_substrings(self):
        for text in ('Accident here.', 'Patayan ang lugar.', 'I am uninjured.', 'Random unrelated sentence.'):
            self.assertIsNone(analyze_cues(text))

    def test_reported_death_is_not_presented_as_confirmed_death(self):
        self.assertIn('not a confirmed death', analyze_cues('Patay ang driver.')['summary'])

    def test_rules_are_opt_in_and_do_not_fabricate_classifier_probabilities(self):
        predictor = SimpleNamespace(
            metadata={'input_format': 'description_v1', 'supported_incident_types': ['road_accident']},
            predict=Mock(return_value={'legacy': True}),
        )
        with patch('src.ai_service.text_inference.get_predictor', return_value=predictor):
            with patch.dict(os.environ, {'AI_TEXT_RULES_ENABLED': 'false'}):
                self.assertEqual(predict_text('A person is injured.'),
                                 {'legacy': True, 'incident_type': 'road_accident'})
            with patch.dict(os.environ, {'AI_TEXT_RULES_ENABLED': 'true'}):
                result = predict_text('A person is not breathing.', incident_type='road_accident')
                self.assertEqual(result['severity'], 'critical')
                self.assertIsNone(result['confidence'])
                self.assertEqual(predict_text('Unknown message.'),
                                 {'legacy': True, 'incident_type': 'road_accident'})

    def test_general_injury_words_do_not_downgrade_uncertain_critical_model_outputs(self):
        model = {'severity': None, 'predicted_label': 'critical', 'status': 'needs_review'}
        result = combine_assessments(model, analyze_cues('The driver is injured.'))
        self.assertIs(result, model)

    def assert_rule_severity(self, text, expected):
        result = analyze_cues(text)
        self.assertIsNotNone(result)
        self.assertEqual(result['severity'], expected, text)
        self.assertTrue(result['responder_review_required'])
        self.assertIsNone(result['confidence'])
        self.assertIsNone(result['probabilities'])
        return result

    def test_contracted_negation_preserves_affirmative_danger_pairs(self):
        pairs = [
            ("The driver isn't unconscious.", 'The driver is unconscious.'),
            ("The driver isn\u2019t unconscious.", 'The driver is unconscious.'),
            ("The victim doesn't have heavy bleeding.", 'The victim has heavy bleeding.'),
            ("The victim doesn't have severe bleeding.", 'The victim has severe bleeding.'),
            ("The patient wasn't unresponsive.", 'The patient is unresponsive.'),
        ]
        for denied, asserted in pairs:
            with self.subTest(denied=denied):
                self.assert_rule_severity(denied, None)
                self.assert_rule_severity(asserted, 'critical')
        self.assert_rule_severity("The patient can't breathe.", 'critical')
        self.assert_rule_severity("The passenger isn't breathing.", 'critical')

    def test_nonpatient_subject_modifiers_do_not_report_a_human_death(self):
        for text in ('Walang nasaktan; patay ang parehong makina.',
                     'Patay ang dalawang makina.', 'Both engines are dead.',
                     'The engine is unresponsive.'):
            with self.subTest(text=text):
                self.assert_rule_severity(text, None)
        self.assert_rule_severity('Patay ang parehong drayber.', 'critical')
        self.assert_rule_severity('Both passengers are dead.', 'critical')
        self.assert_rule_severity('Patay ang makina, but the passenger is not breathing.', 'critical')

    def test_recovery_word_order_and_subject_are_bounded(self):
        for recovery in ('is now awake', 'is awake now', 'is now conscious', 'is conscious now'):
            with self.subTest(recovery=recovery):
                self.assert_rule_severity('The driver was unconscious but ' + recovery + '.', None)
        self.assert_rule_severity('Walang malay ang drayber pero gising na ngayon.', None)
        self.assert_rule_severity('Walay panimuot ang drayber apan mata na karon.', None)
        self.assert_rule_severity('The driver is unconscious but the passenger is awake now.', 'critical')
        self.assert_rule_severity('The driver is unconscious and is not awake now.', 'critical')
        self.assert_rule_severity('The driver is unconscious but is now awake. The passenger is not breathing.', 'critical')

    def test_unrelated_now_does_not_turn_a_historical_cue_into_a_current_one(self):
        for text in ('The driver was unconscious yesterday. I am reporting now.',
                     'The driver was unconscious yesterday and the police are here now.',
                     'I am reporting now, the driver was unconscious yesterday.',
                     'Yesterday, the driver was unconscious.'):
            with self.subTest(text=text):
                self.assert_rule_severity(text, None)
        self.assert_rule_severity('The driver was unconscious yesterday but is still unconscious now.', 'critical')
        self.assert_rule_severity('The driver was unconscious yesterday. The passenger is not breathing now.', 'critical')

    def test_mid_sentence_conditional_and_nonstop_bleeding_context(self):
        for text in ('Please tell me what if the driver is unconscious?',
                     'I am asking if the victim has heavy bleeding.',
                     'Please explain kung walang malay ang drayber.',
                     'I am asking, pananglitan walay panimuot ang drayber.',
                     'Please explain if the victim has bleeding that does not stop.'):
            with self.subTest(text=text):
                self.assert_rule_severity(text, None)
        self.assert_rule_severity('The victim has bleeding that does not stop.', 'critical')
        self.assert_rule_severity('The victim is bleeding and it does not stop.', 'critical')
        self.assert_rule_severity('If one driver is unconscious, what happens? A passenger is not breathing.', 'critical')

    def test_local_uncertainty_or_denial_preserves_other_persons_asserted_danger(self):
        self.assert_rule_severity('I am not sure whether one person died, but another person is not breathing.', 'critical')
        self.assert_rule_severity('I do not know whether the driver died. The passenger is not breathing.', 'critical')
        self.assert_rule_severity('Hindi sigurado kung patay ang drayber pero hindi humihinga ang pasahero.', 'critical')
        self.assert_rule_severity('Dili sigurado kung patay ang drayber apan dili moginhawa ang pasahero.', 'critical')
        self.assert_rule_severity('The driver is unconscious but the passenger is not unconscious.', 'critical')
        self.assert_rule_severity('One person is unconscious but another person is not unconscious.', 'critical')
        self.assert_rule_severity('The driver is unconscious but the driver is not unconscious.', None)

    def test_unresponsive_variants_outweigh_generic_injury_words(self):
        for text in ('The rider is hurt and cannot wake up.',
                     "The rider is hurt and can't wake up.",
                     'The rider is hurt and cannot be awakened.',
                     'The patient is injured and cannot be roused.',
                     'The rider is hurt and I cannot wake him up.',
                     'Nasugatan ang drayber at hindi magising.',
                     'Nasugatan ang pasahero at hindi na nagigising.',
                     'Nasamdan ang drayber ug dili na makamata.',
                     'Nasamdan ang drayber ug dili na siya momata.',
                     'Nasamdan ang pasahero ug dili na mapukaw.'):
            with self.subTest(text=text):
                result = self.assert_rule_severity(text, 'critical')
                self.assertEqual(result['rule_priority'], 'override')
        self.assert_rule_severity('The rider is injured and no one is unconscious.', 'serious')
        self.assert_rule_severity('I am not sure whether the rider cannot wake up.', None)
        self.assert_rule_severity('If the patient cannot be roused, what should happen?', None)
        self.assert_rule_severity('It is not true that the patient cannot be roused.', None)
        self.assert_rule_severity('Dili tinuod nga dili na siya momata.', None)

    def test_injury_fallback_is_bounded_by_classifier_and_context(self):
        model = {'severity': None, 'predicted_label': 'needs_review', 'status': 'needs_review'}
        for text in ('The driver is hurt.', 'No one died but the rider is hurt.',
                     "The driver isn't unconscious but has an injured leg."):
            with self.subTest(text=text):
                rules = analyze_cues(text)
                self.assertEqual(rules['rule_priority'], 'fallback')
                result = combine_assessments(model, rules)
                self.assertEqual(result['severity'], 'serious')
                self.assertIs(result['model_assessment'], model)
                critical_model = dict(model, predicted_label='critical')
                self.assertIs(combine_assessments(critical_model, rules), critical_model)
        for text in ('I am not sure whether the driver is injured.',
                     'The driver was injured yesterday. Now the car is being repaired.',
                     'The driver is unconscious but the driver is not unconscious.'):
            with self.subTest(text=text):
                self.assertIsNone(combine_assessments(model, analyze_cues(text))['severity'])
        explicit = combine_assessments(model, analyze_cues('The driver is hurt and cannot wake up.'))
        self.assertEqual(explicit['severity'], 'critical')
        self.assertIs(explicit['model_assessment'], model)

    def test_rule_scope_gaps_preserve_model_but_context_blocks_override(self):
        for text, severity in (('There are no injuries after a collision.', 'minor'),
                               ('A passenger is trapped behind a door after a crash.', 'critical')):
            with self.subTest(text=text):
                model = {'severity': severity, 'predicted_label': severity, 'status': 'complete'}
                rules = analyze_cues(text)
                self.assertEqual(rules['rule_priority'], 'fallback')
                self.assertIs(combine_assessments(model, rules), model)
        model = {'severity': 'critical', 'predicted_label': 'critical', 'status': 'complete'}
        for text in ("The driver isn't unconscious.",
                     'The driver was unconscious yesterday. Now the car is repaired.',
                     'The driver is unconscious but the driver is not unconscious.'):
            with self.subTest(text=text):
                rules = analyze_cues(text)
                self.assertEqual(rules['rule_priority'], 'override')
                self.assertIsNone(combine_assessments(model, rules)['severity'])


if __name__ == '__main__':
    unittest.main()
