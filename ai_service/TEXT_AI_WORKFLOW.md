# Multilingual incident-description classifier

## Current experimental Lab result — 2026-11-09

The isolated admin Lab on `127.0.0.1:8101` uses
`releases/triage-multiincident-tfidf-20261109-v1`. It supports the six registry
categories: `road_accident`, `fire_incident`, `flood_landslide`,
`medical_emergency`, `security_incident`, and `other`. The model feature contract
is `incident_type_description_v1`: `[incident_type=<canonical code>]`, followed
by the description. Training and inference construct the same input. The opt-in
rules are profile `multiincident_common_v1`, version
`multiincident-cues-20261109-v4`; the legacy road profile is preserved separately.

The selected, unaugmented fit contains 1,068 training, 308 validation and 144
family-separated test descriptions across `en`, `tl`, `ceb`, and `mixed`.
Context augmentation was evaluated but was not selected. Final recorded results
(`final_diagnostic_v2`) are:

| Diagnostic set | Raw accuracy | Full-policy accuracy | Critical recall | Urgent cases assigned a lower category |
| --- | ---: | ---: | ---: | ---: |
| Family-separated test, 144 examples | 73.6111% | 72.9167% | 69.2308% | 5 |
| Separate `fresh_v2`, 384 examples | 83.3333% | 68.4896% | 50.0000% | 10 |

**The requested 80% target failed.** These are synthetic drafts, not approved
human-reviewed labels or real-report validation. The complete policy did not
inherit the raw classifier's 83.3333% on the separate set. Both evaluated sets
are now exposed diagnostics; retain their results and do not reuse them as new
independent release evidence. Human-review and deployment-approval flags remain
false. No further training is part of this wrap-up. See
[the complete evaluation](artifacts/reports/multiincident_common_20261109/EVALUATION.md).

The ordinary service on port 8100 retains the road-only TF-IDF v2 release and
legacy rule policy. Laravel reporting remains `MANUAL_ONLY`. The Lab can inspect
fictional descriptions through `/admin/text-ai-test`; it does not create reports,
change dispatch severity or promote this failed candidate. Follow
[LOCAL_TEXT_AI_LAB.md](LOCAL_TEXT_AI_LAB.md) for the separate service configuration.

## Automatic feedback candidates

Current implementation: 2026-11-09. Live model selection/weights are unchanged.
The model registry is prepared for Road Accident, Fire Incident, Flood / Landslide,
Medical Emergency, Security Incident and Other; the ordinary-service release still
supports road accidents only, while the isolated Lab release supports all six.
Existing reporting locks remain. Unsupported categories at the ordinary service
return a scoreless Needs review until a properly trained/evaluated release exists.

The normal admin task is to review/correct severity and explain the text evidence.
For reusable training data, select **Approve for AI training**, choose English,
Tagalog, Bisaya or mixed language, and verify that the description contains no
personal information and supports the label without unseen photo/call information.
Demo/test reports do not qualify. A review saves an immutable description/category/
original-AI snapshot. A later review, changed description/category or deleted report
supersedes the old example. A stale review form cannot approve different text.

The hourly `ai-learning:run` command replaces the private current export rather
than appending revoked examples. Files stay under
`web_system/storage/app/private/ai-learning`, never the public disk. Reviewer and
report identifiers are pseudonymous; internal review notes stay in the private
audit rather than being copied to training text. Screening catches obvious contact
and demo text, not every name/address. It is not automatic anonymization.

Local configuration uses `AI_LEARNING_ENABLED`, `AI_LEARNING_PYTHON`,
`AI_LEARNING_PROJECT_DIRECTORY` and `AI_LEARNING_BASE_MODEL`. The startup helper
runs `schedule:work`; `AI_LEARNING_ENABLED=false` keeps automatic export but skips
CPU training. Dokploy exports through the web scheduler and optionally trains in
the isolated `learning` profile. Follow the deployment guides for volume paths;
the PHP web image has no Python runtime. Render's free demo remains manual-only.

The Python worker accepts the exact export contract and writes candidate artifacts:

```powershell
cd "C:\laragon\www\TomasOppusRescue-main\ai_service"
.\.venv-text\Scripts\python.exe -m src.ai_service.feedback_learning --feedback "..\web_system\storage\app\private\ai-learning\feedback.jsonl" --state-dir "..\web_system\storage\app\private\ai-learning\candidate-state" --base-model "releases\triage-text-tfidf-20261016-v2" --train
```

The routine scheduler supplies these paths; the admin does not run this command
per correction. `--watch --interval 3600` is for a dedicated worker. Without
`--train`, the runner converts data only. It trains a small CPU TF-IDF candidate,
never a transformer or the live model. A process lock prevents overlapping jobs;
unchanged snapshots are idempotent, sparse/invalid snapshots do not retrain in a
tight loop, and revisions/withdrawals invalidate previous pending candidates.

Export JSONL fields include schema_version, feedback_id, group_id, incident_type,
description, description_sha256, label, language, reviewer, reviewed_at,
review_scope=description_only, review_method, notes and approved_for_training=true.
Training accepts the legacy eight-column CSV or those columns plus incident_type.
Category-aware features are versioned identically in training and inference.
Source families and duplicate text stay in one partition; conflicting labels or
incident categories are quarantined. Semantic incident-family review remains needed.

The initial 50-new-example threshold is configurable and is not enough evidence
by itself for all categories. Each observed category/label must also have enough
independent groups for training/validation/test splitting. Check the private
`latest_status.json` coverage counts; no approved data means waiting, not learning.
Create initial reviewed multi-incident examples before unlocking categories;
unsupported incoming reports cannot bootstrap their own correct labels.

To evaluate, configure `AI_LEARNING_HOLDOUT` (or `--holdout`) to a separately
reviewed CSV that was not used for training, tuning, AI-exposed confirmations or
previous acceptance decisions. Its companion `<holdout.csv>.manifest.json` must
record the actual evidence, never invented approval:

```json
{
  "schema_version": 1,
  "independent_review": false,
  "ai_blinded": false,
  "reviewer": "",
  "dataset_sha256": ""
}
```

This template deliberately fails approval. Only the responsible reviewer changes
the flags after the stated work; compute dataset_sha256 from the exact CSV bytes.
The runner checks overlap with the current pool, baseline/prior candidate splits
and previously inspected holdout examples. Changing notes or row order cannot
make old test examples fresh. Rechecking the same candidate/policy/gate returns
its cached evaluation; a new candidate needs fresh acceptance evidence.

The gate evaluates the complete candidate policy, including its declared category
scope and versioned rule profile, on the independent set. Accuracy and macro-F1 must meet the configured
80% minimum overall, per category, per language and category/language slice.
Critical recall, sample coverage, urgent lower-category assignments and baseline
regression also have explicit checks. Abstentions count in accuracy and Critical
recall and are reported separately. The default minimum five examples per slice
and 95% Critical recall are provisional engineering settings, not clinical
validation or proof of statistically reliable 80% field performance.

Passing produces `candidate_ready_for_release_review`; metadata remains
approved_for_deployment=false and activated=false. Activation is separate and
requires actual release review. Never replace the running model on each correction.
Revocation excludes future training/pending candidates; it is not a claim to erase
learned information from a previously deployed model, which would need a separate
reviewed retrain/release. No auto-promotion or active-selector write exists.

Ordinary-service rules remain `text-cues-20261109-v2`; the isolated Lab uses
`multiincident_common_v1` / `multiincident-cues-20261109-v4`. All older benchmark percentages below
describe their recorded model/policy versions, not new-domain accuracy.

## Current workflow

**Description cues and retained results:** [TEXT_CUE_POLICY.md](TEXT_CUE_POLICY.md) documents the explicitly requested multilingual phrase support and original AI-score retention after admin review. Advisory intake runs automatically only when explicitly enabled; current local reporting is manual-only. Uncertain classifier scores remain diagnostic and cannot assign a final severity. Phrase suggestions are labeled `text_rule_advisory`, not attributed to learned confidence. Read each policy's separate evaluation rather than treating raw-model accuracy as combined service accuracy.

**2026-10-16 pilot:** the owner explicitly requested training, evaluation, online suggestions and admin confirmation. [DualPath Trilingual TF-IDF Logistic Regression v2](releases/triage-text-tfidf-20261016-v2/README.md) is selected in `active_text_config.json` and the Dokploy profile as an experimental supervised pilot. The [v2 retraining report](artifacts/reports/text_retrain_20261016_v2/EVALUATION.md) records a reproducible fit using the canonical corpus and original split; the counted 8,000-word subset adds no unique examples and does not enter training twice. Historical raw test accuracy remains 92.7%; combined classifier-plus-rule routed accuracy is 85.9%, with English at 79.7%. Neither establishes the requested real-report accuracy or grants clinical approval. The validated-release procedure below remains pending; source labels and real review flags have not been changed.

Start with [the working dataset](datasets/emergency_text/working/README.md), not the archive or a previous experiment. Current preparation contains 400 synthetic drafts and zero approved examples. The user confirmed that responder/language reviewers are not yet available. The review workflow is ready; reviewed-data training, independent calibration, responder acceptance and live activation remain dependent on real review.

Ordinary-model scope: road/vehicle accidents in English (`en`), Tagalog (`tl`), Bisaya (`ceb`) and mixed language. The isolated Lab's six-category scope is recorded above. Outputs: `minor`, `serious`, `critical`, `needs_review`. Critical is not a declaration of death. Photos remain evidence; classifier features use the description and the selected category according to artifact metadata. Current local Laravel reporting and the free Render profile remain manual-only.

Historical data are preserved under `datasets/emergency_text/experiments/` and `archive/initial_80/`. See [the original 9,000-word run](artifacts/reports/text_synthetic_9000words_20260920/README.md) and [the corrected workflow report](artifacts/reports/text_workflow_20260920/README.md). Past test partitions are diagnostic history, not fresh release holdouts.

## Commands and responsibilities

Run commands from `C:\laragon\www\TomasOppusRescue-main\ai_service`.

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
- `GET /health/text` checks configuration without loading optional dependencies. `GET /ready/text` and `/ready` load the reviewed text model. Image readiness was removed.

## Laravel behavior and compatibility

All new web/API report submissions use the description-only client. `AI_OPERATION_MODE=MANUAL_ONLY` is the service-wide fail-closed default: `/predict/text` and text readiness are disabled. The image `/predict` route, image preloading, and image checkpoint synchronization have been removed. Health routes report the mode without loading model weights. Only an explicit `AI_OPERATION_MODE=AI_ENABLED` opt-in permits AI work; `AI_TEXT_ENABLED` remains an additional text-model gate. With AI disabled, report intake accepts the report as **Needs review** with null severity/score/probabilities. Unavailable, invalid, experimental-without-permission, and uncertain responses also require review. There is no image or keyword-score fallback.

Photos/selfies, storage, responder previews, upload validation, and the local photo prescreen remain. No photo bytes are sent to `/predict/text`, and Laravel no longer calls `/predict` or performs remote photo relevance gating. The archived image client is permanently disabled and stale `AnalyzeIncidentEvidence` jobs are no-ops, even with old environment flags. Historical image results and uploaded evidence are not rewritten or deleted.

New text `critical` suggestions are stored as `Critical`; legacy `Fatal` records retain their historical meaning. AI suggestions remain advisory until an admin confirms or corrects them. Admin APIs and broadcasts expose the current suggestion as `ai_suggested_severity`; operational severity is null and the display remains Awaiting responder until confirmation. Confirmed-severity summaries and dispatch recommendations exclude unconfirmed text AI suggestions. Raw stored suggestion fields remain for audit and must not be interpreted as an approved assessment. Unavailable inference and manual-only intake use null operational severity with Needs review; legacy `text_review` values are excluded from operational projections. Use the operational projections and review flags for presentation and decisions.

Responder-supplied severity remains authoritative. Text scores are labeled as model scores, not calibrated certainty. Local photo validation and description uncertainty are different checks. Hardware LoRa ingestion retains its supplied/default priority and is separate from the civilian description workflow; it never uses photo inference.

## Release and activation

### Website review labels

The Laravel **Confirm or correct severity** form is available to Admin Receivers in Monitoring, Incident Reports, and report details. The intended flow is text AI suggestion first, followed by admin confirmation/correction; an unavailable model permits a human assessment. Minor, Serious, Critical, and Needs review are explicit text-review labels. Review notes describe the supporting description or missing information and are separate from the civilian response message.

Report exports append `Text review label`, `Text reviewer ID`, `Text reviewed at`, `Text review note`, `Text description SHA256`, `Text review method`, and `AI suggestion label`. The existing coordination log preserves reviewer identity, previous assessment, and the original model result. Review methods distinguish `confirmed_ai`, `corrected_ai`, and `human_assessment`. Changed descriptions or later conflicting severity reviews invalidate an earlier exported text label. Blank review fields remain unreviewed; a legacy source or a saved operational severity alone is not text-label approval.

These records are candidate annotations, not automatic training or deployment approval. Check reviewer qualifications, de-identification, scope, incident families, and data quality before importing them. AI-exposed confirmations must not be presented as an independently blinded ground-truth evaluation. Keep a separately labeled independent test set for the 80% release target.

Training always writes `approved_for_deployment=false`. Before a candidate can serve normal app traffic, complete the dataset/label review, independent evaluation, review-threshold selection, and a responder acceptance review. The requested release target is at least 80% accuracy on a frozen, independently responder-labeled real-report test set, together with the Critical recall, underclassification, language, and useful coverage criteria in the acceptance record. Record the actual results and limitations; never approve a candidate simply because its files load or because synthetic test accuracy exceeds 80%.

An approved release's `metadata.json` must retain the correct label order/model type and include:

- `all_data_reviewed: true`
- `review_threshold_status: "validated"` with the threshold chosen using validation data
- `approved_for_deployment: true`
- `release_review` containing actual `reviewer`, `approved_at`, and `validation_report` strings

These fields are a release record, not proof by themselves; the referenced evaluation must exist and justify the decision. None are preapproved in the supplied artifacts. Treat checkpoint directories as immutable releases and restart the text service when deploying another version.

For an explicitly isolated experimental service, `AI_TEXT_ALLOW_EXPERIMENTAL=true` bypasses the release gate and labels outputs experimental. Keep it false for the app and public deployment. Do not use a test-only random model as an emergency classifier.

Python reads process environment variables, not Laravel's `.env`. The current
isolated experiment uses `test_model_directory` in `active_text_config.json`,
`serve_text_test.py`, and port **8101**. Laravel's admin Lab client uses
`AI_TEXT_TEST_SERVICE_URL=http://127.0.0.1:8101`; the ordinary reporting client
retains `AI_TEXT_SERVICE_URL` for port **8100** and `MANUAL_ONLY` reporting.
Follow [the Lab setup](LOCAL_TEXT_AI_LAB.md) and the normal startup helper.
Do not point ordinary reporting at the failed Lab candidate as an activation
shortcut. A future approved release requires its own reviewed deployment decision.

## Online deployment

The checked-in `render.yaml` sets the AI service to `AI_OPERATION_MODE=MANUAL_ONLY`, keeps text inference disabled on both services, and disables Laravel image inference and the retired photo gate pending a reviewed release. The AI service does not sync checkpoints or load model weights in manual-only mode. The existing AI service/artifacts and SQLite/local-storage demo profile remain. No online deployment or localhost/public parity is claimed by this change.

Severity folder export is an offline dataset workflow. Dataset folders are excluded by the AI Docker context; no public-service environment change, model activation, or redeploy is needed to browse them locally. Include the helper and documentation with the next source synchronization, but do not publish private review data.

For a reviewed release, build `ai_service/deploy/render/Dockerfile` with `INSTALL_TEXT_AI=true`, provide the validated model directory inside the container, and configure text environment variables. The default Docker build does not install optional text libraries. Do not bake `.venv-text`, drafts, credentials, or private reports into the image.

Local `artifacts/checkpoints/text_*` candidates are excluded by `.dockerignore`. Supply an accepted release explicitly through a separate release directory or mount, then set `AI_TEXT_MODEL_DIR` to that location. Do not remove the candidate exclusion merely to make an unreviewed checkpoint deploy.

Then deploy matching code, verify `/health/text`, `/ready/text`, the public app home/login, and an authorized isolated report flow. Compare reported deployment revision to the source revision; a health response alone is not parity. The isolated Lab setup does not by itself establish an online branch/commit match or a successful deployment.

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
