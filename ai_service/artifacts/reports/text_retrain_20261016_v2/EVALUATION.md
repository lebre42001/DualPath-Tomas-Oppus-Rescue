# Requested text retraining and experimental v2 release - 2026-10-16

Model: **DualPath Trilingual TF-IDF Logistic Regression**, `dualpath-tfidf-20261016-v2`.
Policy: `text-cues-20261016-v1`; admin confirmation remains mandatory.

## Data and what was actually retrained

The owner requested live use after creating a counted pack with 2,000 description words in each of English, Tagalog, Bisaya and mixed language. That pack has 764 complete descriptions and 8,000 words; it is a subset of the existing 1,366-row synthetic corpus, not newly collected information.

To retain the existing examples, the classifier was fitted again on the canonical corpus using the original frozen group split: **947 train, 199 validation, 220 test**. Among the counted pack's rows, **537 are in training, 118 in validation and 109 in test**. Every selected case matches the original case/group/language/text/label. Duplicates were not concatenated and validation/test rows were not fitted. Source labels/review fields were not changed; historical approval fields remain independently unverified. Metadata retains `all_data_reviewed=false` and `approved_for_deployment=false`.

Settings were fixed before fitting: character TF-IDF 3-5 grams, 20,000-feature maximum, balanced Logistic Regression, C=16, seed 20261016, uncalibrated cutoff 0.65. There was one candidate; no new tuning. [PLAN.json](PLAN.json) records the input hashes, split membership counts and gates before training. [GATE_RECEIPT.json](GATE_RECEIPT.json) records the results.

The new fit reproduces **identical numeric artifact bytes** to v1: SHA-256 `58d859a25a01f0fb95dd70576ed1523a9a09051e8d097776afb92b80e4085b90`. This is a retraining/reproducibility release, with no accuracy improvement or added knowledge claimed. The original generated training run ID is preserved as `training_run_version` in the release metadata.

## Historical diagnostic results

Validation raw accuracy: 96.5%, macro-F1 0.9604, Critical recall 95.0%; unchanged from v1. Validation passed before the diagnostic test was evaluated.

| Language | Test examples | Raw classifier accuracy | Combined routed policy accuracy |
| --- | ---: | ---: | ---: |
| English | 69 | 92.8% | 79.7% |
| Tagalog | 61 | 95.1% | 86.9% |
| Bisaya | 57 | 87.7% | 86.0% |
| Mixed | 33 | 97.0% | 97.0% |
| Overall | 220 | 92.7% | 85.9% |

Combined suggestion coverage: 65.5%; accepted suggestion accuracy: 91.7%, excluding abstentions. Observed high-risk underclassification: 0/101. These figures match v1 and are different metrics; accepted accuracy must not replace the full-sample result. Raw classifier results are in [test/evaluation_metrics.json](test/evaluation_metrics.json); actual classifier-plus-rule results are in [combined/metrics.json](combined/metrics.json). Case-level predictions and the frozen split are retained.

**This historical synthetic test has already been exposed during development. It is not independent real-report or clinical validation.** English combined routed accuracy remains below 80%; the requested 80-85% per-language/real-report goal is not established. No field error guarantee or fabricated human approval is supplied.

## Activation, persistence and rollback

The system owner explicitly requested retraining and live deployment on 2026-10-16. The v2 release remains an experimental, admin-supervised pilot. Local `active_text_config.json` and Dokploy `compose.dokploy.yaml` select `releases/triage-text-tfidf-20261016-v2`; both retain experimental opt-in and phrase rules. No web schema, routing, permission or severity mapping change is needed.

New reports automatically submit only their description. The admin sees suggestions and genuine four-class model scores; phrase rules have no fabricated confidence. Admin confirmation/correction remains the final assessment and preserves the original AI result/scores. Existing reviewed reports are not reanalyzed or overwritten by deployment. Old unreviewed reports can be analyzed again with v2 using the existing button.

After deployment verify `/system/ai-status` reports `ready`, `model_version=dualpath-tfidf-20261016-v2`, `text_rules_enabled=true`, `text_rules_version=text-cues-20261016-v1`, and `admin_confirmation_required=true`. Verify a real public app route such as `/login`. Local text API/PHP integration checks must pass before push. Docker verifies packaged model hashes during build. Training sources and experiment artifacts are excluded from the service image.

For rollback, select the retained `releases/triage-text-tfidf-20261016` directory in both local config and Compose and redeploy/restart. Its weight bytes are identical. Set `AI_TEXT_MODE=MANUAL_ONLY` to disable suggestions while continuing report receipt.

## Reproduce offline

From `C:\laragon\www\TomasOppusRescue-main\ai_service`, use new output directories:

```powershell
.\.venv-text\Scripts\python.exe -B train_text.py baseline --data datasets/emergency_text/experiments/full_pool_20261011/reports.csv --output artifacts/checkpoints/reproduce_v2 --split-manifest artifacts/reports/text_tfidf_20261016/split_manifest.json --allow-draft --seed 20261016 --regularization-c 16 --review-threshold 0.65
.\.venv-text\Scripts\python.exe -B train_text.py evaluate --data datasets/emergency_text/experiments/full_pool_20261011/reports.csv --model artifacts/checkpoints/reproduce_v2 --output artifacts/reports/reproduce_v2/test --allow-draft --split test
.\.venv-text\Scripts\python.exe -B evaluate_text_cues.py --data datasets/emergency_text/experiments/full_pool_20261011/reports.csv --manifest artifacts/checkpoints/reproduce_v2/split_manifest.json --model artifacts/checkpoints/reproduce_v2 --output artifacts/reports/reproduce_v2/combined --allow-draft --allow-experimental
```

Retraining alone never changes the active model. Explicit release metadata/selector updates and deployment are separate steps.
