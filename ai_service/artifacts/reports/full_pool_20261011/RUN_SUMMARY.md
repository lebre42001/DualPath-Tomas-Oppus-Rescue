# Emergency text full-pool training ? 2026-10-11

**Status: completed offline candidate; not approved for deployment. Active model unchanged.**

## Dataset

- Unique labeled rows: **1366** across **1021** groups; text-deduplicated across canonical sources.
- Human-confirmed/responder-reconciled approved rows: **850**.
- Draft rows included under explicit `--allow-draft` diagnostic mode: **516**. These are not treated as human-confirmed.
- Labels: `{"minor": 342, "serious": 343, "critical": 340, "needs_review": 341}`. Languages: `{"ceb": 416, "en": 390, "mixed": 180, "tl": 380}`.
- See `datasets/emergency_text/experiments/full_pool_20261011/source_manifest.json` for sources, hashes, and exclusions.

The duplicate 850-row exports and duplicate 400/292-row experiments were counted once. Review sheets, folder views, and train/validation/test/challenge/calibration exports were not concatenated as extra rows. `short_panic_v1` proposed labels were excluded because they are still not final human labels.

## Training

- Model: `FacebookAI/xlm-roberta-base` at pinned revision `42f548f32366559214515ec137cdd16002968bf6`; full backbone fine-tuned on CUDA, seed 42, best epoch 8.
- Current split: 954 train / 212 validation / 200 test; split is group/text-disjoint for this run.
- Validation checkpoint selection: `balanced_safety`.

## Results

| Metric | Validation | Test diagnostic |
|---|---:|---:|
| Macro-F1 | 0.918 | 0.945 |
| Scored accuracy | 0.918 | 0.947 |
| Critical recall | 0.893 | 0.895 |
| Serious recall | 0.935 | 0.939 |
| Raw serious/critical under-triage | 2 | 2 |
| Threshold-routed serious/critical under-triage | 0 | 2 |
| Coverage at provisional 0.65 threshold | 64.2% | 69.0% |

Test per-language macro-F1: `{"en": 0.915, "tl": 0.95, "ceb": 0.955, "mixed": 0.969}`.

**Safety gates failed:** test critical recall is 0.895 (target ?0.95); there are 2 raw serious/critical under-triage and 2 after threshold routing. Threshold routing does not eliminate the observed misses.

## Interpretation and next step

The test set was held out from this run's optimizer, but all source datasets have prior model-development exposure and most added rows are synthetic drafts. Treat the score as a diagnostic for this dataset mix, **not real-world accuracy**. Keep this checkpoint inactive and keep responder severity assessment authoritative. Next, have responders independently review the under-triaged test cases, then collect a new de-identified real-incident test set that is kept out of training and threshold tuning.

Checkpoint: `artifacts/checkpoints/full_pool_20261011/xlmr_seed_42`  
Test metrics: `artifacts/reports/full_pool_20261011/test_evaluation/evaluation_metrics.json`  
Machine summary: `artifacts/reports/full_pool_20261011/RUN_SUMMARY.json`
