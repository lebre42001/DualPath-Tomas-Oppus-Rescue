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
        predictor = SimpleNamespace(predict=Mock(return_value={'legacy': True}))
        with patch('src.ai_service.text_inference.get_predictor', return_value=predictor):
            with patch.dict(os.environ, {'AI_TEXT_RULES_ENABLED': 'false'}):
                self.assertEqual(predict_text('A person is injured.'), {'legacy': True})
            with patch.dict(os.environ, {'AI_TEXT_RULES_ENABLED': 'true'}):
                result = predict_text('A person is injured while snowboarding.')
                self.assertEqual(result['severity'], 'serious')
                self.assertIsNone(result['confidence'])
                self.assertEqual(predict_text('Unknown message.'), {'legacy': True})

    def test_general_injury_words_do_not_downgrade_uncertain_critical_model_outputs(self):
        model = {'severity': None, 'predicted_label': 'critical', 'status': 'needs_review'}
        result = combine_assessments(model, analyze_cues('The driver is injured.'))
        self.assertIs(result, model)


if __name__ == '__main__':
    unittest.main()
