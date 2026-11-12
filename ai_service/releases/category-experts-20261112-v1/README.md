# Category-specific multi-incident text AI

Version `category-experts-20261112-v1`, 2026-11-12. **Experimental advisory only;
not independently validated for real reports or automatic dispatch.** The owner
explicitly requested activating multi-incident analysis with training isolated
by the reporter's selected category. Every operational severity still requires
admin assessment. Approval and independent-validation flags remain false.

## What changed

Six independently fitted character TF-IDF / Logistic Regression classifiers
replace the ordinary road-only selector. Selecting Fire calls only the Fire
classifier; selecting Road Accident calls only the Road classifier. Each
vectorizer and classifier was fitted exclusively on its category's training
rows. A category-plus-description feature contract is shared by training and
inference. The service loads the fitted artifacts; it does not search a CSV or
retrain itself on each submitted report.

`category_models.json` binds every expert directory, metadata digest and artifact
digest. The loader rejects missing/mismatched experts, checksum failures,
duplicate JSON fields and paths escaping this release. Unknown categories never
fall back to another expert. Existing omitted-category legacy API requests
retain their road default; the current reporter form requires a valid selection.
Common old road labels such as Vehicular Collision normalize to Road Accident.

## Training data

| Category | Training | Validation | Known test | Total |
| --- | ---: | ---: | ---: | ---: |
| Road Accident | 5,735 | 255 | 248 | 6,238 |
| Fire Incident | 1,060 | 48 | 24 | 1,132 |
| Flood / Landslide | 1,040 | 52 | 24 | 1,116 |
| Medical Emergency | 1,100 | 52 | 20 | 1,172 |
| Security Incident | 1,060 | 48 | 24 | 1,132 |
| Other | 1,040 | 52 | 24 | 1,116 |
| Total | **11,035** | **507** | **364** | **11,906** |

The unaugmented training pool contains 947 retained road training rows, 1,068
retained multi-incident training rows, and 192 newly authored descriptions from
48 fictional scene families in English, Tagalog, Cebuano/Bisaya and mixed
language. Four neutral context prefixes per training row add 8,828 correlated
variants, preserving every original description in full, including negation,
time context and uncertainty. These are not 11,035 independent real incidents.
New scenes/variants are train-only. Both source validation/test memberships,
descriptions and labels are preserved; historical road review flags were copied
only into derived explicitly draft rows because their approval is unverified.
Original source files remain unchanged. No private reports or identities were
used and no human approval was fabricated.

Settings were fixed: C=16, seed 20261112, road cutoff 0.65, other cutoffs 0.50.
There was no hyperparameter search or tuning on test predictions. The tool
refuses to overwrite prior experiment/release directories.

## Known synthetic diagnostics

Validation full-policy accuracy: **83.83%**, Critical recall **87.90%**.
Known-test full-policy accuracy: **74.45%**, macro-F1 **75.12%**, Critical recall
**74.76%**, and five urgent cases assigned a lower category. Needs review is
included in the denominator; suggestion coverage is 56.59%.

| Category | Test N | Full-policy accuracy | Critical recall |
| --- | ---: | ---: | ---: |
| Road Accident | 248 | 76.21% | 81.36% |
| Fire Incident | 24 | 54.17% | 66.67% |
| Flood / Landslide | 24 | 66.67% | 66.67% |
| Medical Emergency | 20 | 75.00% | 25.00% (1/4) |
| Security Incident | 24 | 75.00% | 62.50% |
| Other | 24 | 83.33% | 87.50% |

These exposed synthetic diagnostics **do not meet the prior operational
validation criteria**. Small cells, reused clinical concepts and correlated
translations limit interpretation. Category isolation and more rows do not
establish higher accuracy. The old road-only 84.55% and pooled Lab 72.92%
results used different test populations; this combined 364-row result is not a
direct improvement claim. Scores and cutoffs remain uncalibrated. Medical
Critical recall is especially limited. Admin review remains mandatory; a fresh,
independently reviewed representative real-report holdout is still required.

Evidence is in `training_plan.json`, `evaluation_report.json`,
`*_policy_metrics.json`, `*_policy_predictions.json`, `split_manifest.json`,
`tested_policy_context.json`, and each expert's metadata/manifest. Artifacts are
plain numeric JSON with checksums, never executable pickle. `.gitattributes`
preserves their exact bytes across Windows and Linux.

## Local verification

The final policy reproduced all 364 saved test predictions and metrics exactly.
The combined corpus has no normalized exact description overlap within a
category across partitions and no shared group IDs across partitions. This
mechanical audit does not establish semantic independence or expert approval.
Twelve live FastAPI predictions across all six experts matched the evaluator;
the real Laravel client accepted all six while requiring human review.
`runtime_verification.json` records these synthetic checks without real reports.

The AI suite passed 230 tests (one optional transformer test skipped). The full
Laravel suite passed 470 tests, followed by the additional malformed-category
validation test. Draft/library/tutorial JavaScript tests and the production asset
build passed. An isolated browser check verified all six enabled selections,
Fire draft restoration, and picker widths of 320, 390 and 1024 pixels without
creating a report. Public deployment requires the separate checks below.

## Activation and rollback

The explicit local selector in `active_text_config.json` points both 8100 and
the admin Lab at this release. Dokploy Compose points its private `text-ai`
service at `/app/releases/category-experts-20261112-v1`; the Docker build checks
all expert artifacts before deployment. Laravel remains `MODEL_ADVISORY` with
experimental opt-in and mandatory admin confirmation. Service failure still
accepts reports for human assessment. Existing reviewed reports are unchanged.
LoRa firmware remains road-only; the six-category change is the online/PWA/API
reporting flow. Render's separate free-demo profile remains AI-disabled.

To disable automated analysis while accepting reports, set web
`AI_TEXT_MODE=MANUAL_ONLY` and `AI_TEXT_ENABLED=false`, clear configuration and
restart the affected web process. The local startup helper defaults to advisory
when selected; pass these overrides in its parent shell as documented in
`LOCAL_TEXT_AI_LAB.md`. To restore road-only inference, select the retained
`releases/triage-text-tfidf-20261016-v2` locally and in Compose and restart/rebuild
the AI service. Other categories then require human assessment.

Rebuild from `ai_service` using `.\.venv-text\Scripts\python.exe -B
tools\train_category_experts.py`. Use new `--experiment` and `--output`
directories for a repeat; copy the authored `new_scenarios.json` into the new
experiment directory first. Training alone never edits selectors or grants
approval.
