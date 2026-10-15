# v0.3 Retraining Plan

This plan defines the next training milestone after the current `external-only` production candidate baseline.

## Goal

Train a stronger `v0.3` severity model with better coverage for:

- Philippine-like roads
- motorcycles
- multi-vehicle crashes
- nighttime and rain conditions
- rural and barangay emergency scenes

## Why v0.3 Is Needed

The current baseline is a good starting point, but it is still mainly built from approved external images.

That means we still need stronger support for:

- local visual context
- harder edge cases
- lower-light phone captures
- motorcycle-heavy incidents
- rural Southern Leyte scenes

## Current Baseline

- active config:
  - `configs/bontoc_southern_leyte_production_candidate_external.yaml`
- active checkpoint:
  - `artifacts/checkpoints/bontoc_severity_production_candidate_external_best.pt`
- baseline performance:
  - validation accuracy: `73.56%`
  - test accuracy: `76.19%`

## v0.3 Data Priorities

### Priority 1

- motorcycle-only crashes
- motorcycle versus car
- motorcycle versus truck or van
- tricycle incidents

### Priority 2

- nighttime scenes
- low-light phone captures
- rain or wet-road incidents

### Priority 3

- rural roads
- barangay roads
- mountain or curved roads
- obstruction and roadside ditch scenes

### Priority 4

- multi-vehicle crashes
- chain collisions
- partial-view and ambiguous scenes

## Minimum Data Targets

Before starting `v0.3`, aim for:

- `40` to `60` reviewed local validation images per class
- at least `20+` motorcycle-related images
- at least `15+` night or low-light images
- at least `15+` rain or wet-road images
- at least `20+` rural or barangay-road images
- at least `15+` multi-vehicle images

These categories can overlap.

## Required Evaluation Gate

Before retraining:

1. fill the `local_validation/` manifest
2. run the local validation evaluator on the current model
3. identify the weakest slices
4. collect targeted images for those weak slices

## Suggested Training Inputs For v0.3

Use a controlled mix of:

- approved Kaggle external set
- any newly approved higher-resolution public sources
- carefully reviewed local training data

Do not use:

- old sample uploads that were already marked as non-training references
- unclear or weakly labeled images
- local validation-only images

## Training Sequence

1. keep `local_validation/` held out
2. collect and review new local candidate images
3. build a reviewed `local_train_candidate` pool
4. re-balance class counts
5. retrain with an updated config
6. compare against the current production baseline
7. run local validation again
8. promote only if local slices improve without major regression

## Acceptance Criteria For Promotion

Only promote `v0.3` if:

- overall validation improves or stays stable
- local validation improves on motorcycles
- local validation improves on night or rain scenes
- local validation improves on rural or barangay roads
- fatal recall does not drop sharply
- low-confidence review rate is acceptable

## Deliverables For v0.3

- new reviewed manifests
- training report
- comparison report versus current baseline
- local validation report
- updated active checkpoint if promotion is approved

## Recommended Immediate Next Steps

1. fill the new `local_validation/` folders and manifest
2. run `tools/evaluate_local_validation.py`
3. list the weakest scenario slices
4. collect additional targeted images for those slices
5. prepare a `v0.3` training candidate split
