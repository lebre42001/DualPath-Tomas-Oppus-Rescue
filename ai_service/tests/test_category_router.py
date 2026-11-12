"""Category selection must never use another incident's trained classifier."""
import hashlib
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import Mock

from src.ai_service.text_inference import (CategoryTfidfSeverityPredictor, TEXT_LABELS,
                                          TextModelUnavailable, load_predictor)


def write_json(path, value):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes((json.dumps(value) + '\n').encode('utf-8'))


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


class CategoryRouterTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        bindings = {}
        self.meta = {'schema_version': 1, 'labels': list(TEXT_LABELS), 'version': 'router-fixture',
                     'model_type': 'tfidf_category_router', 'input_format': 'incident_type_description_v1',
                     'supported_incident_types': ['road_accident', 'fire_incident'],
                     'display_name': 'Fixture category router', 'review_threshold': .5, 'max_length': 128,
                     'review_threshold_status': 'provisional', 'approved_for_deployment': False,
                     'all_data_reviewed': False, 'text_rule_profile': 'multiincident_common_v1',
                     'text_rule_incident_types': ['road_accident', 'fire_incident']}
        for category, label in [('road_accident', 'serious'), ('fire_incident', 'critical')]:
            child = self.root / 'experts' / category
            write_json(child / 'baseline.json', {'schema_version': 1, 'labels': list(TEXT_LABELS),
                       'vocabulary': {'abc': 0}, 'idf': [1], 'coefficients': [[0], [0], [0], [0]],
                       'intercept': [8 if value == label else 0 for value in TEXT_LABELS]})
            metadata = {**self.meta, 'model_type': 'tfidf_logistic', 'version': 'fixture-' + category,
                        'supported_incident_types': [category], 'text_rule_incident_types': [category],
                        'artifact_sha256': sha(child / 'baseline.json')}
            write_json(child / 'metadata.json', metadata)
            bindings[category] = {'directory': 'experts/' + category,
                                  'metadata_sha256': sha(child / 'metadata.json'),
                                  'artifact_sha256': metadata['artifact_sha256']}
        write_json(self.root / 'category_models.json', {'schema_version': 1, 'models': bindings})
        self.meta['artifact_sha256'] = sha(self.root / 'category_models.json')
        write_json(self.root / 'metadata.json', self.meta)

    def change_manifest(self, change):
        path = self.root / 'category_models.json'
        manifest = json.loads(path.read_text())
        change(manifest['models'])
        write_json(path, manifest)
        self.meta['artifact_sha256'] = sha(path)
        write_json(self.root / 'metadata.json', self.meta)

    def test_identical_wording_uses_only_the_selected_expert(self):
        router = load_predictor(self.root, allow_experimental=True)
        for expert in router.experts.values():
            expert.predict = Mock(wraps=expert.predict)
        result = router.predict('abc', 'fire')
        self.assertEqual(result['severity'], 'critical')
        self.assertEqual(result['category_expert'], 'fire_incident')
        self.assertEqual(result['model_version'], 'router-fixture')
        self.assertTrue(result['responder_review_required'])
        router.experts['fire_incident'].predict.assert_called_once_with('abc', 'fire_incident')
        router.experts['road_accident'].predict.assert_not_called()
        self.assertEqual(router.predict('abc', 'road_accident')['severity'], 'serious')
        self.assertEqual(router.predict('abc', 'medical_emergency')['reason'], 'unsupported_incident_type')
        with self.assertRaises(ValueError):
            router.predict('abc', 'unknown')

    def test_experimental_opt_in_is_required(self):
        with self.assertRaises(TextModelUnavailable):
            load_predictor(self.root)

    def test_missing_expert_is_not_replaced_by_another_category(self):
        self.change_manifest(lambda models: models.pop('fire_incident'))
        with self.assertRaises(TextModelUnavailable):
            load_predictor(self.root, allow_experimental=True)

    def test_escape_path_is_rejected_even_with_a_new_manifest_hash(self):
        self.change_manifest(lambda models: models['fire_incident'].update(directory='../outside'))
        with self.assertRaises(TextModelUnavailable):
            load_predictor(self.root, allow_experimental=True)

    def test_different_category_metadata_is_rejected(self):
        self.change_manifest(lambda models: models.__setitem__('fire_incident', dict(models['road_accident'])))
        with self.assertRaises(TextModelUnavailable):
            load_predictor(self.root, allow_experimental=True)

    def test_model_tampering_is_rejected(self):
        (self.root / 'experts/fire_incident/baseline.json').write_text('{}')
        with self.assertRaises(TextModelUnavailable):
            load_predictor(self.root, allow_experimental=True)

    def test_metadata_change_after_loading_stops_selected_inference(self):
        router = CategoryTfidfSeverityPredictor(self.root, allow_experimental=True)
        (self.root / 'experts/fire_incident/metadata.json').write_text('{}')
        with self.assertRaises(TextModelUnavailable):
            router.predict('abc', 'fire_incident')
        self.assertEqual(router.predict('abc', 'road_accident')['severity'], 'serious')

    def test_parent_approval_does_not_upgrade_experimental_experts(self):
        self.meta.update({'approved_for_deployment': True, 'all_data_reviewed': True,
                          'review_threshold_status': 'validated',
                          'release_review': {'reviewer': 'test-fixture', 'approved_at': 'test', 'validation_report': 'test'}})
        write_json(self.root / 'metadata.json', self.meta)
        with self.assertRaises(TextModelUnavailable):
            load_predictor(self.root, allow_experimental=True)

    def test_packaged_six_category_release_calls_only_one_expert(self):
        directory = Path(__file__).resolve().parents[1] / 'releases/category-experts-20261112-v1'
        router = load_predictor(directory, allow_experimental=True)
        self.assertEqual(len(router.experts), 6)
        for expert in router.experts.values():
            expert.predict = Mock(wraps=expert.predict)
        result = router.predict('Fictional fire report with unknown details.', 'fire_incident')
        self.assertEqual(result['category_expert'], 'fire_incident')
        for category, expert in router.experts.items():
            self.assertEqual(expert.predict.call_count, 1 if category == 'fire_incident' else 0)


if __name__ == '__main__':
    unittest.main()
