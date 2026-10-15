# Production Candidate Comparison

Date: 2026-04-04

## Compared Runs

- Kaggle isolated trial:
  - `artifacts/reports/bontoc_severity_kaggle_trial_metrics.json`
- Production-candidate external run:
  - `artifacts/reports/bontoc_severity_production_candidate_external_metrics.json`

## Result

The metrics and training history match exactly.

## Matching Values

- best validation accuracy: `0.735632183908046`
- test accuracy: `0.7619047619047619`
- test loss: `0.5935798149023738`
- epoch history: exact match

## Interpretation

This is a good sign.

It means the production-candidate manifests are a clean promotion of the approved Kaggle trial basis, without hidden data drift or accidental label changes.

## Safe Conclusion

The current production-candidate config is stable enough to use as the active external-only training baseline while the standard local manifests remain empty.
