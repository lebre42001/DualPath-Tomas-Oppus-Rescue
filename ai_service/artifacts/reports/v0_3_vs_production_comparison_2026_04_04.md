# v0.3 vs Current Production Comparison

Date: 2026-04-04

## Compared Models

- Current production external-only baseline:
  - config: `configs/bontoc_southern_leyte_production_candidate_external.yaml`
  - local validation report: `datasets/bontoc_southern_leyte/local_validation/reports/production_candidate_local_eval_7img.md`
  - benchmark comparison note: `artifacts/reports/production_candidate_comparison_2026_04_04.md`
- Latest v0.3 candidate:
  - config: `configs/bontoc_southern_leyte_v0_3_candidate_template.yaml`
  - training metrics: `artifacts/reports/bontoc_severity_v0_3_candidate_metrics.json`
  - local validation report: `datasets/bontoc_southern_leyte/local_validation/reports/v0_3_candidate_local_eval.md`

## Current Dataset Snapshot

- reviewed pool approved rows: `18`
- latest split sizes:
  - train: `11`
  - val: `3`
  - test: `4`
- latest training label distribution:
  - train: `3 minor`, `5 serious`, `3 fatal`
  - val: `1 minor`, `1 serious`, `1 fatal`
  - test: `1 minor`, `2 serious`, `1 fatal`

## Benchmark Metrics

### Current Production External Baseline

- best validation accuracy: `0.7356`
- test accuracy: `0.7619`
- test loss: `0.5936`

### Latest v0.3 Candidate

- best validation accuracy: `0.6667`
- test accuracy: `0.7500`
- test loss: `1.3011`

## Local Validation on the Same 7 Held-Out Images

### Current Production External Baseline

- overall accuracy: `0.1429`
- review-required predictions: `1`
- per-class:
  - minor: `0.0000`
  - serious: `0.0000`
  - fatal: `1.0000`
- scenario slices:
  - motorcycle: `0.0000`
  - multi_vehicle: `0.2000`
  - night_or_low_light: `0.5000`
  - rural_scene: `0.0000`

### Latest v0.3 Candidate

- overall accuracy: `0.2857`
- review-required predictions: `1`
- per-class:
  - minor: `1.0000`
  - serious: `0.0000`
  - fatal: `0.0000`
- scenario slices:
  - motorcycle: `0.0000`
  - multi_vehicle: `0.2000`
  - night_or_low_light: `0.0000`
  - rural_scene: `0.0000`

## Interpretation

- The latest v0.3 candidate is still better than the current production model on this tiny held-out local set overall.
- The new serious and fatal seed additions improved the v0.3 candidate's internal train/test benchmark stability.
- But the local held-out behavior is still not trustworthy enough for a live model switch.
- The current v0.3 candidate now overfits toward the current small reviewed pool and lost the earlier `serious` local advantage.

## Safe Conclusion

Do not switch the live AI model yet.

Keep the current production external baseline active while we collect more reviewed local or Philippines-like images, especially for:

- motorcycle crashes
- serious road scenes with responders present
- fatal scenes with clearer road-context cues
- night or low-light incidents
- rural or barangay-road conditions

## Next Best Move

1. Add more reviewed `serious` and `fatal` Philippines-like road scenes with better road-context diversity.
2. Expand the held-out `local_validation` set so promotion decisions are not based on only `7` images.
3. Re-run `v0-3-full` after the reviewed pool reaches a more balanced and less tiny size.
