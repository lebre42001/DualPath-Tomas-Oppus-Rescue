"""Read-only audit of family containment and accessible historical CSV evidence."""
from __future__ import annotations
import csv, hashlib, json, os, sys, zipfile, tarfile
from collections import Counter, defaultdict
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[5]  # repository root
OUT = Path(__file__).resolve().parent
AI = ROOT / "ai_service"
DATA = AI / "datasets/emergency_text"
EXP = DATA / "experiments/civilian_multilingual_6000w_20261005"
REVIEW = DATA / "reviewed/civilian_multilingual_850_humanconfirmed_20261006T141537+0800"
OLD_REPORT = AI / "artifacts/reports/civilian_multilingual_fulltrain_20261005"
REPORT = AI / "artifacts/reports/xlmr_850_humanconfirmed_fulltrain_20261006_1411"
ACCESS_REPORT = AI / "artifacts/reports/reviewed_xlmr_next_stage_20261006/PRIOR_SOURCE_ACCESS_AUDIT.json"

def sha(path: Path) -> str:
    h=hashlib.sha256()
    with path.open('rb') as f:
        for b in iter(lambda:f.read(1024*1024),b''): h.update(b)
    return h.hexdigest()

def read_csv(path: Path):
    with path.open('r',encoding='utf-8-sig',newline='') as f:
        return list(csv.DictReader(f))

def csv_meta(path: Path):
    entry={'path':str(path.relative_to(ROOT)).replace('\\','/')}
    try:
        st=path.stat()
        entry.update({'exists':True,'size_bytes':st.st_size,'mtime_ns':st.st_mtime_ns})
    except Exception as e:
        entry.update({'exists':None,'stat_error':type(e).__name__+': '+str(e)})
    try:
        entry['sha256']=sha(path)
        rows=read_csv(path)
        with path.open('r',encoding='utf-8-sig',newline='') as f:
            header=next(csv.reader(f),[])
        entry.update({'readable':True,'encoding':'utf-8-sig','columns':header,'parsed_data_rows':len(rows),
                      'nonblank_text_rows':sum(bool((r.get('text') or '').strip()) for r in rows) if 'text' in header else None})
    except Exception as e:
        entry.update({'readable':False,'read_error':type(e).__name__+': '+str(e)})
    return entry

def part_map(partitions):
    out={}
    for pname,payload in partitions.items():
        ids=payload.get('case_ids',[]) if isinstance(payload,dict) else payload
        for cid in ids or []: out[str(cid)]=pname
    return out

def partition_integrity(partitions, source_ids):
    occurrences=Counter()
    counts={}
    for pname,payload in partitions.items():
        ids=payload.get('case_ids',[]) if isinstance(payload,dict) else payload
        counts[pname]=len(ids or [])
        occurrences.update(str(x) for x in (ids or []))
    duplicates=sorted(k for k,v in occurrences.items() if v>1)
    return {'partition_counts':counts,'partition_entry_count':sum(counts.values()),
            'unique_assigned_ids':len(occurrences),'duplicate_assigned_ids':duplicates,
            'missing_source_ids':sorted(set(source_ids)-set(occurrences)),
            'unknown_assigned_ids':sorted(set(occurrences)-set(source_ids)),
            'pass':not duplicates and set(occurrences)==set(source_ids)}

class UF:
    def __init__(self,vals): self.p={v:v for v in vals}
    def find(self,x):
        while self.p[x]!=x:
            self.p[x]=self.p[self.p[x]]; x=self.p[x]
        return x
    def union(self,a,b):
        a,b=self.find(a),self.find(b)
        if a!=b:self.p[b]=a

def full_effective_group_check(rows, assignments, pairs):
    """Reconstruct every grouping edge named by the frozen split method."""
    import unicodedata
    uf=UF(r['case_id'] for r in rows)
    def union_equal(key):
        first={}
        for row in rows:
            value=key(row)
            if value in (None,''): continue
            if value in first: uf.union(row['case_id'],first[value])
            else:first[value]=row['case_id']
    union_equal(lambda r:r.get('group_id'))
    union_equal(lambda r:r.get('scenario_family_id'))
    union_equal(lambda r:r.get('annotation_group_id'))
    union_equal(lambda r:' '.join(unicodedata.normalize('NFKC',r['text']).casefold().split()))
    idset={r['case_id'] for r in rows}
    for row in rows:
        parent=row.get('parent_case_id','')
        if parent and parent in idset: uf.union(row['case_id'],parent)
    for pair in pairs:
        if pair['recommendation']=='SAME_SCENARIO_FAMILY': uf.union(pair['case_a'],pair['case_b'])
    groups=defaultdict(list)
    for row in rows: groups[uf.find(row['case_id'])].append(row['case_id'])
    crossings=[]
    for group,cids in groups.items():
        parts=sorted({assignments[cid] for cid in cids if cid in assignments})
        if len(parts)>1: crossings.append({'component':group,'partition_names':parts,'case_ids':sorted(cids)})
    return {'effective_component_count':len(groups),'cross_partition_component_count':len(crossings),
            'cross_partition_components':crossings,'assignment_case_count':len(assignments)}

def group_edges_audit(name, assignments, rows, pairs):
    byid={r['case_id']:r for r in rows}
    allfamilies={r['scenario_family_id'] for r in rows}
    uf=UF(allfamilies)
    same=[p for p in pairs if p['recommendation']=='SAME_SCENARIO_FAMILY']
    for p in same:
        uf.union(byid[p['case_a']]['scenario_family_id'],byid[p['case_b']]['scenario_family_id'])
    members=defaultdict(list)
    for r in rows: members[uf.find(r['scenario_family_id'])].append(r['case_id'])
    comp_cross=[]
    for representative,ids in members.items():
        parts=sorted({assignments[i] for i in ids if i in assignments})
        if len(parts)>1: comp_cross.append({'effective_component':representative,'partition_names':parts,'case_count':len(ids)})
    declared_members=defaultdict(list)
    for r in rows: declared_members[r['scenario_family_id']].append(r['case_id'])
    declared_cross=[]
    for family,ids in declared_members.items():
        parts=sorted({assignments[i] for i in ids if i in assignments})
        if len(parts)>1: declared_cross.append({'scenario_family_id':family,'partition_names':parts,'case_count':len(ids)})
    edge_rows=[]
    for p in pairs:
        ca,cb=p['case_a'],p['case_b']; pa,pb=assignments.get(ca),assignments.get(cb)
        edge_rows.append({'split':name,'pair_id':p['pair_id'],'case_a':ca,'case_b':cb,
                          'recommendation':p['recommendation'],'family_a':byid[ca]['scenario_family_id'],
                          'family_b':byid[cb]['scenario_family_id'],'partition_a':pa,'partition_b':pb,
                          'cross_partition':bool(pa and pb and pa!=pb)})
    return {'partitioned_case_ids':len(assignments),'declared_family_count':len(allfamilies),
            'declared_families_crossing_partitions':len(declared_cross),'declared_family_crossing_examples':declared_cross[:25],
            'same_scenario_pairs':len(same),'effective_family_components_after_same_edges':len(members),
            'effective_components_crossing_partitions':len(comp_cross),'crossing_component_examples':comp_cross[:25],
            'all_41_candidate_pairs_cross_partition':sum(x['cross_partition'] for x in edge_rows),
            'same_scenario_pairs_cross_partition':sum(x['cross_partition'] for x in edge_rows if x['recommendation']=='SAME_SCENARIO_FAMILY'),
            'related_but_distinct_pairs_cross_partition':sum(x['cross_partition'] for x in edge_rows if x['recommendation']=='RELATED_BUT_DISTINCT'),
            'pair_records':edge_rows}

def main():
    src_reports=EXP/'reports.csv'; src_prov=EXP/'provenance.csv'; src_family=EXP/'family_manifest.json'
    report_family=REPORT/'FINAL_FAMILY_MANIFEST.json'; pair_report=REPORT/'FAMILY_RECONCILIATION_REPORT.json'
    current_split=REPORT/'SPLIT_MANIFEST.json'; old_split=EXP/'split_manifest.json'
    if not all(p.is_file() for p in [src_reports,src_prov,src_family,report_family,pair_report,current_split,old_split]):
        raise SystemExit('Missing required evidence file; aborting without partial summary.')
    rows=read_csv(src_reports); report_only_rows=[dict(r) for r in rows]; prov=read_csv(src_prov)
    provenance_by_id={r['case_id']:r for r in prov}
    if len(provenance_by_id)!=len(prov) or set(r['case_id'] for r in rows)!=set(provenance_by_id):
        raise SystemExit('Source reports/provenance case IDs are not one-to-one; stop for investigation.')
    # reports.csv carries group_id; provenance.csv is the source of scenario_family_id and parent metadata.
    for row in rows: row.update(provenance_by_id[row['case_id']])
    orig=json.loads(src_family.read_text(encoding='utf-8'))
    rec=json.loads(pair_report.read_text(encoding='utf-8'))
    fm=json.loads(report_family.read_text(encoding='utf-8'))
    cur=json.loads(current_split.read_text(encoding='utf-8'))
    old=json.loads(old_split.read_text(encoding='utf-8'))
    pairs=rec['pair_recommendations']
    cur_assign=part_map(cur['partition_assignments'])
    old_assign=part_map(old['partitions'])
    current=group_edges_audit('current_20261006',cur_assign,rows,pairs)
    historical=group_edges_audit('historical_20261005',old_assign,rows,pairs)
    full_current=full_effective_group_check(rows,cur_assign,pairs)
    full_historical=full_effective_group_check(rows,old_assign,pairs)
    # Reconstruct exact source builder scan; its executable definition recursively reads emergency_text CSVs,
    # excluding only its staging and output subtrees.
    excluded=[(AI/'artifacts/reports/civilian_multilingual_fulltrain_20261005_staging').resolve(),EXP.resolve()]
    current_inventory=[]; current_scan_records=0; current_unique_norm_text=set()
    import re, unicodedata
    def norm(s):
        s=unicodedata.normalize('NFKC',s).casefold()
        return ' '.join(s.split())
    for p in sorted(DATA.rglob('*.csv')):
        if any(x in p.resolve().parents for x in excluded): continue
        meta=csv_meta(p); current_inventory.append(meta)
        if not meta.get('readable') or 'text' not in meta.get('columns',[]): continue
        try:
            rs=read_csv(p)
            text_rows=[(r.get('text') or '').strip() for r in rs if (r.get('text') or '').strip()]
            current_scan_records += len(text_rows)
            for text in text_rows:
                key=' '.join(re.sub(r'[^\w\s]',' ',norm(text)).split())
                current_unique_norm_text.add(key)
            meta['builder_equivalent_text_records']=len(text_rows)
        except Exception: pass
    # The 60-path list comes from a frozen prior report. Probe path metadata/readability now without querying ACLs.
    access=json.loads(ACCESS_REPORT.read_text(encoding='utf-8'))
    builder_script=AI/'tools/build_civilian_multilingual_experiment.py'
    prior_dup_path=OLD_REPORT/'DUPLICATE_AUDIT.json'
    prior_leak_path=OLD_REPORT/'LEAKAGE_AUDIT.json'
    oldpaths=access['verified']['paths']
    inaccessible=[]
    for recpath in oldpaths:
        p=ROOT/recpath['path'].replace('\\','/')
        inaccessible.append(csv_meta(p))
    # Existing 1732 source report and 2708 independent inventory are compared by count and surviving artifacts.
    dup=json.loads((OLD_REPORT/'DUPLICATE_AUDIT.json').read_text(encoding='utf-8'))
    leak=json.loads((OLD_REPORT/'LEAKAGE_AUDIT.json').read_text(encoding='utf-8'))
    # Probe archive containers by name only; no extraction. CSV contents remain as-is.
    archive_inventory=[]
    for ext in ('*.zip','*.tar','*.tar.gz','*.tgz'):
        for p in sorted(AI.rglob(ext)):
            if p.is_file():
                e={'path':str(p.relative_to(ROOT)).replace('\\','/'),'size_bytes':p.stat().st_size}
                try:
                    if zipfile.is_zipfile(p):
                        with zipfile.ZipFile(p) as z:e['members_matching_restricted_csv']=[n for n in z.namelist() if n.lower().endswith('.csv') and ('by_severity' in n.lower() or 'examples.csv' in n.lower())]
                        e['container_type']='zip'
                    elif tarfile.is_tarfile(p):
                        with tarfile.open(p,'r:*') as t:e['members_matching_restricted_csv']=[m.name for m in t.getmembers() if m.name.lower().endswith('.csv') and ('by_severity' in m.name.lower() or 'examples.csv' in m.name.lower())]
                        e['container_type']='tar'
                    else:e['container_type']='other'
                except Exception as ex:e['inspection_error']=type(ex).__name__+': '+str(ex)
                archive_inventory.append(e)
    # Exact family counts and check family manifest coverage vs reports/provenance.
    row_ids=[r.get('case_id','') for r in rows]
    prov_ids=[r.get('case_id','') for r in prov]
    declared={r.get('scenario_family_id','') for r in rows}
    family_membership={fid:[] for fid in declared}
    for r in rows: family_membership.setdefault(r['scenario_family_id'],[]).append(r['case_id'])
    original_manifest_coverage=(set(orig)==declared and all(sorted(v.get('case_ids',[]))==sorted(family_membership.get(k,[])) for k,v in orig.items()))
    manifest_fams=fm.get('families',{})
    manifest_coverage=all(sorted(v['case_ids'])==sorted(family_membership.get(k,[])) for k,v in manifest_fams.items()) and set(manifest_fams)==declared
    # CSVs with same hash collapse to identity clusters; repeated row counts stay separate from unique text counts.
    hash_groups=defaultdict(list)
    for ent in current_inventory:
        if ent.get('sha256'):hash_groups[ent['sha256']].append(ent['path'])
    duplicate_file_groups=[{'sha256':h,'paths':paths,'path_count':len(paths)} for h,paths in hash_groups.items() if len(paths)>1]
    pairs_csv=[]
    for s in [historical,current]: pairs_csv.extend(s['pair_records'])
    # CSV output utilities
    def write_csv_out(name,records,fields):
        with (OUT/name).open('w',encoding='utf-8',newline='') as f:
            w=csv.DictWriter(f,fieldnames=fields,extrasaction='ignore',lineterminator='\n'); w.writeheader(); w.writerows(records)
    write_csv_out('ACCESSIBLE_CSV_INVENTORY.csv',current_inventory,
      ['path','exists','size_bytes','mtime_ns','sha256','readable','read_error','encoding','columns','parsed_data_rows','nonblank_text_rows','builder_equivalent_text_records'])
    write_csv_out('RECORDED_60_FILE_ACCESS_PROBES.csv',inaccessible,
      ['path','exists','size_bytes','mtime_ns','sha256','readable','read_error','encoding','columns','parsed_data_rows','nonblank_text_rows'])
    write_csv_out('FAMILY_PAIR_SPLIT_CROSSWALK.csv',pairs_csv,
      ['split','pair_id','case_a','case_b','recommendation','family_a','family_b','partition_a','partition_b','cross_partition'])
    out={
      'audit_id':'targeted-family-exposure-audit-20261006T154647+0800-v1',
      'generated_at_utc':datetime.now(timezone.utc).isoformat(),
      'scope':'Read-only verification of declared/effective family counts, 41 recorded candidate pair recommendations, historical and current split containment, historical CSV readability and inventory count discrepancy.',
      'commands_and_methods':{
        'python':'C:\\laragon\\bin\\python\\python-3.13\\python.exe FAMILY_EXPOSURE_AUDIT/audit_family_exposure.py',
        'source_code_inventory':'ai_service/tools/build_civilian_multilingual_experiment.py: prior_text_inventory; exact traversal rglob("*.csv"), skip only staging path and civilian_multilingual_6000w_20261005 output path; UTF-8-sig DictReader; count every nonblank text row, normalized punctuation-stripped exact text keys deduplicated only in lookup map.',
        'reconciliation':'Read-only CSV/JSON parsing; source case_id/scenario_family_id mapping; union-find merges only the 19 SAME_SCENARIO_FAMILY suggestions; pair/split crosswalk written as CSV.',
        'access':'For 60 historically recorded restricted CSV paths, check stat/hash/open/read metadata; run read-only icacls on one representative CSV per tree; no Get-Acl, elevation, ACL change, ownership change, or extraction. Prior permission errors recorded separately from current probe.',
        'archive':'Enumerate zip/tar/tgz under ai_service and inspect member names only for by_severity/examples.csv paths; no extraction or archive member reads.'},
      'source_inputs':{
        'reports_csv':{'path':str(src_reports.relative_to(ROOT)).replace('\\','/'),'sha256':sha(src_reports),'rows':len(rows)},
        'provenance_csv':{'path':str(src_prov.relative_to(ROOT)).replace('\\','/'),'sha256':sha(src_prov),'rows':len(prov)},
        'family_manifest':{'path':str(src_family.relative_to(ROOT)).replace('\\','/'),'sha256':sha(src_family),'declared_families_in_manifest':len(orig)},
        'reconciliation_report':{'path':str(pair_report.relative_to(ROOT)).replace('\\','/'),'sha256':sha(pair_report)},
        'effective_family_manifest':{'path':str(report_family.relative_to(ROOT)).replace('\\','/'),'sha256':sha(report_family)},
        'historical_split':{'path':str(old_split.relative_to(ROOT)).replace('\\','/'),'sha256':sha(old_split)},
        'current_split':{'path':str(current_split.relative_to(ROOT)).replace('\\','/'),'sha256':sha(current_split)},
        'historical_access_report':{'path':str(ACCESS_REPORT.relative_to(ROOT)).replace('\\','/'),'sha256':sha(ACCESS_REPORT)},
        'historical_duplicate_count_report':{'path':str(prior_dup_path.relative_to(ROOT)).replace('\\','/'),'sha256':sha(prior_dup_path)},
        'historical_2708_count_report':{'path':str(prior_leak_path.relative_to(ROOT)).replace('\\','/'),'sha256':sha(prior_leak_path)},
        'builder_source_code':{'path':str(builder_script.relative_to(ROOT)).replace('\\','/'),'sha256':sha(builder_script)}} ,
      'family_verification':{
        'source_report_rows':len(rows),'unique_case_ids':len(set(row_ids)),'provenance_rows':len(prov),'unique_provenance_case_ids':len(set(prov_ids)),
        'report_and_provenance_id_sets_equal':set(row_ids)==set(prov_ids),'declared_family_ids':len(declared),'manifest_declared_family_ids':len(manifest_fams),
        'source_family_manifest_memberships_match_reports':original_manifest_coverage,
        'family_manifest_memberships_match_reports':manifest_coverage,
        'reports_provenance_content_alignment':{field:sum(r[field]==provenance_by_id[r['case_id']].get(field,'') for r in report_only_rows) for field in ('language','text','label')},
        'reports_group_id_matches_provenance_family_id':sum(r.get('group_id')==provenance_by_id[r['case_id']].get('scenario_family_id') for r in rows),
        'split_manifest_component_count':cur.get('component_count'),'reconstructed_current_effective_groups':full_current,
        'reconstructed_historical_effective_groups':full_historical,
        'reconciliation_candidate_pairs':len(pairs),'pair_recommendations':dict(Counter(p['recommendation'] for p in pairs)),
        'current_split':{k:v for k,v in current.items() if k!='pair_records'},
        'historical_split':{k:v for k,v in historical.items() if k!='pair_records'},
        'frozen_split_manifest_summary':{'component_count':cur.get('component_count'),'component_count_merged_from_recommendations':cur.get('component_count_merged_from_recommendations'),'components_crossing_old_partitions':cur.get('components_crossing_old_partitions'),'rows_moved_from_old_assignment':cur.get('rows_moved_from_old_assignment'),'source_dataset_unchanged':cur.get('source_dataset_unchanged'),'reconstructed_effective_group_count_matches_manifest':full_current['effective_component_count']==cur.get('component_count')},
        'edge_semantics':'Current source scenario_family_id and source case_ids are unchanged. Effective components are computed only by unioning candidate family IDs in the 19 SAME_SCENARIO_FAMILY recommendations. These are machine-aided split recommendations, not human-confirmed incident identity.'},
      'historical_prior_exposure':{
        'old_assignment_integrity':partition_integrity(old['partitions'],row_ids),
        'current_assignment_integrity':partition_integrity(cur['partition_assignments'],row_ids),
        'source_case_ids_with_prior_partition':sum(cid in old_assign for cid in row_ids),
        'source_case_ids_with_current_partition':sum(cid in cur_assign for cid in row_ids),
        'same_case_corpus_prior_exposure':set(row_ids)<=set(old_assign),
        'interpretation':'All rows in this 850-case candidate corpus had an assignment in the 2026-10-05 historical split (including its old fresh_test/challenge); therefore the new split is not historical unseen evidence. This says nothing definitive about inaccessible legacy CSV content.'},
      'readable_inventory_reproduction':{
        'scope':'Current exact reproduction of the original builder’s scan rule, not a claim that this current tree equals the 2026-10-05 tree state.',
        'current_csv_paths_considered':len(current_inventory),'current_readable_csv_paths':sum(bool(x.get('readable')) for x in current_inventory),
        'current_readable_csv_with_text_column':sum(bool(x.get('readable')) and 'text' in x.get('columns',[]) for x in current_inventory),
        'current_nonblank_text_records_exact_builder_count':current_scan_records,
        'current_punctuation_normalized_unique_keys':len(current_unique_norm_text),
        'current_readable_file_identity_counts':{'readable_csv_paths':sum(bool(x.get('readable')) for x in current_inventory),'unique_sha256_file_identities':len(hash_groups),'parsed_data_rows_across_paths':sum(int(x.get('parsed_data_rows') or 0) for x in current_inventory),'parsed_data_rows_across_unique_file_identities':sum(int(next(e for e in current_inventory if e.get('sha256')==h).get('parsed_data_rows') or 0) for h in hash_groups),'nonblank_text_records_across_paths':current_scan_records},
        'sha256_duplicate_file_groups':len(duplicate_file_groups),'duplicate_file_groups':duplicate_file_groups,
        'historical_recorded_1732':dup.get('prior_csv_text_records_checked'),
        'historical_recorded_2708':leak.get('preexisting_sources_checked_rows'),
        'historical_2708_files_recorded':leak.get('preexisting_sources_checked_files'),
        'same_path_hash_row_crosswalk_available_for_historical_2708':False,
        'reconciliation_result':'UNRESOLVED: surviving artifacts record 1,732 from the builder’s current-tree readable nonblank-text-row scan and 2,708 across 17 files from an independent red-team scan, but do not preserve a common per-path inventory/hash/row manifest for the 2,708 population. The builder scan rule counts repeated nonblank text rows while its lookup map deduplicates keys; the 2,708 methodology/path list is not retained, so mathematical equivalence cannot be established.'},
      'restricted_csv_access':{
        'prior_recorded_restricted_path_count':len(oldpaths),
        'unique_restricted_paths':len(set(x['path'] for x in oldpaths)),
        'current_probes':{'stat_ok':sum(x.get('exists') is True for x in inaccessible),'read_ok':sum(bool(x.get('readable')) for x in inaccessible),'stat_error':sum('stat_error' in x for x in inaccessible),'read_errors':dict(Counter(x.get('read_error','') for x in inaccessible if x.get('read_error')))},
        'by_tree':dict(Counter('\\'.join(x['path'].split('\\')[:5]) for x in oldpaths)),
        'representative_read_only_icacls_probes':{'working/by_severity/critical/examples.csv':'exit 5, Access is denied; no ACL entry returned','experiments/synthetic_9000words_20260920/by_severity/critical/examples.csv':'exit 5, Access is denied; no ACL entry returned','archive/initial_80/by_severity/critical/examples.csv':'exit 5, Access is denied; no ACL entry returned'},
        'source_list':'Prior audit path list; all 60 are included row-by-row in RECORDED_60_FILE_ACCESS_PROBES.csv.',
        'no_acl_or_privilege_action':True},
      'archives':{'read_only_container_count':len(archive_inventory),'containers':archive_inventory,'restricted_csv_member_count':sum(len(x.get('members_matching_restricted_csv',[])) for x in archive_inventory),'unsupported_archive_search':{'extensions':['.7z','.rar'],'result':'No archive paths found under ai_service by rg --files; not present in archive scanner.'}},
      'limitations':[
        'No readable contents, hashes, or parsed row counts can be supplied for any restricted CSV that remains unreadable; path/stat metadata is not evidence of content overlap.',
        'The accessible 1,732 vs 2,708 historical scan populations cannot be reconciled without the independent 2,708 per-file inventory, exact scan code/config, and contemporaneous tree snapshot.',
        'Current re-scan sees a later repository state than the original 2026-10-05 scan and includes subsequently created reviewed/research CSVs; it is labeled current reproducibility only.',
        'The old 850-row corpus itself was assigned among old partitions, and future-looking evaluations on its old heldouts count as project historical exposure.',
        'No real-world incident identities can be inferred from lexical family links.',
        'No file under source trees was changed; no ACLs, owners, or permissions were changed; no model/training was run.' ]}
    (OUT/'FAMILY_EXPOSURE_AUDIT.json').write_text(json.dumps(out,indent=2,ensure_ascii=False)+'\n',encoding='utf-8')
    (OUT/'COMMANDS_AND_LIMITS.md').write_text('''# Family and prior-exposure audit\n\nRead-only audit. Exact Python command:\n\n```powershell\n& "C:\\laragon\\bin\\python\\python-3.13\\python.exe" "ai_service\\artifacts\\reports\\targeted_remediation_20261006T154647+0800\\FAMILY_EXPOSURE_AUDIT\\audit_family_exposure.py"\n```\n\nThe script reads the source report/provenance/family files, old and current split manifests, frozen reconciliation and historical access reports, and readable CSVs under `ai_service/datasets/emergency_text`. It writes outputs only to this directory. It does not elevate, query ACLs with PowerShell `Get-Acl`, alter ACL/owner/security, or run model/GPU code. CSVs not readable are not bypassed. It lists `ai_service` archive central directories and matching member names only; nothing is extracted or read from members. Three read-only `icacls` probes returned exit 5 / Access is denied without ACL entries.\n\nThe historical 1,732 and 2,708 figures cannot be reconciled from retained evidence because the 2,708 scan lacks a saved per-file path/hash/parsed-row crosswalk and exact scan code. The current scan is explicitly a later-tree reproduction and cannot replace that historical missing manifest.\n''',encoding='utf-8')
    print(json.dumps({'output':str(OUT),'source_rows':len(rows),'families':len(declared),'pair_count':len(pairs),
      'same_edges':current['same_scenario_pairs'],'current_effective_components':current['effective_family_components_after_same_edges'],
      'current_crossing_components':current['effective_components_crossing_partitions'],
      'historical_crossing_components':historical['effective_components_crossing_partitions'],
      'restricted_paths':len(oldpaths),'current_readable_csvs':sum(bool(x.get('readable')) for x in current_inventory),
      'current_scan_text_records':current_scan_records,'archive_containers':len(archive_inventory)},indent=2))

if __name__=='__main__': main()
