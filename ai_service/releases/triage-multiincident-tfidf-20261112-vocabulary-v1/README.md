# Experimental multi-incident local vocabulary candidate

Version: `multiincident-tfidf-20261112-vocabulary-v1`, created 2026-11-12.
Character TF-IDF (3–5 grams), logistic regression C=16, provisional cutoff 0.50.
Uses the existing category-plus-description contract and
`multiincident_common_v1` cue profile. Supports the six canonical incident
categories and English, Tagalog, Cebuano/Bisaya and mixed wording.

The user requested additional multi-incident vocabulary and local text AI
activation. The ordinary reporting service on 8100 retains the road-only v2
release. This new candidate is selected **only for the local admin Lab on 8101**
after the fixed validation comparison below. It has not replaced the ordinary
model or enabled additional reporting categories.

One bounded CPU fit uses 5,340 training rows, including 4,272 correlated neutral
context variants of the original 1,068 training descriptions. All complete
source descriptions, groups, labels and languages are preserved. All 308
validation and 144 known test rows are unchanged. There are no new independent
incident families and no human-approved examples in this experiment.

| Existing synthetic partition | Prior model full policy | New model full policy | Prior/new Critical recall | Prior/new urgent lower assignments |
| --- | ---: | ---: | ---: | ---: |
| Validation, 308 cases | 85.06% | 86.04% | 91.67% / 92.86% | 1 / 1 |
| Known test, 144 cases | 72.92% | 72.92% | 69.23% / 69.23% | 5 / 5 |

Lab selection uses validation only: accuracy, macro-F1 and Critical recall must
not regress, and urgent lower assignments must not increase. The new candidate
passes that comparison. It does **not** pass the historical operational release
criteria; test accuracy remains below 80%, Critical recall remains low, and
urgent lower assignments remain. No claim of improved generalization or
real-report accuracy follows from the vocabulary increase.

All data/labels remain AI-authored drafts. Scores are uncalibrated. Existing
partitions were exposed during earlier development; they are regression
diagnostics, not fresh acceptance evidence. The former external challenge was
not added to training and was not used to tune this fit. Human review and a
fresh independently reviewed representative holdout are still needed for a
validated operational replacement. No automatic dispatch or death assessment
is authorized.

Evidence: `expansion_report.json`, `validation_selection.json`, the
`baseline_*_policy_metrics.json` / `candidate_*_policy_metrics.json` pairs,
`tested_policy_context.json`, and `split_manifest.json`. `metadata.json` keeps
`approved_for_deployment=false`, `human_reviewed=false`,
`independent_validation=false`, `deployment_status=experimental`.

The frozen experiment/release files preserve their captured bytes through Git
via `.gitattributes`, so artifact, metadata and dataset digests survive upload.
`policy_source_sha256` binds the raw source bytes used for the local diagnostic;
`policy_source_lf_sha256` binds the same source with CRLF normalized to LF for
comparison with Linux checkouts. No policy logic changed during packaging.

Rebuild with `tools/refresh_local_text_vocabulary.py` from `ai_service`, using
new output directories. The helper does not activate a model. The Lab selector
is a separate explicit setting in `active_text_config.json`. To roll the Lab
back, restore `test_model_directory` to
`releases/triage-multiincident-tfidf-20261109-v1`, restore its evaluation-report
path, and restart only that checkout's `serve_text_test.py` service.

Local verification on 2026-11-12:

- Ordinary and Lab `/ready/text` returned 200 with their distinct intended
  checkpoints. Six-category HTTP predictions matched the packaged Lab policy.
- Laravel `/system/ai-status` reported ready and required admin confirmation;
  a direct real-service client check produced a suggestion while keeping
  `operational_severity=null`. An unsupported ordinary-service category returned
  Needs review. No report records were created by these checks.
- Local `/`, `/login`, and `/register` returned 200. Asset build passed.
- The 101-test text suite passed with one optional transformer test skipped;
  the new full-description/holdout-preservation test passed separately.
- Laravel's full run passed 456 tests; one existing XLSX test lacked the PHP
  ZIP extension. All eight export tests then passed with `-d extension=zip`.
  No application code or database changes were needed for that runtime issue.
- Artifact, metadata and policy hashes were verified. Public deployment was
  not attempted; the free Render AI flags are unchanged.
