# Dataset organization and corrected full-training workflow

The working master is [working/reports.csv](../../../datasets/emergency_text/working/reports.csv). Original data moved into `archive/initial_80` and `experiments/synthetic_9000words_20260920`; [the migration receipt](migration.json) verifies the bytes of all 49 moved files before documentation updates. Training-source CSVs and model artifacts are preserved.

## Implemented

- One working master and generated severity/language browsing copies; defaults updated.
- 400 draft descriptions: 292 preserved rows, 68 previously unused seed translations, 40 new code-switching variants. 99 declared families; no human approvals and no new real reports.
- Source-fingerprinted review worksheet, explicit accountable decision import to a new CSV, draft audit, immutable split export and prior-experiment overlap checks.
- Correct weighted-loss normalization across the complete gradient-accumulation window, including partial final windows. Gradient-equivalence tests cover batch sizes 1/2/3 and unequal weights.
- Validation-only training reports by default, frozen split reuse, early stopping, and checkpoint selection by critical recall followed by macro-F1.
- Validation-only threshold sweep that changes no model/config files; offline shadow comparison with explicit experimental/historical switches.

The previous batch-size-1 weighted mean canceled per-class weights. This does not establish the cause of poor critical detection; critical was already the largest training class, and class balancing is not a substitute for representative data.

## Corrected diagnostic run

The run uses the unchanged 292-row historical source and its exact previous group split to isolate training changes. It does **not** train on the expanded working copy or claim new independent holdout evidence. Settings: pinned mBERT revision `3f076fdb1ab68d5b2880cb87a0886f315b8146f8`, all 177,856,516 parameters trainable, CPU/two threads, learning rate 0.00002, batch 1, accumulation 16, length 128, three maximum epochs, early-stopping patience 2, validation critical recall then macro-F1 selection.

**Completed successfully:** three full-backbone epochs in **700.67 seconds (11 minutes 40.67 seconds)**. Epoch 3 was selected. No epoch recognized any validation critical case, so macro-F1 broke the zero-recall tie. This candidate fails the intended critical-detection objective and remains inactive.

| Partition | Raw correct | Raw macro-F1 | Critical recall | Severity coverage at 0.65 |
| --- | ---: | ---: | ---: | ---: |
| Validation | 21/32 (65.63%) | 0.5568 | 0/6 | 6/32 (18.75%) |
| Historical test, after selection | 19/27 (70.37%) | 0.6461 | 1/5 | 4/27 (14.81%) |

The 27-row test is already exposed historical data, not independent release evidence. The original run matched 20/27 labels; the corrected run matched 19/27. Fixing the training objective did not establish an accuracy improvement. Three critical test cases were classified below critical before abstention; one required review. At the threshold, four suggestions matched their draft labels and 23 examples were routed to review. All experimental outputs still require responder verification; 4/4 accepted draft matches is not proof of reliable performance.

| Historical test language | Raw correct | Critical recall |
| --- | ---: | ---: |
| English | 5/9 | 0/1 |
| Tagalog | 7/9 | 0/2 |
| Bisaya | 7/9 | 1/2 |

There are no mixed-language test rows in this historical experiment. The 40 new mixed drafts are prepared for human review, not represented by these scores.

Evidence: [run receipt](run_status.json), [validation metrics](../../checkpoints/text_weighted_full_20260920/validation_metrics.json), [independent checkpoint reload and historical comparison](historical_shadow/shadow_comparison.json), [validation-only threshold analysis](thresholds/threshold_analysis.json), [stdout](training.stdout.log), [stderr](training.stderr.log). Candidate: [text_weighted_full_20260920](../../checkpoints/text_weighted_full_20260920).

## Verification

**60 tests passed, zero skipped**, including an offline tiny-BERT train/save/reload round trip, forced early stopping, loss/gradient equivalence for partial windows, split integrity, stale-review rejection, and refusal to score test data during default training. See [test results](tests.stderr.log).

[Final integrity checks](integrity_verification.json) verified 43 moved CSV files byte-for-byte, unchanged original 292 rows inside the working master, 400 blank review decisions, draft training/release gates, full-backbone metadata and exact reuse of the original split. Laravel `.env`, `active_config.txt` and `render.yaml` match their original hashes. All three sets of generated folder views passed `--check`; local documentation links were checked after relocation.

## Remaining dependencies

The user confirmed no responder/language reviewer is available yet. No actual human labels, independent reviewed holdout, fitted/validated calibration, responder acceptance, or production-ready model is claimed. The 1,200-reviewed-description target remains a planning milestone. The supplied worksheet makes the next human review concrete; no approval is fabricated.

Text AI remains inactive; report submission retains Needs review when assessment is unavailable. Photo evidence remains unchanged. [Post-training localhost checks](local_services.json) returned HTTP 200 for admin login, civilian login and `/health/text`; the text service reports disabled/no loaded model. The Render profile remains AI-disabled; no deployment is performed. Docker excludes local `artifacts/checkpoints/text_*` candidates from implicit image copying.
