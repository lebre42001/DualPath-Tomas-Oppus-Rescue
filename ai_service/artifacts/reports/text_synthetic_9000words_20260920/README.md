# September 20 multilingual text training experiment

**Completed:** three epochs of full-backbone mBERT fine-tuning, final evaluation, and independent checkpoint reload verification. The training command finished successfully in **661.38 seconds (11 minutes 1.38 seconds)** on CPU. See [the run receipt](run_status.json).

**Not ready for live severity decisions:** raw predictions matched 20/27 synthetic draft test labels (74.07%), but recognized only 1/5 critical cases. At the provisional 0.65 threshold, all 27 test descriptions required review. The checkpoint remains experimental and inactive.

## Results

| Partition | Raw correct | Raw accuracy | Raw macro-F1 | Raw critical recall | Automatic severity coverage |
| --- | ---: | ---: | ---: | ---: | ---: |
| Validation | 25/32 | 78.13% | 0.6504 | 0/6 | 0% |
| Test | 20/27 | 74.07% | 0.6716 | 1/5 | 0% |

Raw predictions are the highest-scoring class before abstention. After applying the review threshold, all validation and test examples route to `needs_review`; test routed accuracy is 8/27 (29.63%) and macro-F1 is 0.1143. Those figures include the eight draft examples actually labeled `needs_review`, not successful automatic severity decisions.

Three critical test descriptions were predicted as a lower severity before abstention; another was classified as `needs_review`. All 11 serious/critical test cases require responder review at the threshold. Zero accepted undertriage with **zero coverage** is not evidence of a reliable triage model. Scores remain uncalibrated.

| Test language | Raw correct | Raw accuracy | Raw macro-F1 | Raw critical recall |
| --- | ---: | ---: | ---: | ---: |
| English | 8/9 | 88.89% | 0.9000 | 1/1 |
| Tagalog | 5/9 | 55.56% | 0.4679 | 0/2 |
| Bisaya | 7/9 | 77.78% | 0.6643 | 0/2 |

These are very small synthetic partitions, not estimates of field accuracy. There are no mixed-language rows in this pack; it provides no evidence of mixed-language performance. Every test language has zero automatic severity coverage.

| Epoch | Training loss | Validation raw macro-F1 |
| --- | ---: | ---: |
| 1 | 1.3907 | 0.2063 |
| 2 | 1.3778 | 0.0789 |
| 3 (selected) | 1.2651 | 0.6504 |

The test set was evaluated only after selecting epoch 3 using validation macro-F1. Independent reload reproduced all saved test metrics. Median isolated CPU inference was **0.165 seconds** per description with two threads, excluding model loading and HTTP handling; this is a local measurement, not a latency guarantee.

## Dataset and folders

The [dataset index](../../../datasets/emergency_text/experiments/synthetic_9000words_20260920/README.md)
links the experiment's master CSV and its severity/language browsing folders.

| Language | Description words | Reports |
| --- | ---: | ---: |
| English | 3,000 | 99 |
| Tagalog | 3,000 | 97 |
| Bisaya | 3,000 | 96 |
| Total | 9,000 | 292 |

Each language contains exactly 750 words for each of `minor`, `serious`, `critical`, and `needs_review`.
`needs_review` is an abstention category, not a severity. Words are whitespace-separated tokens in the master `text` column. Metadata and duplicate folder copies are excluded. Reports remain whole; no description was cut or padded to meet the budget.

All 292 rows are fictional drafts with no approved reviewer. They form 93 scenario families after conservative grouping of translations and close variants. Original 80-row files remain separate and unchanged. Source provenance, generation rules, and limitations are in the [generation notes](../../../datasets/emergency_text/generation/README.md).

| Partition | Reports | Families | English | Tagalog | Bisaya |
| --- | ---: | ---: | ---: | ---: | ---: |
| Training | 233 | 71 | 79 | 77 | 77 |
| Validation | 32 | 11 | 11 | 11 | 10 |
| Test | 27 | 11 | 9 | 9 | 9 |

Whole families stay in one partition. Fractions are applied to groups, so row counts differ from 70/15/15 percent. Seed: 42. No exact normalized text or declared scenario group crosses partitions. This does not establish independence of all synthetic phrasing.

Dataset fingerprint: `f34f78c7095ab7207602fb338ae1ef925b610febb3d3eeddabc3066c4889ca59`.

## Training configuration

- Backbone: `google-bert/bert-base-multilingual-cased`, revision `3f076fdb1ab68d5b2880cb87a0886f315b8146f8`.
- Full backbone fine-tuning: all **177,856,516 parameters** trainable.
- Three epochs, CPU, two threads, batch size 1, gradient accumulation 16, learning rate 0.00002, maximum length 128 tokens.
- Longest source description: 76 model tokens; no input truncation.
- Select the checkpoint using validation raw macro-F1 only. Evaluate the test partition after selection.
- Review threshold: 0.65, provisional and uncalibrated. Low scores and the review class produce no severity suggestion.
- Local, cached model and offline Hugging Face settings; no dataset upload or live model activation.

The optional TF-IDF baseline failed with a Windows Application Control `_nd_image` DLL import error. It produced no usable artifact. No security setting was changed. A separate full-BERT load succeeded in the existing environment; its success does not establish why the earlier optional baseline import failed.

## Evidence

- [Preflight counts, splits, and integrity hashes](preflight.json).
- [Training progress log](training.stdout.log) and [training diagnostic log](training.stderr.log).
- [Targeted test output](tests.stderr.log): 47 passed; one optional tiny-model smoke test skipped.
- [Independent candidate reload verification](candidate_verification.json): passed after training exited, using [this verifier](verify_candidate.py).
- Checkpoint output: [text_synthetic_9000_mbert_full_20260920](../../checkpoints/text_synthetic_9000_mbert_full_20260920).
- Full [validation metrics](../../checkpoints/text_synthetic_9000_mbert_full_20260920/validation_metrics.json), [test metrics](../../checkpoints/text_synthetic_9000_mbert_full_20260920/test_metrics.json), and [training history](../../checkpoints/text_synthetic_9000_mbert_full_20260920/training_history.json).

Verification confirmed all 177,856,516 parameters were marked trainable during training; three completed epochs; unchanged source fingerprint; group, case-ID, and exact-text separation; ordinary release-gate rejection of this draft checkpoint; successful experimental reload; matching saved metrics; and null-severity abstention for overlength input, low scores, and the review class. Candidate artifacts, both original 80-row CSVs, `active_config.txt`, Laravel `.env`, and `render.yaml` matched their preflight hashes.

The installed Transformers version printed a Mistral-regex warning while loading this BERT tokenizer. The verifier confirmed `BertTokenizerFast` and compared **all 292 complete token-ID sequences** with the pinned original BERT tokenizer: every sequence matched. No Mistral regex modification was applied. This check is specific to this dataset and tokenizer, not a general dismissal of tokenizer warnings.

A separate read-only generator review reproduced all 292 rows and their fingerprint from the source packs/group map, including after reversing input-file order. It compared whole-description selection with brute-force solutions on 250 small cases. This supplements the automated tests; it is not human approval of the labels.

## Reproduction

Historical note: the commands below record the original experiment. The current
trainer subsequently fixed weighted accumulation and changed checkpoint-selection
defaults/test evaluation behavior. Rerunning current code is a NEW experiment,
not a claim of bit-for-bit reproduction of the old training algorithm. The saved
original checkpoint, CSV fingerprint and metrics remain preserved.

Run from `C:\laragon\www\CAPSTONE-EMERGENCY-SYSTEM-main\ai_service` using a **new** output directory. The original training CSV must still match the fingerprint above. The base model revision must already be cached for offline execution.

```powershell
$env:HF_HUB_OFFLINE = '1'
$env:TRANSFORMERS_OFFLINE = '1'
$env:TOKENIZERS_PARALLELISM = 'false'
& '.\.venv-text\Scripts\python.exe' -u train_text.py train `
  --data datasets/emergency_text/experiments/synthetic_9000words_20260920/reports.csv `
  --output artifacts/checkpoints/text_synthetic_9000_reproduction `
  --base-model google-bert/bert-base-multilingual-cased `
  --revision 3f076fdb1ab68d5b2880cb87a0886f315b8146f8 `
  --allow-draft --unfreeze-backbone --device cpu --threads 2 `
  --epochs 3 --batch-size 1 --gradient-accumulation 16 `
  --max-length 128 --learning-rate 0.00002 --seed 42 `
  --validation-fraction 0.15 --test-fraction 0.15 --review-threshold 0.65
```

## Release and deployment

Completing training does not approve draft labels. Human responder review, more representative permitted reports, and a fresh independent reviewed test set are needed before release. Do not repeatedly tune against this inspected test partition.

For the next review round, copy the master to a separate working CSV and export it to new severity/language folders. Keep the exact source for this experiment unchanged so its fingerprint and results remain reproducible. Correct labels and translations in the new master, record actual reviewers, and train a new candidate from approved rows. Folder copies are browsing views, not extra training records.

The generated descriptions cover road/vehicle accidents only. Repeated synthetic templates, limited misspellings and code-switching, small per-language test sets, and uncalibrated scores limit every reported result. Scores are agreement with draft labels, not real-world emergency accuracy.

Laravel/FastAPI text inference flags and the active configuration are preserved. New civilian reports continue to use description-only assessment with **Needs review** while text inference is disabled; photo attachments remain evidence. No candidate is promoted automatically.

Post-training [localhost checks](local_services.json) returned HTTP 200 for `/admin/login`, `/login`, `/health`, and `/health/text`. The text health endpoint reports `enabled=false` and `model_loaded=false`. Admin and civilian login services remain running locally.

Deployment preparation follows [the root deployment guide](../../../../DEPLOYMENT_RENDER_RAILWAY.md). The Render free demo keeps AI disabled. No checkpoint upload, deployment, or public-route success is claimed by this experiment.
