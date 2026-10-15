from pathlib import Path
import csv, json, hashlib, sys
import torch
from transformers import AutoModelForSequenceClassification, AutoTokenizer

ROOT = Path(__file__).resolve().parents[5]
DATA = ROOT / 'ai_service/datasets/emergency_text/experiments/civilian_multilingual_6000w_20261005'
CKPT = ROOT / 'ai_service/artifacts/checkpoints/text_xlmr_civilian_multilingual_20261005/trial_lr_2e-5_seed42'
OUT = Path(__file__).resolve().parent
labels = ['minor','serious','critical','needs_review']
with (DATA/'reports.csv').open(encoding='utf-8-sig', newline='') as f:
    rows = list(csv.DictReader(f))
if len(rows) != 850:
    raise RuntimeError(f'Expected 850 rows; got {len(rows)}')
metadata = json.loads((CKPT/'metadata.json').read_text(encoding='utf-8'))
tok = AutoTokenizer.from_pretrained(CKPT, local_files_only=True, trust_remote_code=False)
model = AutoModelForSequenceClassification.from_pretrained(CKPT, local_files_only=True, trust_remote_code=False)
device = torch.device('cuda' if torch.cuda.is_available() else 'cpu')
model.to(device).eval()
max_length = int(metadata['max_length'])
preds = []
for start in range(0, len(rows), 8):
    batch = rows[start:start+8]
    encoded_by_idx = []
    result = [None] * len(batch)
    eligible_idx = []
    for i, row in enumerate(batch):
        if len(tok.encode(row['text'], truncation=False)) <= max_length:
            eligible_idx.append(i)
    if eligible_idx:
        encoded = tok([batch[i]['text'] for i in eligible_idx], padding=True, truncation=False, return_tensors='pt')
        with torch.inference_mode():
            logits = model(**{k:v.to(device) for k,v in encoded.items()}).logits
            probs = torch.softmax(logits.float(), dim=-1).cpu().tolist()
        for i, p in zip(eligible_idx, probs): result[i] = p
    for row, p in zip(batch, result):
        if p is None:
            preds.append({'case_id': row['case_id'], 'raw_prediction': None, 'confidence_raw_softmax': None, 'probabilities': None, 'token_count': len(tok.encode(row['text'], truncation=False)), 'unscored_reason':'exceeds_checkpoint_max_length'})
        else:
            j = max(range(len(labels)), key=lambda x:p[x])
            preds.append({'case_id': row['case_id'], 'raw_prediction': labels[j], 'confidence_raw_softmax': p[j], 'probabilities':dict(zip(labels,p)), 'token_count': len(tok.encode(row['text'], truncation=False)), 'unscored_reason':''})
payload = {'method':'Local checkpoint inference, eval mode, batch size 8, tokenizer/config from selected checkpoint; token lengths above metadata max_length left unscored; raw softmax is uncalibrated.', 'checkpoint':str(CKPT), 'device':str(device), 'metadata_version':metadata['version'], 'max_length':max_length, 'rows':preds}
(OUT/'MODEL_PREDICTIONS_850.json').write_text(json.dumps(payload,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
print(json.dumps({'rows':len(rows),'scored':sum(p['raw_prediction'] is not None for p in preds),'unscored':sum(p['raw_prediction'] is None for p in preds),'device':str(device),'six_check':{cid:next((p for p in preds if p['case_id']==cid),None) for cid in ['CEB-CRITICAL-001','CEB-CRITICAL-003','CEB-CRITICAL-024','MX-CRITICAL-002','MX-CRITICAL-013','TL-126']}},ensure_ascii=False,indent=2))

