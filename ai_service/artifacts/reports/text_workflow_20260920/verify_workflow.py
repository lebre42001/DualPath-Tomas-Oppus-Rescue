"""Read-only final integrity/gate checks, writing only this workflow's receipt."""
import csv
import hashlib
import json
from pathlib import Path
import sys
from types import SimpleNamespace

ROOT = Path(__file__).resolve().parents[3]
REPOSITORY = ROOT.parent
REPORT = Path(__file__).resolve().parent
sys.path.insert(0, str(ROOT))
from src.ai_service.text_training import dataset_fingerprint, experiment_splits, load_dataset, select_rows
from src.ai_service.text_inference import TextModelUnavailable, read_metadata
from src.ai_service.text_review import audit

def read(path):
    return json.loads(path.read_text(encoding='utf-8-sig'))

def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()

migration = read(REPORT/'migration.json')
locations = {item['old'].replace('\\','/'):item['new'].replace('\\','/') for item in migration['moved_files']}
csv_records = [item for item in migration['moved_files'] if item['new'].endswith('.csv')]
assert all(sha(REPOSITORY/item['new']) == item['sha256'] for item in csv_records)
preflight = read(ROOT/'artifacts/reports/text_synthetic_9000words_20260920/preflight.json')
protected = {}
for relative, expected in preflight['protected_file_hashes'].items():
    normal = relative.replace('\\','/')
    protected[relative] = sha(REPOSITORY/locations.get(normal, normal)) == expected
assert all(protected.values())

data = ROOT/'datasets/emergency_text'
original = load_dataset(data/'experiments/synthetic_9000words_20260920/reports.csv')
working = load_dataset(data/'working/reports.csv')
by_id = {row['case_id']:row for row in working}
assert all(by_id[row['case_id']] == row for row in original)
assert len(working) == 400 and all(row['review_status'] == 'draft' and not row['reviewer'] for row in working)
checks = audit(working)
assert checks['duplicate_normalized_text_count'] == 0 and not checks['over_1000_character_case_ids']
try:
    select_rows(working)
except ValueError as error:
    assert 'No approved' in str(error)
else:
    raise AssertionError('Unreviewed working data passed the normal training gate')
with (data/'working/review_worksheet.csv').open(encoding='utf-8-sig', newline='') as handle:
    worksheet = list(csv.DictReader(handle))
assert len(worksheet) == 400
assert all(not row['decision'] and not row['reviewer'] and row['source_fingerprint'] == dataset_fingerprint(working) for row in worksheet)

candidate = ROOT/'artifacts/checkpoints/text_weighted_full_20260920'
metadata = read(candidate/'metadata.json')
history = read(candidate/'training_history.json')
assert metadata['backbone_frozen'] is False and metadata['trainable_parameters'] == 177856516
assert metadata['loss_normalization'] == 'weighted_sum_over_accumulation_window_weight_sum'
assert metadata['selection_metric'] == 'critical_recall_then_macro_f1'
assert metadata['epochs_completed'] == len(history['epochs']) == 3
assert not metadata['test_evaluated_during_training'] and not (candidate/'test_metrics.json').exists()
assert not metadata['approved_for_deployment'] and not metadata['all_data_reviewed']
assert metadata['dataset_fingerprint'] == dataset_fingerprint(original) == preflight['dataset_fingerprint']
old_manifest = ROOT/'artifacts/checkpoints/text_synthetic_9000_mbert_full_20260920/split_manifest.json'
assert read(candidate/'split_manifest.json') == read(old_manifest)
experiment_splits(original, SimpleNamespace(split_manifest=old_manifest))
try:
    read_metadata(candidate, allow_experimental=False)
except TextModelUnavailable:
    pass
else:
    raise AssertionError('Experimental candidate passed the release gate')

receipt = {'all_checks_passed':True, 'preserved_csv_files':len(csv_records),
           'protected_files_unchanged':protected, 'original_292_rows_preserved':True,
           'working_rows':len(working), 'working_groups':checks['groups'], 'approved_rows':0,
           'worksheet_pending_decisions':len(worksheet), 'normal_training_gate_blocks':True,
           'full_backbone_training_verified':True, 'epochs_completed':metadata['epochs_completed'],
           'frozen_splits_match_original':True, 'test_not_evaluated_during_training':True,
           'normal_release_gate_blocks':True, 'approved_for_deployment':False}
(REPORT/'integrity_verification.json').write_text(json.dumps(receipt,indent=2)+'\n',encoding='utf-8')
print(json.dumps(receipt,indent=2))
