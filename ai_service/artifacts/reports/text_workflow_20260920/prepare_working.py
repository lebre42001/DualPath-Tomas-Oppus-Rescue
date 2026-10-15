"""One-time expansion of the untouched working copy; no approvals or new incidents claimed."""
import csv
import hashlib
import json
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT))
from src.ai_service.text_training import COLUMNS, dataset_summary, load_dataset, normalized_text
from src.ai_service.text_synthetic_pack import read_scenarios, read_group_map
from src.ai_service.text_review import write_csv

data = ROOT / 'datasets/emergency_text'
working = data / 'working/reports.csv'
original = data / 'experiments/synthetic_9000words_20260920/reports.csv'
source_bytes = working.read_bytes()
if source_bytes != original.read_bytes():
    raise ValueError('Working copy changed; preserve reviewer edits. This one-time initializer will not overwrite it.')
rows = {row['case_id']: row for row in load_dataset(working)}
source_paths = sorted((data / 'generation').glob('road_scenarios_*.json'))
scenarios = read_scenarios(source_paths)
group_path = data / 'generation/scenario_groups.json'
aliases = read_group_map(group_path, scenarios)
by_id = {item['id']: item for item in scenarios}
for item in scenarios:
    for language in ('en', 'tl', 'ceb'):
        identifier = f"SYN_{item['id'].upper()}_{language.upper()}"
        record = dict(case_id=identifier, group_id=f"synthetic_{aliases.get(item['id'], item['id'])}",
                      language=language, text=item[language], label=item['label'], review_status='draft', reviewer='',
                      notes='AI-generated fictional road-report example; provisional label, no human approval. ' + item['rationale'])
        if identifier in rows and rows[identifier] != record:
            raise ValueError(f'Existing scenario differs: {identifier}')
        rows.setdefault(identifier, record)
mixed_path = data / 'generation/mixed_review_examples.json'
for item in json.loads(mixed_path.read_text(encoding='utf-8')):
    parent = by_id[item['scenario_id']]
    identifier = f"SYN_{parent['id'].upper()}_MIXED"
    if identifier in rows or len(item['text']) > 1000:
        raise ValueError('Duplicate or overlength mixed draft')
    rows[identifier] = dict(case_id=identifier, group_id=f"synthetic_{aliases.get(parent['id'], parent['id'])}",
                           language='mixed', text=item['text'], label=parent['label'], review_status='draft', reviewer='',
                           notes='AI-authored code-switching draft of existing scenario ' + parent['id'] +
                           '; not an independent incident. Requires fluent-speaker and responder review. ' + parent['rationale'])
if len({normalized_text(row['text']) for row in rows.values()}) != len(rows):
    raise ValueError('Duplicate normalized text')
staged = working.with_name('reports.initializing.csv')
write_csv(staged, sorted(rows.values(), key=lambda row: row['case_id']))
checked = load_dataset(staged)
if working.read_bytes() != source_bytes:
    raise ValueError('Working master changed during initialization; staged file retained for inspection')
staged.replace(working)
receipt = {**dataset_summary(checked), 'inherited_rows':292, 'added_seed_translations':68, 'added_mixed_variants':40,
           'newly_authored_incidents':0, 'additional_preexisting_seed_families':6, 'source_type':'synthetic draft',
           'sources':{str(path.relative_to(ROOT)):hashlib.sha256(path.read_bytes()).hexdigest()
                      for path in [original, *source_paths, group_path, mixed_path]},
           'release_holdout':'Not fresh: related scenarios were used in earlier experiments',
           'reviewed_target':1200, 'target_is_planning_only':True}
(working.parent / 'provenance.json').write_text(json.dumps(receipt, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
print(json.dumps(dataset_summary(checked), indent=2))
