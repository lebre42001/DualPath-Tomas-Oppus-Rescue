# AI Severity Service

## Service-wide inference switch

The FastAPI service defaults to `AI_OPERATION_MODE=MANUAL_ONLY`. In this mode, image and text prediction routes, readiness checks, warmup, startup model preloading, and startup checkpoint synchronization are disabled. `/health` reports that inference is disabled without loading model weights; `/ready` returns 503 because AI readiness is intentionally unavailable. Deployments that keep the service running in manual-only mode should use `/health` as the liveness check. Legacy flags such as `AI_TEXT_ENABLED=true`, preload settings, or checkpoint files cannot override this mode. Enable model work only by explicitly setting `AI_OPERATION_MODE=AI_ENABLED`; text inference still additionally requires `AI_TEXT_ENABLED=true` and a valid reviewed checkpoint.

This folder contains text-classifier training/inference and archived image-model experiments for the Stitch emergency system.

**Current application workflow:** new web/API severity suggestions use the emergency description only. Photos remain supporting evidence for responders. Laravel no longer calls image severity/relevance prediction, and stale image jobs cannot update reports. Text inference remains disabled pending a reviewed model; reports are accepted as **Needs review** meanwhile. Existing model artifacts and historical assessments are preserved.

## Description-only multilingual text AI

Latest responder-review reconciliation and candidate experiment (2026-10-07):
the 850 supplied decisions matched the existing 850 case IDs, text, language,
family IDs, and labels exactly, so no extra training rows or label changes were
made. A separate reconciled dataset version and frozen split are preserved under
`datasets/emergency_text/reviewed/civilian_multilingual_850_responder_reconciled_20261007/`.
The one-seed full-backbone XLM-R candidate remains experimental and failed the
predefined test gates (macro-F1 0.579, Critical recall 93.9%, 3 serious/critical
under-triage cases, and 0% routed coverage). It was not activated. This test set
has prior model-development exposure and the source descriptions are synthetic;
it is not independent external validation. See
`artifacts/reports/xlmr_responder_reconciled_20261007/EXPERIMENT_EVALUATION.md`.

**Current editing starts in [working](datasets/emergency_text/working/README.md).**
It contains 400 synthetic draft descriptions and a pending human-review worksheet.
Historical datasets are separated into `experiments/` and `archive/initial_80/`.
`organize_text.py` and default training/validation now point to the working master.
See [the corrected workflow report](artifacts/reports/text_workflow_20260920/README.md)
for weighted accumulation, frozen splits, validation-only tuning and review tools.

The separate text workflow is documented in [TEXT_AI_WORKFLOW.md](TEXT_AI_WORKFLOW.md).
It covers English, Tagalog, Cebuano, and mixed-language road/vehicle accident reports,
draft annotation review, a TF-IDF baseline, and mBERT classifier training/evaluation.
The text feature is disabled by default. No text checkpoint has been validated for dispatch.
The [September 20 synthetic pack](datasets/emergency_text/experiments/synthetic_9000words_20260920/README.md)
contains exactly 3,000 description words per English, Tagalog and Bisaya (292 reports total),
organized into severity/language folders. All labels remain drafts; original data is preserved.
Its [full-backbone training and verification report](artifacts/reports/text_synthetic_9000words_20260920/README.md)
records three completed epochs and 20/27 raw draft-label matches. Only 1/5 critical test
cases was recognized; all test cases required review at the threshold. The candidate is inactive.
To browse current text examples by label, open the [working severity folder index](datasets/emergency_text/working/by_severity/README.md).
Edit the `working/reports.csv` master and run `python organize_text.py` to refresh those copies;
`python organize_text.py --check` verifies that they still match. No training is started.
Three-epoch head-only and full-backbone mBERT experiments are complete; see the
[September 19 experimental training report](artifacts/reports/text_mbert_20260919/README.md) for results and limitations.
The existing `active_config.txt` and image model are unchanged; the image scope below
does not describe the separate text classifier. New routes are `/health/text`,
`/ready/text`, and `POST /predict/text`.

## Archived image-model scope

**Dataset retirement (2026-10-01):** `datasets/` now contains only
`emergency_text/`. At the user's request, `bontoc_southern_leyte/`,
`external_image_sources/`, and `external_tabular_sources/` were removed.
The image/tabular collection and training instructions below are historical,
not runnable instructions for the current dataset tree. Retained image configs
and scripts may reference these removed paths; this cleanup does not switch
models or remove application photo evidence. Use [TEXT_AI_WORKFLOW.md](TEXT_AI_WORKFLOW.md)
for current training. The removal inventory is recorded in
[the cleanup receipt](artifacts/reports/dataset_cleanup_20261001/cleanup_receipt.json).

The model is designed to support, not replace, responder judgment.

- Target prediction: `minor`, `serious`, or `fatal`
- Primary input: incident photo
- Optional context for later versions: description, location, transport mode, and weather
- Operational focus: Bontoc, Southern Leyte road and emergency conditions

## Archived image training approach

The retained image experiments used a two-stage server-side training workflow:

1. photo relevance gate: `related` vs `unrelated`
2. severity classifier: `minor`, `serious`, `fatal`

The implementation supports `resnet18`, `mobilenet_v3_small`, and `efficientnet_b0` transfer learning. See `RECOMMENDED_AI_TRAINING_WORKFLOW.md` for the exact workflow, dummy-photo evaluation, and promotion checklist.

## Project Layout

```text
ai_service/
  artifacts/
    checkpoints/
    reports/
  configs/
    bontoc_southern_leyte_severity.yaml
  datasets/
    bontoc_southern_leyte/
      annotations/
        class_map.yaml
        labeling_guide.md
        manifest_schema.md
      import_staging/
        README.md
      local_validation/
        README.md
        checklists/
          DATASET_ACQUISITION_CHECKLIST.md
          LOCAL_VALIDATION_CAPTURE_PLAN.md
        manifests/
          local_validation_manifest_template.csv
          scenario_coverage_template.csv
        review/
          local_validation_review_template.csv
      v0_3_candidate/
        README.md
        reviewed_pool/
          minor/
          serious/
          fatal/
        images/
          train/
            minor/
            serious/
            fatal/
          val/
            minor/
            serious/
            fatal/
          test/
            minor/
            serious/
            fatal/
        manifests/
          reviewed_pool_manifest_template.csv
          train_v0_3_candidate_template.csv
          val_v0_3_candidate_template.csv
          test_v0_3_candidate_template.csv
          scenario_balance_tracker.csv
        review/
          v0_3_candidate_review_template.csv
      curated/
        images/
          train/
            minor/
            serious/
            fatal/
          val/
            minor/
            serious/
            fatal/
          test/
            minor/
            serious/
            fatal/
      metadata/
        train_template.csv
        val_template.csv
        test_template.csv
      qa/
        review_log_template.csv
        external_import_review_accident_images_analysis_dataset.csv
      raw/
        road_accident/
        general_accident/
        emergency_scene/
      unlabeled/
        inbox/
      README.md
      dataset_card.md
    external_tabular_sources/
      CATALOG.md
      LABEL_MAPPING.md
      archives/
      extracted/
    external_image_sources/
      ACCIDENT_IMAGES_ANALYSIS_DATASET.md
      archives/
      extracted/
  src/
    ai_service/
      __init__.py
      api.py
      config.py
      dataset.py
      inference.py
      labels.py
      model.py
      trainer.py
  infer.py
  requirements.txt
  serve_api.py
  train.py
```

## Dataset Flow

1. Place newly collected images in `datasets/bontoc_southern_leyte/unlabeled/inbox/`
2. Move originals into the correct `raw/` source domain folder
3. Review and label using `annotations/labeling_guide.md`
4. Copy approved images into the matching `curated/images/{split}/{severity}/` folder
5. Fill in the matching manifest CSV inside `metadata/`
6. Train the model using the config file
7. Review validation and test metrics before using the model in the app

Important:

- old Bontoc/civilian uploaded sample images and sample videos are no longer part of the active training basis
- the standard local `train_template.csv`, `val_template.csv`, and `test_template.csv` are intentionally empty until a reviewed local dataset is prepared
- use isolated external trial configs for experiments until a proper approved local set exists
- keep the new `local_validation/` folder separate from training so we can measure true Bontoc/Southern Leyte performance honestly

## Supporting Tabular Sources

Imported CSV-based accident datasets that are useful for analytics and feature engineering should be stored in:

```text
datasets/external_tabular_sources/
```

These are supporting structured-data sources, not replacements for the photo image dataset used by the current AI severity model.

## Supporting External Image Sources

Public image datasets downloaded from online sources should be stored in:

```text
datasets/external_image_sources/
```

These can be reviewed and mapped into the local `minor`, `serious`, and `fatal` labels before any curated merge into the Bontoc training dataset.

## Labels

- `minor`
- `serious`
- `fatal`

Use the exact lowercase labels above so the training service and manifests stay consistent.

## Source Domains

- `road_accident`
- `general_accident`
- `emergency_scene`

These source domains help us track where the image came from even when the training target is still severity.

## Bontoc, Southern Leyte Notes

When collecting or labeling data, pay attention to local conditions that can affect visual severity cues:

- barangay roads and mountain roads
- wet or muddy surfaces after rain
- motorcycle and tricycle crashes
- jeepney, van, truck, and bus collisions
- landslide, obstruction, and flood-related emergency scenes
- low-light captures from phones during night incidents

## Local Validation-Only Set

Use this folder for `held-out` Bontoc and Southern Leyte evaluation images:

```text
datasets/bontoc_southern_leyte/local_validation/
```

This set should not be used for training.

It exists to answer:

- how well the current model works on local roads
- whether motorcycles cause systematic mistakes
- whether night or rain scenes are underperforming
- whether rural emergency scenes are being misread

Use these files first:

- `local_validation/README.md`
- `local_validation/checklists/DATASET_ACQUISITION_CHECKLIST.md`
- `local_validation/checklists/LOCAL_VALIDATION_CAPTURE_PLAN.md`
- `local_validation/LOCAL_VALIDATION_RUNNER_GUIDE.md`
- `local_validation/manifests/local_validation_manifest_template.csv`
- `local_validation/manifests/wikimedia_commons_public_seed_manifest_2026_04_04.csv`
- `local_validation/manifests/scenario_coverage_template.csv`
- `local_validation/review/local_validation_review_template.csv`

## Active Default Config

The current active default config now points to the cleaned external production-candidate model:

```text
configs/bontoc_southern_leyte_production_candidate_external.yaml
```

This affects:

- the FastAPI inference service
- `train.py` default behavior
- `infer.py` default behavior

## Training

Inside `ai_service/`:

```bash
pip install -r requirements.txt
python train.py
```

This saves:

- checkpoint files to `artifacts/checkpoints/`
- metrics reports to `artifacts/reports/`

## Hosted Checkpoint Sync

Online deployments can load trained `.pt` files at startup using env-provided URLs. This is useful for Render because AI checkpoints are large runtime artifacts.

See the repo-level guide:

```text
../AI_CHECKPOINT_SYNC_GUIDE.md
```

Key env variables:

- `AI_SEVERITY_CHECKPOINT_URL`
- `AI_SEVERITY_CHECKPOINT_SHA256`
- `AI_PHOTO_RELEVANCE_CHECKPOINT_URL`
- `AI_PHOTO_RELEVANCE_CHECKPOINT_SHA256`

If the two active checkpoints are already included in the Docker image for a temporary demo build, these URL env variables can stay blank.

## Local Validation Evaluator

Use this after filling the local validation manifest:

```bash
C:\laragon\bin\python\python-3.13\python.exe tools\evaluate_local_validation.py
```

This generates:

- a per-image CSV report inside `datasets/bontoc_southern_leyte/local_validation/reports/`
- a markdown summary with:
  - overall accuracy
  - per-class accuracy
  - confusion matrix
  - scenario slice accuracy for motorcycle, rain, night, rural, and multi-vehicle cases

Use this to measure real `Bontoc / Southern Leyte` behavior before retraining.

## v0.3 Planning

The next retraining milestone is documented here:

```text
V0_3_RETRAINING_PLAN.md
```

The command-by-command runner guide is here:

```text
V0_3_TRAINING_RUNNER_GUIDE.md
```

Promotion safety checklist:

```text
AI_PROMOTION_CHECKLIST.md
```

Active config pointer:

```text
active_config.txt
```

That plan focuses on:

- motorcycles
- night or low-light scenes
- rain or wet roads
- rural and barangay road incidents
- multi-vehicle crash coverage

## v0.3 Training Candidate Structure

The staging structure for the next retraining cycle is ready here:

```text
datasets/bontoc_southern_leyte/v0_3_candidate/
```

Use these files first:

- `v0_3_candidate/README.md`
- `v0_3_candidate/manifests/reviewed_pool_manifest_template.csv`
- `v0_3_candidate/source_batches/wikimedia_commons_seed_batch_2026_04_04.csv`
- `v0_3_candidate/manifests/scenario_balance_tracker.csv`
- `v0_3_candidate/review/v0_3_candidate_review_template.csv`
- `configs/bontoc_southern_leyte_v0_3_candidate_template.yaml`
- `tools/build_v0_3_candidate_split.py`
- `V0_3_TRAINING_RUNNER_GUIDE.md`

Recommended flow:

1. review new candidate images into `reviewed_pool/`
2. fill the reviewed pool manifest
3. track scenario coverage
4. build the final train/val/test split with `tools/build_v0_3_candidate_split.py`
5. switch the config from template manifests to the approved final manifests

Starter note:

- a small public Wikimedia Commons seed batch is now present in `local_validation/` and `reviewed_pool/`
- these are useful for bootstrapping the workflow, but they are still non-local reference images
- keep prioritizing Philippine and Bontoc-like scenes for future validation and v0.3 data collection

## One-Click AI Workflow Helper

From the project root:

```bash
pwsh -File .\tool\run_ai_workflow.ps1 -Mode local-validation
pwsh -File .\tool\run_ai_workflow.ps1 -Mode v0-3-split
pwsh -File .\tool\run_ai_workflow.ps1 -Mode v0-3-train
pwsh -File .\tool\run_ai_workflow.ps1 -Mode v0-3-full
pwsh -File .\tool\run_ai_workflow.ps1 -Mode both
```

This helper runs:

- `tools/evaluate_local_validation.py`
- `tools/build_v0_3_candidate_split.py`
- `train.py --config configs\bontoc_southern_leyte_v0_3_candidate_template.yaml`

Use:

- `Mode both` for baseline local validation plus split rebuild
- `Mode v0-3-train` for training only on the current v0.3 split
- `Mode v0-3-full` for split build, v0.3 training, then post-train local validation in one run

## Live Model Switch Helper

The AI service now reads its default config from:

```text
active_config.txt
```

Check the current active config:

```bash
pwsh -File .\tool\switch_ai_model.ps1 -ShowCurrent
```

Point the service to a different config:

```bash
pwsh -File .\tool\switch_ai_model.ps1 -ConfigPath configs\bontoc_southern_leyte_production_candidate_external.yaml
pwsh -File .\tool\switch_ai_model.ps1 -ConfigPath configs\bontoc_southern_leyte_v0_3_candidate_template.yaml
```

Important:

- this only changes the pointer file
- restart the AI service after switching
- do not switch to `v0.3` until the promotion checklist is satisfied

## Staging Review Helper

Use this before merging staged external images into the main curated dataset:

```bash
C:\laragon\bin\python\python-3.13\python.exe tools\review_staging_dataset.py
```

This generates:

- `artifacts/reports/staging_review_accident_images_analysis_dataset.csv`
- `artifacts/reports/staging_review_accident_images_analysis_dataset.md`

The review helper flags:

- corrupt images
- too-small images
- extreme aspect ratios
- duplicate candidates by file hash

## Retraining Prep Helper

Use this after staging an external dataset to generate a non-destructive split plan:

```bash
C:\laragon\bin\python\python-3.13\python.exe tools\prepare_retraining.py --config configs\bontoc_southern_leyte_severity.yaml
```

This generates:

- `datasets/bontoc_southern_leyte/metadata/retraining_candidate_split_plan_accident_images_analysis_dataset.csv`
- `artifacts/reports/retraining_prep_accident_images_analysis_dataset.md`

It does not touch the main `train`, `val`, or `test` manifests. It only prepares the review-safe next-step plan.

## External Trial Subset Helper

Because the imported `Accident-Severity` public dataset is low-resolution, it is safer to build a separate trial subset instead of mixing it directly into the primary Bontoc manifests.

Use:

```bash
C:\laragon\bin\python\python-3.13\python.exe tools\build_external_trial_subset.py
```

This creates:

- `curated/external_trial_accident_images_analysis_dataset/images/`
- `metadata/train_external_trial_accident_images_analysis_dataset.csv`
- `metadata/val_external_trial_accident_images_analysis_dataset.csv`
- `metadata/test_external_trial_accident_images_analysis_dataset.csv`
- `artifacts/reports/external_trial_accident_images_analysis_dataset_summary.md`

To train the external low-resolution trial:

```bash
C:\laragon\bin\python\python-3.13\python.exe train.py --config configs\bontoc_southern_leyte_external_trial.yaml
```

## Production-Candidate Manifest Helper

Use this to promote an approved external trial manifest set into a separate production-candidate manifest set without touching the empty standard local manifests:

```bash
C:\laragon\bin\python\python-3.13\python.exe tools\build_production_candidate_from_trial.py
```

This creates:

- `metadata/train_production_candidate_external_approved_kaggle_300.csv`
- `metadata/val_production_candidate_external_approved_kaggle_300.csv`
- `metadata/test_production_candidate_external_approved_kaggle_300.csv`

To train the current production candidate:

```bash
C:\laragon\bin\python\python-3.13\python.exe train.py --config configs\bontoc_southern_leyte_production_candidate_external.yaml
```

## Local Inference

```bash
python infer.py --image path\\to\\sample.jpg
```

## Manual Prediction Test

Use this to test one unseen accident image against the current active production-candidate model:

```bash
C:\laragon\bin\python\python-3.13\python.exe tools\manual_prediction_test.py --image path\\to\\sample.jpg
```

This prints:

- active config path
- experiment name
- checkpoint path
- predicted severity
- confidence
- per-class probabilities
- responder review flag

## Batch Prediction Test

Use this to run predictions on one folder of unseen images:

```bash
C:\laragon\bin\python\python-3.13\python.exe tools\batch_prediction_test.py --input path\\to\\image_folder --pattern *.jpg --limit 20 --output-csv artifacts\\reports\\batch_prediction_results.csv
```

This prints:

- active config path
- experiment name
- checkpoint path
- number of images scanned
- prediction counts by severity
- per-image prediction results

## API Service

```bash
uvicorn src.ai_service.api:app --reload --port 8100
```

Endpoints:

- `GET /health`
- `POST /predict`

## Safety Reminder

This model should be treated as AI-assisted triage support only.

- Do not promise 100 percent accuracy
- Use responder confirmation for low-confidence predictions
- Review false positives and false negatives after every new training cycle
