# External Trial Training Run

Date: 2026-04-04
Run target: `configs/bontoc_southern_leyte_external_trial.yaml`
Location focus: `Bontoc, Southern Leyte, Philippines`

## Purpose

This run validates the low-resolution external trial subset in isolation before any merge into the main production dataset.

## Trial Dataset

- Train: `126` images
- Validation: `27` images
- Test: `27` images
- Labels:
  - `minor`: balanced
  - `serious`: balanced
  - `fatal`: balanced

## Model Setup

- Architecture: `resnet18`
- Pretrained: `true`
- Batch size: `8`
- Epochs: `10`
- Learning rate: `0.0003`
- Weight decay: `0.0001`

## Results

- Best validation accuracy: `0.5185`
- Test accuracy: `0.6296`
- Test loss: `1.4164`
- Checkpoint:
  - `artifacts/checkpoints/bontoc_severity_external_trial_best.pt`
- Metrics report:
  - `artifacts/reports/bontoc_severity_external_trial_metrics.json`

## Interpretation

This run is useful as a pipeline validation and as a benchmark for low-resolution external data, but it is not strong enough for production severity inference.

Key reasons:

- The source images are only `28x28`
- Fine-grained crash severity cues are lost at that resolution
- The dataset is external and not yet aligned to Bontoc-specific capture conditions

## Recommendation

Do not merge this low-resolution external trial subset into the main production manifests yet.

Use it only for:

- controlled experiments
- architecture comparisons
- data pipeline validation

The next safer move is to add a higher-resolution public image dataset and keep it in staging until quality review is complete.
