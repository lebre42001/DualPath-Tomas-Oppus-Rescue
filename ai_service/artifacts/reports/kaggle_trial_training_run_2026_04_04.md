# Kaggle Trial Training Run

Date: 2026-04-04
Run target: `configs/bontoc_southern_leyte_kaggle_trial.yaml`
Location focus: `Bontoc, Southern Leyte, Philippines`

## Purpose

This run evaluates the staged Kaggle accident severity image dataset in isolation before any merge into the main production severity dataset.

## Kaggle Trial Dataset

- Train: `402` images
- Validation: `87` images
- Test: `84` images
- Balanced labels:
  - `minor`
  - `serious`
  - `fatal`

## Source Mapping

- `normal -> minor`
- `moderate -> serious`
- `severe -> fatal`

## Quality Notes

- Sample image size: `300x300`
- Review scan results:
  - no corrupt files
  - no too-small flags
  - only `2` duplicate-flagged images in `serious`

## Results

- Best validation accuracy: `0.7356`
- Test accuracy: `0.7619`
- Test loss: `0.5936`
- Checkpoint:
  - `artifacts/checkpoints/bontoc_severity_kaggle_trial_best.pt`
- Metrics report:
  - `artifacts/reports/bontoc_severity_kaggle_trial_metrics.json`

## Comparison With First External Trial

First low-resolution external trial:

- Best validation accuracy: `0.5185`
- Test accuracy: `0.6296`
- Source image size: `28x28`

Kaggle trial improvement:

- better image quality
- larger balanced subset
- stronger validation and test accuracy

## Recommendation

This Kaggle trial is a much stronger external candidate than the low-resolution public trial.

Still, the safest next step is:

1. keep it isolated for now
2. compare predictions qualitatively against local Bontoc images
3. only then consider a controlled merge into the main production manifests
