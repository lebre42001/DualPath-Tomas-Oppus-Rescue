"""The JSON runtime must reproduce training inference and fail on broken artifacts."""
import hashlib
import json
import tempfile
import unittest
from pathlib import Path

from src.ai_service.text_inference import TfidfSeverityPredictor, TextModelUnavailable
from src.ai_service.text_training import _baseline_predictor


class TfidfInferenceTests(unittest.TestCase):
    def setUp(self):
        self.directory = Path(__file__).resolve().parents[1] / 'releases/triage-text-tfidf-20261016'
        self.predictor = TfidfSeverityPredictor(self.directory, allow_experimental=True)

    def test_saved_runtime_matches_training_for_all_three_languages(self):
        baseline = _baseline_predictor(json.loads((self.directory / 'baseline.json').read_text()))
        descriptions = [
            'A motorcycle crashed. The driver is unconscious and is not breathing.',
            'Nabangga ang motor ug wala nay panimuot ang drayber.',
            'Nabangga ang motorsiklo at hindi humihinga ang drayber.',
            'The car has a small scratch; everyone is uninjured and safe.',
        ]
        for text, expected in zip(descriptions, baseline(descriptions), strict=True):
            actual = self.predictor.predict(text)
            self.assertEqual(actual['deployment_status'], 'experimental')
            self.assertTrue(actual['responder_review_required'])
            for label, probability in zip(self.predictor.metadata['labels'], expected, strict=True):
                self.assertAlmostEqual(actual['probabilities'][label], probability, places=5)

    def test_unknown_text_abstains_instead_of_using_class_intercept(self):
        result = self.predictor.predict('漢字漢字漢字')
        self.assertIsNone(result['severity'])
        self.assertEqual(result['status'], 'needs_review')

    def test_unapproved_release_remains_blocked_without_explicit_opt_in(self):
        with self.assertRaises(TextModelUnavailable):
            TfidfSeverityPredictor(self.directory)

    def test_checksum_and_numeric_shapes_are_validated(self):
        with tempfile.TemporaryDirectory() as temp:
            directory = Path(temp)
            metadata = dict(self.predictor.metadata)
            payload = json.loads((self.directory / 'baseline.json').read_text())
            payload['coefficients'] = [[0]]
            content = json.dumps(payload).encode()
            (directory / 'baseline.json').write_bytes(content)
            for update_checksum in (False, True):
                if update_checksum:
                    metadata['artifact_sha256'] = hashlib.sha256(content).hexdigest()
                (directory / 'metadata.json').write_text(json.dumps(metadata))
                with self.assertRaises(TextModelUnavailable):
                    TfidfSeverityPredictor(directory, allow_experimental=True)


if __name__ == '__main__':
    unittest.main()
