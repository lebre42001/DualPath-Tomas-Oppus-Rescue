import csv,json,math
from pathlib import Path
import torch
from transformers import AutoModelForSequenceClassification, AutoTokenizer
R=Path('ai_service/artifacts/reports/xlmr_850_humanconfirmed_fulltrain_20261006_1411'); MODEL=Path('ai_service/artifacts/checkpoints/text_xlmr_850_humanconfirmed_fulltrain_20261006_1411'); CSV=R/'frozen_splits/calibration.csv'; OUT=R/'calibration_evaluation'
labels=['minor','serious','critical','needs_review']; rows=list(csv.DictReader(CSV.open(encoding='utf-8-sig',newline=''))); device=torch.device('cuda')
tokenizer=AutoTokenizer.from_pretrained(MODEL,local_files_only=True,trust_remote_code=False); model=AutoModelForSequenceClassification.from_pretrained(MODEL,local_files_only=True,trust_remote_code=False).to(device); model.eval(); logits=[]
with torch.inference_mode():
 for start in range(0,len(rows),8):
  batch=rows[start:start+8]; enc=tokenizer([r['text'] for r in batch],padding=True,truncation=False,return_tensors='pt'); out=model(**{k:v.to(device) for k,v in enc.items()}).logits.float().cpu(); logits.extend(out)
logits=torch.stack(logits); target=torch.tensor([labels.index(r['label']) for r in rows]);
def probs(temp): return torch.softmax(logits/temp,dim=-1)
def nll(p): return float(-torch.log(p[torch.arange(len(target)),target].clamp_min(1e-12)).mean())
def ece(p,bins=10):
 conf,pred=p.max(dim=-1); acc=(pred==target).float(); val=0.0; detail=[]
 for b in range(bins):
  lo=b/bins; hi=(b+1)/bins; ix=(conf>=lo)&((conf<hi) if b<bins-1 else (conf<=hi)); n=int(ix.sum())
  if n:
   c=float(conf[ix].mean()); a=float(acc[ix].mean()); val+=(n/len(target))*abs(a-c); detail.append({'bin_lower':lo,'bin_upper':hi,'count':n,'mean_confidence':c,'accuracy':a})
 return val,detail
best=(float('inf'),1.0)
for i in range(50,501):
 t=i/100; score=nll(probs(t))
 if score<best[0]: best=(score,t)
temp=best[1]; raw=probs(1.0); cal=probs(temp); raw_ece,raw_bins=ece(raw); cal_ece,cal_bins=ece(cal)
conf,pred=raw.max(dim=-1); calconf,calpred=cal.max(dim=-1); counts={'rows':len(rows),'families':len({r['group_id'] for r in rows})}; urg=[i for i,r in enumerate(rows) if r['label'] in ('serious','critical')]
for threshold in (.5,.65,.75,.85,.9,.95,.99):
 accept=[i for i in range(len(rows)) if float(conf[i])>=threshold]; high=[i for i in urg if i in accept]; down=sum(float(pred[i]) in [] for i in [])
 auto_under=sum((labels[int(pred[i])]=='minor' and rows[i]['label'] in ('serious','critical')) or (rows[i]['label']=='critical' and labels[int(pred[i])]=='serious') for i in high)
 counts.setdefault('routing_thresholds',[]).append({'threshold':threshold,'automatic_coverage':len(accept)/len(rows),'human_review_rate':1-len(accept)/len(rows),'high_risk_automatic_coverage':len(high)/len(urg) if urg else None,'unsafe_automatic_undertriage':auto_under,'high_risk_support':len(urg)})
with (R/'calibration_evaluation'/'calibration_predictions.csv').open('w',encoding='utf-8-sig',newline='') as f:
 w=csv.writer(f,lineterminator='\n'); w.writerow(['case_id','language','true_label','raw_prediction','raw_confidence','calibrated_prediction','calibrated_confidence','raw_probabilities','temperature_scaled_probabilities'])
 for i,r in enumerate(rows): w.writerow([r['case_id'],r['language'],r['label'],labels[int(pred[i])],float(conf[i]),labels[int(calpred[i])],float(calconf[i]),json.dumps(raw[i].tolist()),json.dumps(cal[i].tolist())])
report={'dataset':'CALIBRATION only','count':len(rows),'language_counts':{l:sum(r['language']==l for r in rows) for l in ('en','tl','ceb','mixed')},'label_counts':{l:sum(r['label']==l for r in rows) for l in labels},'confidence_is_raw_softmax_unless_temperature_scaled':True,'raw_ece_10_bin':raw_ece,'temperature_scaling':{'method':'grid-search scalar T in [0.50,5.00] step 0.01 minimizing NLL on calibration partition only','temperature':temp,'raw_nll':nll(raw),'calibrated_nll':nll(cal),'calibrated_ece_10_bin':cal_ece,'calibration_examples':len(rows),'model_weights_changed':False},'raw_confidence_bins':raw_bins,'calibrated_confidence_bins':cal_bins,'routing_thresholds_on_calibration_only':counts['routing_thresholds'],'threshold_note':'descriptive calibration-set operating points; optimistic to quote as final performance; raw 0.65 runtime threshold remains provisional unless explicitly set later; do not apply calibration results to fresh test.','approved_for_deployment':False}
(R/'CALIBRATION_METRICS.json').write_text(json.dumps(report,indent=2)+'\n',encoding='utf-8'); print(json.dumps({'rows':len(rows),'temperature':temp,'raw_ece':raw_ece,'calibrated_ece':cal_ece,'raw_nll':nll(raw),'calibrated_nll':nll(cal),'routing':counts['routing_thresholds']},indent=2))

