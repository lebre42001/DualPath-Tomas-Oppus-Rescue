from pathlib import Path
import csv, json, hashlib, re, collections, datetime, unicodedata
BASE = Path(__file__).resolve().parents[5]
DS = BASE/'ai_service/datasets/emergency_text/experiments/civilian_multilingual_6000w_20261005'
REVIEWED = BASE/'ai_service/datasets/emergency_text/reviewed/civilian_multilingual_850_humanconfirmed_20261006T141537+0800'
ATTEST = BASE/'ai_service/datasets/emergency_text/reviews/RESPONDER_850_BATCH_VERBAL_ATTESTATION_20261006T141537+0800/RESPONDER_850_BATCH_VERBAL_ATTESTATION_20261006T141537+0800.json'
CKPT = BASE/'ai_service/artifacts/checkpoints/text_xlmr_civilian_multilingual_20261005/trial_lr_2e-5_seed42'
OUT = Path(__file__).resolve().parent
LABELS=['minor','serious','critical','needs_review']

def readcsv(p):
    with p.open(encoding='utf-8-sig',newline='') as f: return list(csv.DictReader(f))
def sha(p):
    h=hashlib.sha256()
    with p.open('rb') as f:
        for b in iter(lambda:f.read(1024*1024),b''): h.update(b)
    return h.hexdigest()
def writecsv(path, rows, fields):
    with path.open('w',encoding='utf-8-sig',newline='') as f:
        w=csv.DictWriter(f,fieldnames=fields,extrasaction='ignore'); w.writeheader(); w.writerows(rows)

def countwords(t): return len(t.split())

source=readcsv(DS/'reports.csv'); human=readcsv(REVIEWED/'reports.csv'); prov=readcsv(DS/'provenance.csv')
if len(source)!=850 or len(human)!=850: raise ValueError('Expected source and owner-attested review rows to both contain 850 rows')
src={r['case_id']:r for r in source}; hum={r['case_id']:r for r in human}; provenance={r['case_id']:r for r in prov}
if set(src)!=set(hum): raise ValueError('Source/reviewed case ID coverage differs')
if any(src[k]['text']!=hum[k]['text'] or src[k]['label']!=hum[k]['label'] for k in src): raise ValueError('Owner-attested reviewed dataset changed text or label; stop')
att=json.loads(ATTEST.read_text(encoding='utf-8'))
if att['source_dataset_sha256']!=sha(DS/'reports.csv') or att['covered_row_count']!=850: raise ValueError('Attestation does not bind the exact source file')
model=json.loads((OUT/'MODEL_PREDICTIONS_850.json').read_text(encoding='utf-8'))
mp={r['case_id']:r for r in model['rows']}
if set(mp)!=set(src): raise ValueError('Model inference case coverage differs')
# Exposure provenance: all 850 cases map into stored fixed experiment partitions; this labels past model exposure, not present data suitability.
partition_files={'train_in_sample':'train.csv','validation_used_for_selection':'validation.csv','calibration_reserved':'calibration.csv','fresh_test':'fresh_test.csv','challenge':'challenge.csv'}
partition_sets={k:{r['case_id'] for r in readcsv(DS/v)} for k,v in partition_files.items()}
exposure={cid:[k for k,s in partition_sets.items() if cid in s] for cid in src}
# Inference uses original checkpoint; train membership marks direct in-sample use, validation marks selection exposure. All other splits remain experimental.
# Dataset source text heuristics are deliberately transparent pattern flags and cannot adjudicate severity.
NEG = re.compile(r"\b(?:not|no|never|without|isn't|wasn't|aren't|didn't|doesn't|can't|cannot|wala|walay|dili|di|hindi|walang|huwag|hindi na|dili na|wala na)\b",re.I)
CRIT = re.compile(r"(?:unresponsive|not responding|does not respond|no response|unconscious|not breathing|no breathing|stopped breathing|shallow breath|difficulty breathing|struggling to breathe|heavy bleeding|bleeding (?:won't|will not|does not) stop|uncontrolled bleeding|trapped.{0,45}(?:fire|burn|flood|water)|(?:fire|burn|flood|water).{0,45}trapped|dili motubag|wala motubag|dili na motubag|walay ginhawa|walay pagginhawa|hinay ang pagginhawa|bug-at.{0,30}pagdugo|dili mohunong.{0,20}pagdugo|naipit.{0,50}(?:sunog|nasunog|tubig|baha)|nasunog.{0,50}naipit|walang malay|hindi sumasagot|hindi tumutugon|hindi humihinga|walang paghinga|hirap huminga|malakas.{0,25}pagdurugo|hindi tumitigil.{0,20}dugo|naipit.{0,40}(?:sunog|baha|tubig))",re.I)
SER = re.compile(r"(?:injur(?:y|ed|ies)|wound|cut|bleed|pain|swelling|dizzy|cannot move|unable to move|fracture|broken bone|nasamad|samad|nasugatan|hiwa|dugo|sakit|nahilo|nabali|dili maalsa|dili makalihok|sugat|sakit kaayo|injured|sugatan|dugo|nahihilo|bali|hindi makagalaw|hirap gumalaw)", re.I)
OBJ = re.compile(r"(?:car|vehicle|motorcycle|motor|bus|truck|jeep|scooter|kotse|sasakyan|motorsiklo|jeepney|awto|bangga|collision|crash|accident|road|kalsada|kalye)",re.I)
PERSON = re.compile(r"(?:person|people|victim|passenger|rider|driver|occupant|cyclist|pedestrian|child|adult|man|woman|siklista|motorsiklista|motorista|tao|pasahero|sakay|rider|drayber|biktima|lalaki|babae|bata|lalaking|babaeng)",re.I)
UNKNOWN = re.compile(r"(?:unknown|not sure|unclear|unsure|maybe|possibly|hindi alam|di alam|dili ko kabalo|wala ko kabalo|ambot|siguro|baka|yata|parang|murag|basin|daw|reportedly|sabi|may nagsabi|someone said)",re.I)
PAST = re.compile(r"(?:yesterday|last night|earlier today|kahapon|kagabi|ganina|kagahapon|before|previously|storya|hypothetical|example lang)",re.I)
MULTI = re.compile(r"(?:both|another|others|two people|passenger and driver|rider and passenger|ang isa|usa pa|laing|uban|sila|they|another person|the other|driver.{0,35}passenger|rider.{0,35}passenger)",re.I)
SEVERE_MECH = re.compile(r"(?:collision|crash|bangga|rollover|rolled over|nasunog|sunog|nalubog|submerged|fell|natumba|high speed|kusog nga bangga|malakas na bangga|bus|truck|van|jeepney)",re.I)
INJ_NONE = re.compile(r"(?:no one injured|nobody injured|no injuries|not injured|walay nasamad|walay nasamdan|walang nasugatan|walang nasaktan|walang injury|walang nasugatan|not hurt|uninjured)",re.I)
SHORT_PANIC = re.compile(r"(?:help|tabang|tulong|emergency|aksidente|accident|crash|bangga|please|please help|urgent|rescue|saklolo)",re.I)
MINOR_CUE = re.compile(r"(?:superficial|minor (?:cut|scratch|scrape|graze)|small (?:cut|scratch|scrape)|scratch only|gasgas|gasgas ra|gamay ra nga samad|maliit na hiwa|gasgas lang|kaunting gasgas|bahagyang gasgas)",re.I)
SAFE_CUE = re.compile(r"(?:alert|conscious|responsive|safe|moving normally|bleeding stopped|no continuing bleeding|stopped bleeding|walay padayon nga pagdugo|mihunong ang dugo|nahunong ang pagdugo|gising|mulat|maayos ang lagay|huminto ang pagdurugo|dugo ay tumigil)",re.I)
OUT_OF_SCOPE = re.compile(r"(?:fire only|house fire|sunog sa balay|baha|flood|heart attack|medical emergency|seizure|stroke)",re.I)
KNOWN_SIX={'CEB-CRITICAL-001','CEB-CRITICAL-003','CEB-CRITICAL-024','MX-CRITICAL-002','MX-CRITICAL-013','TL-126'}
rows_out=[]; short_out=[]; counts=collections.Counter(); by_lang=collections.defaultdict(collections.Counter); by_label=collections.defaultdict(collections.Counter)
confusion={a:{b:0 for b in LABELS} for a in LABELS}
for cid,r in src.items():
    hp=hum[cid]; p=mp[cid]; pr=provenance[cid]; text=r['text']; human_label=hp['label']; raw=p['raw_prediction']; prob=p['confidence_raw_softmax']; wc=countwords(text)
    pred_suggested = raw if prob is not None and prob >= 0.65 else 'needs_review'
    agreement='UNCERTAIN' if pred_suggested=='needs_review' and human_label!='needs_review' and raw!='needs_review' else ('AGREE' if raw==human_label else 'DISAGREE')
    if raw is None: agreement='UNCERTAIN'
    # Prediction-risk direction uses raw class output, distinct from threshold abstention.
    under='NONE'; over='NONE'
    rank={'minor':0,'serious':1,'critical':2}
    if human_label in rank and raw in rank:
        if rank[raw] < rank[human_label]: under=f'{human_label.upper()}_PREDICTED_{raw.upper()}'
        elif rank[raw] > rank[human_label]: over=f'{raw.upper()}_PREDICTED_FOR_{human_label.upper()}'
    elif human_label=='needs_review' and raw in ('serious','critical'): over='MODEL_ASSIGNS_SEVERITY_TO_NEEDS_REVIEW_CASE'
    flags=[]
    if cid in KNOWN_SIX: flags.append('KNOWN_FRESH_TEST_CRITICAL_UNDERRANKING')
    if wc<=8: flags.append('SHORT_TEXT_PANIC_OR_UNDERSPECIFIED')
    if NEG.search(text): flags.append('NEGATION_SCOPE_REQUIRES_READING')
    if UNKNOWN.search(text): flags.append('EXPLICIT_UNCERTAINTY_WORDING')
    if PAST.search(text): flags.append('POSSIBLE_PAST_OR_REPORTED_EVENT')
    if MULTI.search(text): flags.append('MULTIPLE_PERSON_REFERENCE')
    if OBJ.search(text) and not PERSON.search(text): flags.append('VEHICLE_OR_MECHANISM_WITHOUT_CLEAR_PERSON_STATUS')
    if PERSON.search(text) and OBJ.search(text) and (MULTI.search(text) or UNKNOWN.search(text)):
        flags.append('PERSON_OBJECT_ROLE_LINK_REQUIRES_CONTEXT')
    if PERSON.search(text) and re.search(r"(?:passenger|occupant|sakay|pasahero)",text,re.I) and not (SER.search(text) or CRIT.search(text)):
        flags.append('OCCUPANT_PRESENT_STATUS_UNSPECIFIED')
    if SEVERE_MECH.search(text) and not (CRIT.search(text) or SER.search(text)):
        flags.append('SEVERE_MECHANISM_WITHOUT_EXPLICIT_PATIENT_CONDITION')
    if INJ_NONE.search(text) and CRIT.search(text): flags.append('NO_INJURY_AND_DANGER_CUE_CO_OCCUR_REVIEW')
    if r['language']=='mixed': flags.append('MIXED_LANGUAGE_NATURALNESS_AND_SCOPE_REVIEW')
    if raw is not None and human_label in ('serious','critical') and raw in ('minor','serious') and rank[raw]<rank[human_label]: flags.append('MODEL_HIGH_RISK_UNDERSCORING')
    if raw is not None and prob < 0.65: flags.append('PROVISIONAL_THRESHOLD_WOULD_ROUTE_TO_REVIEW')
    if human_label=='needs_review': flags.append('OUT_OF_SCOPE_OR_INSUFFICIENT_TEXT_RETAIN_ABSTENTION')
    rubric_signals=[]
    if CRIT.search(text): rubric_signals.append('possible_critical_indicator_literal_match; inspect negation, attribution, time, person')
    if SER.search(text): rubric_signals.append('possible_injury_or_urgent_need_literal_match; does_not_distinguish_serious_vs_critical')
    if MINOR_CUE.search(text): rubric_signals.append('superficial_or_minor_injury_boundary_phrase; apply_local_policy_and_check_context')
    if SAFE_CUE.search(text): rubric_signals.append('explicit_reassuring_condition_phrase; does_not_cancel_other_person_or_hazard')
    if INJ_NONE.search(text): rubric_signals.append('explicit_no_injury_phrase; verify_scope_and_person_references')
    if OBJ.search(text) and not (SER.search(text) or CRIT.search(text) or PERSON.search(text)):
        rubric_signals.append('vehicle_or_property_event_without_explicit_injury_or_person_status; do_not_infer_no_injury')
    if OUT_OF_SCOPE.search(text): rubric_signals.append('possible_out_of_scope_emergency; accident_classifier_should_abstain_and_route')
    if agreement=='AGREE' and not flags and raw not in ('needs_review',): decision='GOOD'
    elif any(f in flags for f in ['VEHICLE_OR_MECHANISM_WITHOUT_CLEAR_PERSON_STATUS','OCCUPANT_PRESENT_STATUS_UNSPECIFIED','SEVERE_MECHANISM_WITHOUT_EXPLICIT_PATIENT_CONDITION','NO_INJURY_AND_DANGER_CUE_CO_OCCUR_REVIEW','EXPLICIT_UNCERTAINTY_WORDING','POSSIBLE_PAST_OR_REPORTED_EVENT','MULTIPLE_PERSON_REFERENCE','PERSON_OBJECT_ROLE_LINK_REQUIRES_CONTEXT','MIXED_LANGUAGE_NATURALNESS_AND_SCOPE_REVIEW']): decision='SEMANTIC_AMBIGUITY'
    elif 'NO_INJURY_AND_DANGER_CUE_CO_OCCUR_REVIEW' in flags or 'KNOWN_FRESH_TEST_CRITICAL_UNDERRANKING' in flags: decision='FLAG_FOR_FUTURE_HUMAN_RECHECK'
    elif under!='NONE' or over!='NONE' or wc<=8 or agreement!='AGREE' or (prob is not None and prob<0.65): decision='FLAG_FOR_FUTURE_HUMAN_RECHECK'
    else: decision='GOOD'
    rationale_parts=[f'raw XLM-R={raw or "UNSCORED"}; source attested label={human_label}; raw-softmax max={prob:.3f} (uncalibrated)' if prob is not None else f'raw XLM-R unscored; source attested label={human_label}']
    if exposure[cid]: rationale_parts.append('checkpoint exposure='+','.join(exposure[cid]))
    if flags: rationale_parts.append('rule flags='+','.join(flags))
    rationale_parts.append('pattern flags are review prompts only; not severity adjudication')
    row={
      'case_id':cid,'human_reviewed_label':human_label,'label_evidence':'owner_attested_verbal_batch; no direct responder record','human_review_status':hp.get('review_status',''),'rubric_version':pr.get('rubric_version',''),
      'ai_suggested_label':pred_suggested,'model_raw_prediction':raw or 'UNSCORED','ai_agreement':agreement,'ai_review_decision':decision,
      'model_confidence_raw_softmax':f'{prob:.8f}' if prob is not None else '', 'confidence_calibrated':'false','checkpoint_exposure':';'.join(exposure[cid]),
      'undertriage':under,'overtriage':over,'semantic_ambiguity':';'.join([x for x in flags if x in ['EXPLICIT_UNCERTAINTY_WORDING','POSSIBLE_PAST_OR_REPORTED_EVENT','MULTIPLE_PERSON_REFERENCE','PERSON_OBJECT_ROLE_LINK_REQUIRES_CONTEXT','MIXED_LANGUAGE_NATURALNESS_AND_SCOPE_REVIEW','VEHICLE_OR_MECHANISM_WITHOUT_CLEAR_PERSON_STATUS','OCCUPANT_PRESENT_STATUS_UNSPECIFIED','SEVERE_MECHANISM_WITHOUT_EXPLICIT_PATIENT_CONDITION','NO_INJURY_AND_DANGER_CUE_CO_OCCUR_REVIEW']]),
      'person_object_risks':';'.join([x for x in flags if x in ['VEHICLE_OR_MECHANISM_WITHOUT_CLEAR_PERSON_STATUS','OCCUPANT_PRESENT_STATUS_UNSPECIFIED','PERSON_OBJECT_ROLE_LINK_REQUIRES_CONTEXT']]),'negation_risk':'NEGATION_SCOPE_REQUIRES_READING' if 'NEGATION_SCOPE_REQUIRES_READING' in flags else '',
      'keyword_shortcut_risks':';'.join([x for x in flags if x in ['SHORT_TEXT_PANIC_OR_UNDERSPECIFIED','SEVERE_MECHANISM_WITHOUT_EXPLICIT_PATIENT_CONDITION']]),
      'rubric_heuristic_signals':' | '.join(rubric_signals),
      'rule_flags':';'.join(flags),'text':text,'language':r['language'],'scenario_family_id':pr.get('scenario_family_id',''),'word_count':wc,'length_bucket':pr.get('length_bucket',''),
      'concise_rationale':' '.join(rationale_parts),'attestation_id':att.get('batch_id',''),'attestation_status':att.get('project_owner_attestation',{}).get('attestation_status','')
    }
    rows_out.append(row); counts[decision]+=1; counts['agree_'+agreement]+=1; counts['raw_'+(raw or 'UNSCORED')]+=1
    by_lang[r['language']][decision]+=1; by_label[human_label][decision]+=1
    if human_label in LABELS and raw in LABELS: confusion[human_label][raw]+=1
    if wc<=8: short_out.append(row)
# Output row-level audit
fields=list(rows_out[0])
writecsv(OUT/'RESPONDER_STYLE_AI_AUDIT_850.csv',rows_out,fields)
writecsv(OUT/'SHORT_PANIC_AI_REVIEW_301.csv',short_out,fields)
# Diagnosis of fixed six known cases and explicit boundary concerns, not assertions that approved labels are wrong.
focus={
'CEB-CRITICAL-001':'Known fresh-test critical→minor model disagreement; fluent Cebuano reviewer should verify exact response/breathing and negation scope.',
'CEB-CRITICAL-003':'Known fresh-test critical→minor disagreement; inspect whether person condition is stated or inferred from mechanism.',
'CEB-CRITICAL-024':'Known fresh-test critical→serious downgrade; assess whether described sign is immediate life-threatening under local rubric.',
'MX-CRITICAL-002':'Known mixed Cebuano/Tagalog/English critical→minor disagreement; verify clauses belong to the same driver and interpret response/breathing wording.',
'MX-CRITICAL-013':'Known mixed-language critical→serious disagreement; verify bleeding severity/continuation and whether pallor/slow response refer to same person.',
'TL-126':'Known short critical→minor disagreement; text says occupied submerged car without occupant condition; review whether draft label is substantiated by stated active danger.'}
focused=[]
for cid,note in focus.items():
    x=next(x for x in rows_out if x['case_id']==cid)
    focused.append({'case_id':cid,'human_reviewed_label':x['human_reviewed_label'],'ai_suggested_label':x['ai_suggested_label'],'model_raw_prediction':x['model_raw_prediction'],'model_confidence_raw_softmax':x['model_confidence_raw_softmax'],'language':x['language'],'word_count':x['word_count'],'text':x['text'],'undertriage':x['undertriage'],'semantic_ambiguity':x['semantic_ambiguity'],'person_object_risks':x['person_object_risks'],'negation_risk':x['negation_risk'],'keyword_shortcut_risks':x['keyword_shortcut_risks'],'checkpoint_exposure':x['checkpoint_exposure'],'ai_review_decision':x['ai_review_decision'],'human_focus':note,'interpretation':'The disagreement is relative to an owner-attested synthetic label, not independently verified responder ground truth; no label correction is made.'})
with (OUT/'SIX_HIGH_RISK_CASES_DEEP_REVIEW.json').open('w',encoding='utf-8') as f: json.dump({'status':'AI-only diagnostic, no label changes','cases':focused},f,ensure_ascii=False,indent=2); f.write('\n')
# Compute diagnostic agreement/metrics; don't present as independent test because most cases in sample/exposure split.
scored=[x for x in rows_out if x['model_raw_prediction'] in LABELS]
correct=sum(x['model_raw_prediction']==x['human_reviewed_label'] for x in scored)
per_label={}
for lab in LABELS:
    tp=confusion[lab][lab]; support=sum(confusion[lab].values()); pred_count=sum(confusion[a][lab] for a in LABELS)
    per_label[lab]={'support':support,'precision':tp/pred_count if pred_count else 0,'recall':tp/support if support else 0,'f1':2*tp/(support+pred_count) if support+pred_count else 0}
macro=sum(v['f1'] for v in per_label.values())/4
urgent=[x for x in scored if x['human_reviewed_label'] in ('serious','critical')]
raw_under=sum(1 for x in urgent if x['undertriage']!='NONE')
exposure_counts=collections.Counter(';'.join(exposure[cid]) if exposure[cid] else 'unmapped' for cid in src)
intersection_count=sum(1 for v in exposure.values() if len(v)>1)
short_disagree=sum(1 for x in short_out if x['ai_agreement']!='AGREE')
split_metrics={}
for split_name in partition_files:
    part=[x for x in rows_out if split_name in exposure[x['case_id']]]
    support=[x for x in part if x['human_reviewed_label'] in ('serious','critical')]
    split_metrics[split_name]={
      'rows':len(part),
      'raw_agreement_count':sum(x['model_raw_prediction']==x['human_reviewed_label'] for x in part),
      'raw_agreement_rate':sum(x['model_raw_prediction']==x['human_reviewed_label'] for x in part)/len(part) if part else None,
      'serious_critical_attested_support':len(support),
      'raw_severity_downrank_count':sum(x['undertriage']!='NONE' for x in support),
      'raw_severity_downrank_case_ids':[x['case_id'] for x in support if x['undertriage']!='NONE'],
      'critical_to_minor_count':sum(x['human_reviewed_label']=='critical' and x['model_raw_prediction']=='minor' for x in part),
      'critical_to_serious_count':sum(x['human_reviewed_label']=='critical' and x['model_raw_prediction']=='serious' for x in part),
      'warning':'diagnostic only against owner-attested synthetic labels; validation selected model; train is in-sample; not prospective validation'
    }
summary={
 'audit_id':'xlmr-850-owner-attested-ai-audit-20261006','created_at_local':datetime.datetime.now().astimezone().isoformat(),
 'source_rows':850,'attestation':{'batch_id':att['batch_id'],'status':att['project_owner_attestation']['attestation_status'],'covered_rows':att['covered_row_count'],'recording_basis':att['project_owner_attestation']['recording_basis'],'review_claim':'owner reports human responder(s) accepted complete batch as-is; no direct responder identity/audio/transcript, date/time, or row-specific rationale independently verified'},
 'input_integrity':{'source_reports_sha256':sha(DS/'reports.csv'),'owner_attested_reports_sha256':sha(REVIEWED/'reports.csv'),'source_provenance_sha256':sha(DS/'provenance.csv'),'attestation_sha256':sha(ATTEST),'labels_unchanged_in_owner_dataset_vs_source':True,'case_id_coverage_equal':True,'model_output_rows':len(mp),'unscored_model_rows':sum(p['raw_prediction'] is None for p in mp.values())},
 'model':{'checkpoint':str(CKPT.relative_to(BASE)),'metadata_version':model['metadata_version'],'checkpoint_model_sha256':sha(CKPT/'model.safetensors'),'base_model':'FacebookAI/xlm-roberta-base','device':model['device'],'max_length':model['max_length'],'inference_batch_size':8,'confidence':'raw max softmax, not calibrated; no safety threshold validation','training_or_deployment_changed':False},
 'exposure_counts':dict(exposure_counts),'partition_overlap_row_count':intersection_count,'diagnostic_metrics_by_exposure':split_metrics,
 'ai_diagnostic_only_against_owner_attested_labels':{'overall_raw_agreement':correct/len(scored) if scored else None,'raw_accuracy':correct/len(scored) if scored else None,'raw_macro_f1':macro,'confusion_rows_attested_columns_raw_prediction':confusion,'per_label':per_label,'urgent_serious_critical_raw_downrank_count':raw_under,'urgent_support':len(urgent),'urgent_raw_downrank_rate':raw_under/len(urgent) if urgent else None,'metrics_caution':'Whole 850 is not a held-out evaluation: 516 train rows are directly in-sample, 124 validation rows were used for selection, and 210 rows belong to other splits; the same synthetic batch and reported labels are owner-attested, not independently verified real-world outcomes.'},
 'review_triage_decisions':dict(counts),'by_language':{k:dict(v) for k,v in by_lang.items()},'by_attested_label':{k:dict(v) for k,v in by_label.items()},
 'short_panic':{'rows_at_most_8_words':len(short_out),'agreement_not_agree_count':short_disagree,'word_buckets':dict(collections.Counter('1-3' if x['word_count']<=3 else '4-8' for x in short_out)),'note':'Short/panic flag is only lexical length and cue based; it is not a severity label.'},
 'known_six_cases':{'case_ids':list(focus),'reproduced_count':sum(1 for x in focused if x['undertriage']!='NONE'),'interpretation':'Previously observed fresh-test model disagreements reproduced at essentially identical probabilities; labels remain as owner-attested and unchanged.'},
 'limits':['Attestation is project-owner-reported verbal responder batch approval, not directly corroborated.','All source descriptions are synthetic-origin, not real incident reports.','Heuristic flags cannot safely adjudicate Tagalog/Cebuano/code-switched semantics or clinical urgency.','Raw softmax confidence is uncalibrated; the stored threshold 0.65 is provisional.','Whole-dataset agreement is descriptive only, not validation or acceptance evidence.','The legacy annotation_guide.md is explicitly provisional and states no injury statement is not evidence of no injury.','No training, splitting, active model mutation, deployment, or source label edits were performed.'],
 'files':['RESPONDER_STYLE_AI_AUDIT_850.csv','SHORT_PANIC_AI_REVIEW_301.csv','SIX_HIGH_RISK_CASES_DEEP_REVIEW.json','MODEL_PREDICTIONS_850.json','run_inference.py','SUMMARY.json','METHOD.md']}
(OUT/'SUMMARY.json').write_text(json.dumps(summary,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
print(json.dumps({'source_rows':850,'agreement':summary['ai_diagnostic_only_against_owner_attested_labels']['overall_raw_agreement'],'raw_macro_f1_diagnostic':macro,'raw_urgent_downrank':raw_under,'urgent_support':len(urgent),'short_rows':len(short_out),'decisions':dict(counts),'exposure_counts':dict(exposure_counts),'partition_overlap':intersection_count,'six':[{'id':x['case_id'],'label':x['human_reviewed_label'],'prediction':x['model_raw_prediction'],'risk':x['undertriage']} for x in focused]},indent=2))
