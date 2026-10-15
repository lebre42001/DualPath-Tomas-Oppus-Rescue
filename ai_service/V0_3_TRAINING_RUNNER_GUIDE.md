# v0.3 Training Runner Guide

Use this guide when you already have enough `approved` images in:

- `datasets/bontoc_southern_leyte/v0_3_candidate/reviewed_pool/`

and you are ready to build the split and train the next model candidate.

## Goal

Run the full `v0.3` preparation flow in the correct order:

1. verify local validation results
2. build the `v0.3` split
3. train the candidate model
4. review the metrics
5. compare against the current baseline

## Before You Start

Confirm these first:

- `local_validation/` has been evaluated
- the weak scenario slices are already known
- the reviewed pool manifest has enough approved rows
- local validation images are still kept separate

## Step 1: Go to the AI service folder

```powershell
cd "C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch\ai_service"
```

## Step 2: Build the v0.3 candidate split

```powershell
C:\laragon\bin\python\python-3.13\python.exe tools\build_v0_3_candidate_split.py
```

This will:

- read the approved reviewed-pool rows
- copy images into:
  - `v0_3_candidate/images/train`
  - `v0_3_candidate/images/val`
  - `v0_3_candidate/images/test`
- rewrite the train/val/test manifest templates
- generate:
  - `v0_3_candidate/reports/v0_3_candidate_split_summary.md`

## Step 3: Review the split summary

Check:

- class balance
- total images per split
- whether the approved images were copied successfully

Open:

- `datasets/bontoc_southern_leyte/v0_3_candidate/reports/v0_3_candidate_split_summary.md`

## Step 4: Train the v0.3 candidate model

```powershell
C:\laragon\bin\python\python-3.13\python.exe train.py --config configs\bontoc_southern_leyte_v0_3_candidate_template.yaml
```

This writes:

- checkpoint:
  - `artifacts/checkpoints/bontoc_severity_v0_3_candidate_best.pt`
- metrics:
  - `artifacts/reports/bontoc_severity_v0_3_candidate_metrics.json`

## Step 5: Review the training output

Check:

- validation accuracy
- test accuracy
- whether one class collapsed
- whether the result is better than the current baseline

## Step 6: Run local validation again

After training, test the new candidate against the local validation set:

```powershell
C:\laragon\bin\python\python-3.13\python.exe tools\evaluate_local_validation.py --config configs\bontoc_southern_leyte_v0_3_candidate_template.yaml --output-prefix v0_3_candidate_local_eval
```

This tells you whether the new candidate actually improved on:

- motorcycles
- night or low-light scenes
- rain or wet-road scenes
- rural or barangay roads
- multi-vehicle incidents

## Step 7: Promotion check

Promote the new model only if:

- overall metrics are stable or better
- local validation improves on weak slices
- fatal recall does not regress badly
- low-confidence review rate remains acceptable

## Recommended Comparison Files

Current baseline:

- `artifacts/checkpoints/bontoc_severity_production_candidate_external_best.pt`
- `artifacts/reports/bontoc_severity_production_candidate_external_metrics.json`

New candidate:

- `artifacts/checkpoints/bontoc_severity_v0_3_candidate_best.pt`
- `artifacts/reports/bontoc_severity_v0_3_candidate_metrics.json`

## Important Safety Rule

Do not switch the live AI service to the `v0.3` checkpoint until:

- the training metrics look good
- the local validation report also looks good
- responder review agrees that the outputs are safer or better
