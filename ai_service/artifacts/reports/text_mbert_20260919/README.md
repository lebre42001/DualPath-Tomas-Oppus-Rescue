# Experimental text training — September 19, 2026 (Asia/Manila)

Both requested mBERT experiments completed successfully: three epochs with a frozen backbone and three epochs with **all model layers trainable**. Neither candidate is suitable for live severity assignment. Training completion is not deployment approval.

## Data and reproducibility

- Source: `datasets/emergency_text/archive/initial_80/draft_reports.csv`; 80 synthetic, unreviewed rows, 54 incident groups, zero approved examples. No labels or review statuses were changed.
- Source fingerprint: `9337a70b5a30de1135bd040a918d034befb6781372fdac1a78cb9ff700732878`.
- English, Tagalog, Cebuano, and mixed language: 20 examples each. Labels: minor, serious, critical, needs_review, 20 each.
- Identical group-separated splits confirmed against the prior baseline: 60 training, 10 validation, 10 test examples. Seed 42. Related translations/paraphrases remain grouped according to the draft group IDs.
- Base: `google-bert/bert-base-multilingual-cased`, pinned revision `3f076fdb1ab68d5b2880cb87a0886f315b8146f8`; 177,856,516 parameters including the four-label classifier.
- CPU, two Torch threads, maximum 128 tokens; no examples truncated. Torch 2.11.0+cpu and Transformers 4.57.6 in `.venv-text`.
- Head: batch 2, accumulation 8, learning rate 0.001, best epoch 1. Full: batch 1, accumulation 16, learning rate 0.00002, best epoch 3. The smaller full-training batch limits memory use; this is not a controlled architecture comparison.
- Best epoch selected using validation macro-F1. The three-epoch runs were specified before their test scores were inspected. No test-driven hyperparameter search was performed; this test set has now been inspected and is not a fresh future release holdout.

## Results

Scores below compare raw model predictions with **draft labels**, before the review threshold. The prior baseline used the same source fingerprint and split manifest.

| Candidate | Validation macro-F1 | Test correct | Test macro-F1 | Automatic coverage at 0.65 |
| --- | ---: | ---: | ---: | ---: |
| Existing TF-IDF baseline | 1.0000 | 7/10 | 0.6792 | 0% |
| mBERT head only | 0.0625 | 1/10 | 0.0625 | 0% |
| mBERT full fine-tuning | 0.2625 | 2/10 | 0.1806 | 0% |

All three send all ten test cases for human review at the provisional 0.65 threshold. Zero accepted undertriage is therefore not evidence of useful or safe automated classification. The test includes six serious/critical cases, only one of them critical. No calibrated accuracy or language reliability claim is supported.

The full candidate's raw correct counts by language are English 0/1, Tagalog 1/3, Cebuano 1/2, and mixed 0/4. These tiny denominators cannot establish language performance. The standard metrics files' `per_language` section describes **review-routed** predictions; `candidate_verification.json` separately records raw language results and all ten synthetic test predictions for each transformer.

## Time and resource observations

- Head artifact creation through final metadata: approximately 36 seconds, excluding Python/import time. This is a filesystem timestamp estimate, not an instrumented training timer.
- Full process start through final metadata: **190.78 seconds (about 3 minutes 11 seconds)**, including model loading, training, checkpoint writing and evaluation.
- Full process observation during training: 3,159 MiB working set, 3,528 MiB peak working set reported at that point, 4,547 MiB private allocation. This is a sampled observation, not the final peak. The machine has about 7.2 GiB usable RAM and a Windows pagefile.
- Offline single-description median inference: about 157 ms for head and 141 ms for full, two CPU threads, ten short synthetic examples per candidate. These are direct predictor timings, not HTTP/application latency or a throughput benchmark.
- Larger datasets, longer descriptions and different hardware will take different times. The short run does not establish that a useful production model can be trained in three minutes.

## Verification

`verify_candidates.py` reloaded both actual safetensors checkpoints locally and verified:

- Unreviewed checkpoints are rejected when experimental loading is disabled.
- Experimental predictions remain marked for responder review.
- Overlength descriptions return no severity, no confidence and no probabilities.
- All ten reloaded test predictions reproduce each saved raw test accuracy.
- Saved tokenizer encodings match the pinned original tokenizer on all 80 descriptions (`tokenizer_verification.json`).

Transformers 4.57.6 emitted a Mistral-regex warning when loading these local BERT tokenizers. Inspection of the installed loader showed its local-config warning path also applies to a BERT config saved with this version. Both are `BertTokenizerFast` using `BertPreTokenizer`; the complete dataset token encodings match the pinned source. No Mistral tokenizer modification was applied.

Local `/login` returned HTTP 200 and `/health/text` returned HTTP 200 with `enabled=false`, `status=disabled`. Training did not activate text AI or change the existing image model, Laravel environment, or online deployment. No runtime application code was changed by this experiment. Online deployment remains a separate, unverified state; these experimental checkpoints must not be promoted through the existing release gate.

## Re-run offline verification

From this repository's `ai_service` directory:

```powershell
& '.\.venv-text\Scripts\python.exe' artifacts/reports/text_mbert_20260919/verify_candidates.py
```

Checkpoints are in `artifacts/checkpoints/text_mbert_draft_head_20260919` and `artifacts/checkpoints/text_mbert_draft_full_20260919`. Each contains safetensors weights, tokenizer, metadata, training history, split manifest, validation metrics and test metrics. New training runs must use a new output directory.

## Next data work

Have a responder/MDRRMO reviewer resolve the draft label definitions and review this pack using `datasets/emergency_text/annotation_guide.md`. Collect de-identified descriptions representing actual reporting language, vague reports, negation, misspellings and code switching; keep related reports grouped. Preserve a separately reviewed, incident-independent holdout before further tuning. Compare candidates on validation data and evaluate calibration, review coverage and severity errors with the reviewer before any release. More epochs alone cannot correct unreviewed labels or establish field reliability.
