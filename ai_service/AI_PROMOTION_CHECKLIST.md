# AI Promotion Checklist

Use this checklist before switching the live AI service to a new checkpoint.

## Candidate Identity

- [ ] New checkpoint path is recorded
- [ ] New config path is recorded
- [ ] Metrics report is saved in `artifacts/reports/`
- [ ] Local validation report is saved in `datasets/bontoc_southern_leyte/local_validation/reports/`

## Dataset Hygiene

- [ ] No `local_validation` image is reused in `reviewed_pool`, `train`, `val`, or `test`
- [ ] Public-source provenance is logged in the matching `source_batches/` or manifest CSV
- [ ] `reviewed_pool` contains balanced `minor`, `serious`, and `fatal` examples
- [ ] The split summary confirms every class is present in train, val, and test when feasible

## Training Metrics

- [ ] Validation accuracy is not collapsing across epochs
- [ ] Test accuracy is not dramatically worse than the current live baseline
- [ ] No class is completely missing from the training split
- [ ] The model is not obviously overfitting tiny data without any local-validation gain

## Local Validation Requirements

- [ ] Overall local-validation accuracy is better than or meaningfully equal to the current live model
- [ ] `fatal` local-validation performance does not regress badly
- [ ] `minor` local-validation performance does not regress badly
- [ ] `serious` local-validation performance is stable or improved
- [ ] Motorcycle slice is stable or improved
- [ ] Multi-vehicle slice is stable or improved
- [ ] Night or low-light slice is stable or improved
- [ ] Rural or barangay-road slice is stable or improved

## Safety Review

- [ ] Review-required prediction rate is acceptable for responder workflow
- [ ] Confusion matrix does not show dangerous collapse into one class
- [ ] Fatal incidents are not being hidden as `minor`
- [ ] Low-damage scenes are not being over-promoted to `fatal` too often
- [ ] A responder or project owner has reviewed the comparison note

## Deployment Decision

Promote only if the answer is `yes` to all critical items below:

- [ ] Better or safer on local validation
- [ ] No serious `fatal` regression
- [ ] No serious `minor` regression
- [ ] Metrics and artifacts are fully documented

If any critical item is `no`, keep the current live model and collect more reviewed data first.
