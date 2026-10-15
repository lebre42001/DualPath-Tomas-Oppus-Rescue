# Controlled Merge Plan

## Goal

Promote only reviewed and approved external images into a production-candidate dataset without mixing in:

- old Bontoc sample uploads
- old civilian sample uploads
- old uploaded sample videos
- unreviewed staged images

## Current Safe Basis

The strongest reviewed external source right now is the Kaggle severity dataset trial:

- isolated Kaggle trial config:
  - `configs/bontoc_southern_leyte_kaggle_trial.yaml`
- isolated Kaggle trial summary:
  - `artifacts/reports/kaggle_trial_training_run_2026_04_04.md`

## Promotion Rules

An external source is allowed into the production-candidate basis only if it passes all of the following:

1. Downloaded into `datasets/external_image_sources/`
2. Mapped into `minor`, `serious`, and `fatal`
3. Reviewed with `tools/review_staging_dataset.py`
4. Deduplicated through the isolated trial subset process
5. Trained in isolation with a better result than the low-resolution baseline
6. Not mixed with local sample-only uploads

## Current Approved External Candidate

The current approved external production candidate is:

- Kaggle `Accident Severity Image Dataset v4`
- local staging mapping:
  - `normal -> minor`
  - `moderate -> serious`
  - `severe -> fatal`

## Production-Candidate Dataset Policy

The production-candidate dataset is still not the final production merge.

It is a cleaner intermediate basis that:

- includes only approved external data
- excludes old local sample uploads
- uses dedicated manifests and config
- stays reversible and non-destructive

## Files Created For This Stage

- production-candidate config:
  - `configs/bontoc_southern_leyte_production_candidate_external.yaml`
- production-candidate manifests:
  - `metadata/train_production_candidate_external_approved_kaggle_300.csv`
  - `metadata/val_production_candidate_external_approved_kaggle_300.csv`
  - `metadata/test_production_candidate_external_approved_kaggle_300.csv`
- helper:
  - `tools/build_production_candidate_from_trial.py`

## Recommended Next Use

1. Train and validate using the production-candidate config
2. Run qualitative prediction checks on fresh unseen accident photos
3. Only after that, decide whether to copy those manifests into the main production training config

## Do Not Do Yet

- do not overwrite the empty standard local manifests
- do not mix old sample uploads back into the training basis
- do not claim final production accuracy from external-only trial performance
