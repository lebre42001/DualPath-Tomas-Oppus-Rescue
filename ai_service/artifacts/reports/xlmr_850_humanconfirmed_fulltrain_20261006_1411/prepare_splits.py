import csv, json, hashlib, re, shutil, sys
from pathlib import Path
from collections import defaultdict, Counter
from datetime import datetime, timezone
ROOT=Path.cwd(); sys.path.insert(0, str(ROOT/'ai_service'))
from src.ai_service.text_training import load_dataset, _manifest
DATA=ROOT/'ai_service/datasets/emergency_text/reviewed/civilian_multilingual_850_humanconfirmed_20261006T141537+0800'
SRC=ROOT/'ai_service/datasets/emergency_text/experiments/civilian_multilingual_6000w_20261005'
REPORT=ROOT/'ai_service/artifacts/reports/xlmr_850_humanconfirmed_fulltrain_20261006_1411'
SPLIT_DIR=REPORT/'frozen_splits'; MODEL_DATA=REPORT/'model_data'
SPLIT_DIR.mkdir(exist_ok=True); MODEL_DATA.mkdir(exist_ok=True)
rows=load_dataset(DATA/'reports.csv')
source_prov={r['case_id']:r for r in csv.DictReader((DATA/'provenance.csv').open(encoding='utf-8-sig',newline=''))}
old=json.loads((SRC/'split_manifest.json').read_text(encoding='utf-8'))['partitions']
old_map={cid:p for p,v in old.items() for cid in v['case_ids']}
parent={r['case_id']:r['case_id'] for r in rows}
def find(x):
 while parent[x]!=x: parent[x]=parent[parent[x]]; x=parent[x]
 return x
def union(a,b):
 a,b=find(a),find(b)
 if a!=b: parent[max(a,b)]=min(a,b)
by_key=defaultdict(list)
for r in rows:
 p=source_prov[r['case_id']]
 for key,value in [('source_group_id',r['group_id']),('scenario_family_id',p.get('scenario_family_id')),('annotation_group_id',p.get('annotation_group_id')),('normalized_text',' '.join(re.findall(r'\w+',r['text'].casefold())))]:
  if value: by_key[(key,value)].append(r['case_id'])
 if p.get('parent_case_id'): union(r['case_id'],p['parent_case_id'])
for ids in by_key.values():
 for cid in ids[1:]: union(ids[0],cid)
family=json.loads((REPORT/'FAMILY_RECONCILIATION_REPORT.json').read_text(encoding='utf-8'))
for pair in family['pair_recommendations']:
 if pair['recommendation']=='SAME_SCENARIO_FAMILY': union(pair['case_a'],pair['case_b'])
components=defaultdict(list)
for r in rows: components[find(r['case_id'])].append(r)
assigned={}
for gid,items in components.items(): assigned[gid]=Counter(old_map[r['case_id']] for r in items).most_common(1)[0][0]
parts={name:[] for name in ('train','validation','calibration','fresh_test','challenge')}
for gid,items in components.items(): parts[assigned[gid]].extend(items)
for name in parts: parts[name].sort(key=lambda r:r['case_id'])
# Validate required label support, membership, family-component disjointness, exact text disjointness.
assert len(rows)==850 and all(len(v)>0 for v in parts.values())
assert set().union(*(set(r['case_id'] for r in v) for v in parts.values()))=={r['case_id'] for r in rows}
assert all(set(r['label'] for r in v)=={'minor','serious','critical','needs_review'} for v in parts.values())
component_of={cid:find(cid) for group in components.values() for cid in [r['case_id'] for r in group]}
for i,a in enumerate(parts):
 for b in list(parts)[i+1:]:
  assert not ({component_of[r['case_id']] for r in parts[a]} & {component_of[r['case_id']] for r in parts[b]})
  assert not ({' '.join(re.findall(r'\w+',r['text'].casefold())) for r in parts[a]} & {' '.join(re.findall(r'\w+',r['text'].casefold())) for r in parts[b]})
# Create a distinct training copy using conservative split group ids; source reviewed set remains byte-identical.
comp_hash={gid:'FS-'+hashlib.sha256('|'.join(sorted(r['case_id'] for r in items)).encode()).hexdigest()[:16] for gid,items in components.items()}
model_rows=[]
for p in ('train','validation','fresh_test'):
 for r in parts[p]:
  copy=dict(r); copy['group_id']=comp_hash[component_of[r['case_id']]]; model_rows.append(copy)
training_data=MODEL_DATA/'model_development.csv'
with training_data.open('w',encoding='utf-8-sig',newline='') as f:
 w=csv.DictWriter(f,fieldnames=list(model_rows[0].keys()),lineterminator='\n'); w.writeheader(); w.writerows(model_rows)
# CSVs for separate non-training reserve partitions, with original group IDs intact.
for p in ('calibration','challenge'):
 path=SPLIT_DIR/f'{p}.csv'
 with path.open('w',encoding='utf-8-sig',newline='') as f:
  w=csv.DictWriter(f,fieldnames=list(rows[0].keys()),lineterminator='\n'); w.writeheader(); w.writerows(parts[p])
for p in ('train','validation','fresh_test'):
 path=SPLIT_DIR/f'{p}.csv'
 with path.open('w',encoding='utf-8-sig',newline='') as f:
  w=csv.DictWriter(f,fieldnames=list(rows[0].keys()),lineterminator='\n'); w.writeheader(); w.writerows(parts[p])
manifest_all={}
for p,items in parts.items(): manifest_all[p]={'case_ids':[r['case_id'] for r in items], 'source_family_ids':sorted({source_prov[r['case_id']]['scenario_family_id'] for r in items}), 'effective_split_groups':sorted({comp_hash[component_of[r['case_id']]] for r in items}), 'summary':{'rows':len(items),'by_label':dict(Counter(r['label'] for r in items)),'by_language':dict(Counter(r['language'] for r in items))}}
all_manifest={'format':'five_partition_owner_attested_850_v1','created_at':datetime.now(timezone.utc).isoformat(),'source_reports_sha256':hashlib.sha256((DATA/'reports.csv').read_bytes()).hexdigest(),'source_provenance_sha256':hashlib.sha256((DATA/'provenance.csv').read_bytes()).hexdigest(),'family_reconciliation_sha256':hashlib.sha256((REPORT/'FAMILY_RECONCILIATION_REPORT.json').read_bytes()).hexdigest(),'method':'Existing 5-way family-stratified partition retained where possible; all source group_id, scenario_family_id, annotation_group_id, parent-child links, normalized-text duplicates and 19 AI-recommended SAME_SCENARIO_FAMILY edges were unioned as indivisible effective split groups. Original scenario/family IDs are unchanged. Components crossing old partitions were assigned by component plurality (3 components; 4 rows moved).','prior_exposure_limitation':'All rows were previously consumed across old model train/validation/calibration/fresh_test/challenge workflows; these new-run holdouts are not independent of historical XLM-R/TF-IDF/model-development exposure.','partition_assignments':manifest_all,'component_count':len(components),'component_count_merged_from_recommendations':19,'components_crossing_old_partitions':sum(len({old_map[r['case_id']] for r in group})>1 for group in components.values()),'rows_moved_from_old_assignment':sum(old_map[r['case_id']]!=assigned[find(r['case_id'])] for r in rows),'source_dataset_unchanged':True,'training_rows_excluded_from_calibration_challenge':True}
(REPORT/'SPLIT_MANIFEST.json').write_text(json.dumps(all_manifest,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
train_manifest=_manifest({'train':[{**r,'group_id':comp_hash[component_of[r['case_id']]]} for r in parts['train']]}); val_manifest=_manifest({'validation':[{**r,'group_id':comp_hash[component_of[r['case_id']]]} for r in parts['validation']]}); test_manifest=_manifest({'test':[{**r,'group_id':comp_hash[component_of[r['case_id']]]} for r in parts['fresh_test']]})
training_split={'train':train_manifest['train'],'validation':val_manifest['validation'],'test':test_manifest['test']}
(REPORT/'training_split_manifest.json').write_text(json.dumps(training_split,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
# Identify internal test IDs: ensure no historical fresh-test use is confused with fresh-in-new-run.
print(json.dumps({'partition_rows':{p:len(v) for p,v in parts.items()},'partition_label_language_counts':{p:{'labels':dict(Counter(r['label'] for r in v)),'languages':dict(Counter(r['language'] for r in v))} for p,v in parts.items()},'effective_components':len(components),'old_partition_crossing_components':all_manifest['components_crossing_old_partitions'],'moved_rows':all_manifest['rows_moved_from_old_assignment'],'training_data_rows':len(model_rows),'training_data_sha256':hashlib.sha256(training_data.read_bytes()).hexdigest(),'training_split_manifest_sha256':hashlib.sha256((REPORT/'training_split_manifest.json').read_bytes()).hexdigest()},ensure_ascii=False,indent=2))

