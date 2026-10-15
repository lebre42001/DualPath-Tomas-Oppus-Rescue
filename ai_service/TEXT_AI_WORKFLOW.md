# Multilingual road-accident description classifier

## Current workflow

Start with [the working dataset](datasets/emergency_text/working/README.md), not the archive or a previous experiment. Current preparation contains 400 synthetic drafts and zero approved examples. The user confirmed that responder/language reviewers are not yet available. The review workflow is ready; reviewed-data training, independent calibration, responder acceptance and live activation remain dependent on real review.

Scope: road/vehicle accidents in English (`en`), Tagalog (`tl`), Bisaya (`ceb`) and mixed language. Outputs: `minor`, `serious`, `critical`, `needs_review`. Critical is not a declaration of death. Photos remain evidence; severity input is description only. Live text inference remains disabled.

Historical data are preserved under `datasets/emergency_text/experiments/` and `archive/initial_80/`. See [the original 9,000-word run](artifacts/reports/text_synthetic_9000words_20260920/README.md) and [the corrected workflow report](artifacts/reports/text_workflow_20260920/README.md). Past test partitions are diagnostic history, not fresh release holdouts.

## Commands and responsibilities

Run commands from `C:\laragon\www\CAPSTONE-EMERGENCY-SYSTEM-main\ai_service`.

| Command | Purpose |
| --- | --- |
| `review_text.py audit` | Current counts, language/category coverage, duplicate/length findings |
| `review_text.py worksheet` | Blank human-decision worksheet with source fingerprint |
| `review_text.py import-review` | Validate supplied reviewer decisions; create a NEW CSV |
| `review_text.py freeze` | Select approved rows and save immutable master/split manifest |
| `review_text.py check-holdout` | Reject overlap with all partitions of prior models |
| `organize_text.py` | Generate current severity/language browsing copies |
| `train_text.py train` | Review-gated transformer training; validation-only by default |
| `review_text.py thresholds` | Validation-only coverage/error sweep; no activation or calibration claim |
| `shadow_text.py` | Offline prediction comparison against independently reviewed labels |

```powershell
& '.\.venv-text\Scripts\python.exe' -B review_text.py audit
& '.\.venv-text\Scripts\python.exe' -B train_text.py validate
& '.\.venv-text\Scripts\python.exe' -B organize_text.py
& '.\.venv-text\Scripts\python.exe' -B organize_text.py --check
```

Default source for audit, review, organization, validation and training is `datasets/emergency_text/working/reports.csv`. Explicit `--data` overrides it. Never concatenate browsing copies with the master. See [worksheet/import instructions](datasets/emergency_text/working/README.md).

## Environment

Use the existing isolated `.venv-text` environment. It uses CPU-only PyTorch; the physically installed RTX 3050 does not make this environment CUDA-enabled. Do not replace shared dependencies or disable Windows security to run a baseline. Optional dependencies are recorded in `requirements-text.txt`.

The original full-backbone 292-row run took 661.38 seconds on CPU. That is a measured historical duration, not an estimate for a larger reviewed dataset. Model/data versions, settings and hardware affect runtime.

## Freeze reviewed data before training

These commands are for AFTER actual review and adoption of the reviewed working master. With zero approved rows they deliberately fail; do not bypass approval for a release run.

```powershell
& '.\.venv-text\Scripts\python.exe' -B review_text.py freeze --output datasets/emergency_text/experiments/reviewed_v1
```

The freeze command rejects duplicated/overlength selected descriptions and splits whole scenario groups with a fixed seed. Related translations/paraphrases must share a group. A frozen split rejects later edits to labels, text or review flags. Group/exact-text checks cannot establish semantic independence automatically. A reviewed split alone does not become a fresh release holdout if its incidents were already used in earlier experiments.

## Full fine-tuning after review

```powershell
& '.\.venv-text\Scripts\python.exe' -u -B train_text.py train `
  --data datasets/emergency_text/experiments/reviewed_v1/reports.csv `
  --split-manifest datasets/emergency_text/experiments/reviewed_v1/split_manifest.json `
  --output artifacts/checkpoints/text_reviewed_v1 `
  --base-model google-bert/bert-base-multilingual-cased `
  --revision 3f076fdb1ab68d5b2880cb87a0886f315b8146f8 `
  --unfreeze-backbone --device cpu --threads 2 `
  --epochs 6 --early-stopping-patience 2 `
  --selection-metric critical_recall_then_macro_f1 `
  --batch-size 1 --gradient-accumulation 16 --max-length 128 --learning-rate 0.00002
```

Six is a maximum epoch budget, not a quality guarantee; early stopping may finish sooner. Full fine-tuning is explicit `--unfreeze-backbone`; otherwise only the classifier head is trained. Inspect complete token lengths first: reviewed overlength training descriptions are rejected instead of silently losing evidence. A new output directory is required for every run.

Class-weighted loss is normalized over the FULL accumulation window, including its partial last window. This fixes the old batch-size-1 cancellation. Checkpoint selection prioritizes validation critical recall, then raw macro-F1; it does not guarantee acceptable per-language errors, false alarms or field performance. Selection records both scores, configured maximum epochs and actual completed epochs.

Training no longer evaluates the test set by default. Keep the same frozen splits across a small predefined settings comparison. `--evaluate-test` is an explicit final/historical diagnostic opt-in, not a tuning flag. Never choose settings, thresholds or epochs from test results. An old exposed test set cannot be claimed as a new independent release test.

## Threshold analysis and independent comparison

```powershell
& '.\.venv-text\Scripts\python.exe' -B review_text.py thresholds --model artifacts/checkpoints/text_reviewed_v1 --output artifacts/reports/text_reviewed_v1_thresholds
```

This writes a validation-only sweep of coverage, accepted accuracy, high-risk underclassification and language-specific results. It does not fit a calibrator, select a release threshold, modify metadata, or activate anything. Calibration requires separately reviewed calibration examples and a documented evaluation; scores remain explicitly uncalibrated until that work exists. Lowering a threshold merely to obtain answers does not correct missed critical cases.

Collect a fresh reviewed test CSV from independent incidents and check against EVERY relevant prior checkpoint:

```powershell
& '.\.venv-text\Scripts\python.exe' -B review_text.py check-holdout --data path/to/reviewed_holdout.csv --exclude-model artifacts/checkpoints/text_synthetic_9000_mbert_full_20260920 --exclude-model artifacts/checkpoints/text_weighted_full_20260920 --exclude-model artifacts/checkpoints/text_reviewed_v1
& '.\.venv-text\Scripts\python.exe' -B shadow_text.py --model artifacts/checkpoints/text_reviewed_v1 --data path/to/reviewed_holdout.csv --output artifacts/reports/text_reviewed_v1_shadow --allow-experimental
```

Replace the example holdout path with the actual reviewed file. Shadow comparison is offline and sends no app reports or notifications. `--allow-experimental` is confined to that diagnostic process: training artifacts always remain unapproved. `--historical-test --allow-draft` can explicitly reproduce diagnostics on a saved synthetic test set, but cannot create fresh evidence.

Review critical recall, false negatives, false positives, per-language class counts, macro-F1, abstention/coverage and latency with responders. Agree operational acceptance criteria before final evaluation. No single aggregate accuracy, word count or file-loading success establishes release readiness.

## Inference contract

`POST /predict/text` accepts only:

```json
{"description":"Nabangga ang motor, adunay pasahero nga nasamdan."}
```

- Maximum: 1,000 characters, matching the web description limit. Empty/blank/non-string inputs and unknown fields return `422`.
- The response includes severity or null, predicted label, uncalibrated model score, four-class probabilities, model version, review flag, and deployment status.
- Uncertain predictions and `needs_review` return null severity. Over-token-limit text is not silently truncated: it returns a review result with no score/probabilities.
- Disabled, missing, unapproved, or unloadable models return `503`. Laravel accepts the report and marks it for responder review.
- Fixed summary templates describe the suggestion without fabricating casualties, location, or reasons.
- `GET /health/text` checks configuration without loading optional dependencies. `GET /ready/text` actually loads the model. The existing `/ready` remains image readiness.

## Laravel behavior and compatibility

All new web/API report submissions use the description-only client. `AI_OPERATION_MODE=MANUAL_ONLY` is the service-wide fail-closed default: `/predict`, `/predict/text`, both readiness routes, `/warmup`, startup preload, and startup checkpoint synchronization are disabled even if legacy model flags or checkpoints are present. Health routes report the mode without loading model weights. Only an explicit `AI_OPERATION_MODE=AI_ENABLED` opt-in permits AI work; `AI_TEXT_ENABLED` remains an additional text-model gate. With AI disabled, report intake accepts the report as **Needs review** with null severity/score/probabilities. Unavailable, invalid, experimental-without-permission, and uncertain responses also require review. There is no image or keyword-score fallback.

Photos/selfies, storage, responder previews, upload validation, and the local photo prescreen remain. No photo bytes are sent to `/predict/text`, and Laravel no longer calls `/predict` or performs remote photo relevance gating. The archived image client is permanently disabled and stale `AnalyzeIncidentEvidence` jobs are no-ops, even with old environment flags. Historical image results and uploaded evidence are not rewritten or deleted.

The legacy app's consumers use `Minor`, `Serious`, `Fatal` storage values. Text `critical` explicitly maps to legacy `Fatal` for compatibility and is displayed as **Critical** for text-model reports. This does not rewrite historical records or assert a death. Unassessed text reports use provisional `Serious` storage with source `text_review`, **Needs review** display, null score and an explicit review flag. Dashboard severity summaries separate these into a `needs_review` bucket. Raw storage fields remain for compatibility; clients must use `severity_display`, `severity_is_provisional`, and `ai_score_label` for presentation rather than interpreting the placeholder as an assessment.

Responder-supplied severity remains authoritative. Text scores are labeled as model scores, not calibrated certainty. Local photo validation and description uncertainty are different checks. Hardware LoRa ingestion retains its supplied/default priority and is separate from the civilian description workflow; it never uses photo inference.

## Release and activation

Training always writes `approved_for_deployment=false`. Before a candidate can serve normal app traffic, complete the dataset/label review, independent evaluation, review-threshold selection, and a responder acceptance review. Record the actual results and limitations; never approve a candidate simply because its files load.

An approved release's `metadata.json` must retain the correct label order/model type and include:

- `all_data_reviewed: true`
- `review_threshold_status: "validated"` with the threshold chosen using validation data
- `approved_for_deployment: true`
- `release_review` containing actual `reviewer`, `approved_at`, and `validation_report` strings

These fields are a release record, not proof by themselves; the referenced evaluation must exist and justify the decision. None are preapproved in the supplied artifacts. Treat checkpoint directories as immutable releases and restart the text service when deploying another version.

For an explicitly isolated experimental service, `AI_TEXT_ALLOW_EXPERIMENTAL=true` bypasses the release gate and labels outputs experimental. Keep it false for the app and public deployment. Do not use a test-only random model as an emergency classifier.

Python reads process environment variables, not Laravel's `.env`. A reviewed text service can run on a separate local port with the optional environment, preserving the image service on 8100:

```powershell
$env:AI_OPERATION_MODE = 'AI_ENABLED' # explicit opt-in required for any model inference
$env:AI_TEXT_ENABLED = 'true'
$env:AI_TEXT_MODEL_DIR = 'artifacts/checkpoints/text_mbert_candidate'
$env:AI_TEXT_ALLOW_EXPERIMENTAL = 'false'
$env:AI_TEXT_DEVICE = 'cpu'
$env:AI_PRELOAD_MODELS = 'false'
$env:AI_SERVICE_PORT = '8101'
& '.\.venv-text\Scripts\python.exe' serve_api.py
```

In Laravel's local `.env`, set the text feature on and its service URL to `http://127.0.0.1:8101`, then clear Laravel config cache. See `.env.example` for exact `AI_TEXT_*` keys. This is an activation instruction for a reviewed model; these settings have not been enabled by preparing this workflow.

## Online deployment

The checked-in `render.yaml` sets the AI service to `AI_OPERATION_MODE=MANUAL_ONLY`, keeps text inference disabled on both services, and disables Laravel image inference and the retired photo gate pending a reviewed release. The AI service does not sync checkpoints or load model weights in manual-only mode. The existing AI service/artifacts and SQLite/local-storage demo profile remain. No online deployment or localhost/public parity is claimed by this change.

Severity folder export is an offline dataset workflow. Dataset folders are excluded by the AI Docker context; no public-service environment change, model activation, or redeploy is needed to browse them locally. Include the helper and documentation with the next source synchronization, but do not publish private review data.

For a reviewed release, build `ai_service/deploy/render/Dockerfile` with `INSTALL_TEXT_AI=true`, provide the validated model directory inside the container, and configure text environment variables. The default Docker build does not install optional text libraries. Do not bake `.venv-text`, drafts, credentials, or private reports into the image.

Local `artifacts/checkpoints/text_*` candidates are excluded by `.dockerignore`. Supply an accepted release explicitly through a separate release directory or mount, then set `AI_TEXT_MODEL_DIR` to that location. Do not remove the candidate exclusion merely to make an unreviewed checkpoint deploy.

Then deploy matching code, verify `/health/text`, `/ready/text`, the public app home/login, and an authorized isolated report flow. Compare reported deployment revision to the source revision; a health response alone is not parity. The current working folder has no `.git` metadata, so this implementation cannot claim a branch/commit match or push a deployment from this copy.

## Verification commands

```powershell
& '.\.venv-text\Scripts\python.exe' -m unittest discover -s tests -p 'test_text_*.py' -v
```

Laravel validation runs from `web_system/` using its isolated SQLite-memory test configuration:

```powershell
php artisan test
npm.cmd run build
php artisan optimize:clear
```

Model/framework references: [Google multilingual language coverage](https://github.com/google-research/bert/blob/master/multilingual.md#list-of-languages), [Hugging Face text classification](https://huggingface.co/docs/transformers/tasks/sequence_classification). These describe the tools, not emergency-specific validity of this candidate.
