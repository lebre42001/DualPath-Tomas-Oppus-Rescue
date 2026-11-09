# Six-category common-vocabulary experiment: final diagnostic

Date: 2026-11-09. **The requested 80% complete-system target was not met.**
The trained artifact is available only in the isolated local AI Lab. It is not a validated final model and has not replaced the live reporting model. All authored data and labels remain synthetic drafts, with no human/clinical approval.

## Frozen candidate and data

- Release: `releases/triage-multiincident-tfidf-20261109-v1`.
- Model: character-word-boundary TF-IDF (3-5 grams) plus logistic regression, C=16; category-plus-description input (`incident_type_description_v1`).
- Categories: road accident, fire, flood/landslide, medical emergency, security incident, and other.
- Languages: English, Tagalog, Cebuano/Bisaya, and mixed language.
- Fitted rows: 1,068; validation: 308; family-partitioned final test: 144. All 1,520 rows are unreviewed drafts. The 384-description separate diagnostic was not fitted.
- Training vocabulary word types: English 739, Tagalog 614, Cebuano 589, mixed 736. Counts overlap across languages and are descriptive, not a coverage or accuracy guarantee. The classifier learns character patterns rather than relying solely on a word list.
- Provisional review cutoff: 0.50; scores are uncalibrated. Cue profile: `multiincident_common_v1`, implementation `multiincident-cues-20261109-v4`.
- Validation-only Lab selection: 85.06% full-policy accuracy, 91.67% Critical recall, zero Critical lower assignments and one Serious lower assignment. This is selection evidence, not a held-out result.

## Final results

Complete-policy accuracy includes every evaluated case after rules and the review cutoff. A Needs review output is a class, not an excluded error or a correct Critical assignment. Raw classifier accuracy is the pre-routing argmax and is not the accuracy users receive.

| Diagnostic | N | Raw classifier accuracy | Complete-policy accuracy | Macro-F1 | Critical recall | Urgent lower assignments | Suggestion coverage |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Family-partitioned test | 144 | 73.61% | 72.92% | 71.88% | 69.23% | 5 / 76 | 50.00% |
| Separately authored fresh-v2 challenge | 384 | 83.33% | 68.49% | 69.17% | 50.00% | 10 / 192 | 46.88% |

The 144-case test contains 105 correct complete-policy predictions (106 raw). The separate 384-case diagnostic contains 263 correct complete-policy predictions (320 raw). The raw 83.33% result must not be presented as a final 80% system.

Urgent lower assignment means Serious assigned Minor, or Critical assigned Minor/Serious. It excludes review abstentions: the test also routed 19/76 urgent cases to review, and the challenge routed 82/192 urgent cases to review. Review still needs timely human attention. Critical recall was 36/52 and 48/96, respectively.

The preserved engineering gate required at least 80% accuracy and macro-F1 overall and by category/language, at least 95% Critical recall, and zero urgent lower assignments. These are provisional engineering criteria, not clinical standards. Both diagnostics failed; independent real-report validation is absent. `engineering_gate.json` lists every failing slice.

## Category results

| Slice | Test N | Test raw | Test complete policy | Challenge N | Challenge raw | Challenge complete policy |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| `road_accident` | 28 | 75.00% | 67.86% | 64 | 84.38% | 64.06% |
| `fire_incident` | 24 | 66.67% | 54.17% | 64 | 73.44% | 68.75% |
| `flood_landslide` | 24 | 54.17% | 66.67% | 64 | 78.12% | 70.31% |
| `medical_emergency` | 20 | 90.00% | 75.00% | 64 | 85.94% | 65.62% |
| `security_incident` | 24 | 79.17% | 79.17% | 64 | 87.50% | 70.31% |
| `other` | 24 | 79.17% | 95.83% | 64 | 90.62% | 71.88% |

## Language results

| Slice | Test N | Test raw | Test complete policy | Challenge N | Challenge raw | Challenge complete policy |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| `en` | 36 | 77.78% | 77.78% | 96 | 83.33% | 75.00% |
| `tl` | 36 | 69.44% | 69.44% | 96 | 84.38% | 67.71% |
| `ceb` | 36 | 72.22% | 63.89% | 96 | 83.33% | 59.38% |
| `mixed` | 36 | 75.00% | 80.56% | 96 | 82.29% | 71.88% |

## Selection, exposure and limits

The initial source used 60 declared global semantic families; translations and category variants of a declared family stayed together. The supplementary source was independently family-partitioned before fitting. The final combined partitions have no declared family or exact-text overlap; concepts can overlap across AI-authored sources. Small subgroup cells and correlated translations prevent reliable population estimates.

All trials and revisions are retained. The first 208-case test and first 384-case challenge were inspected during earlier development and are exposed regression evidence, not fresh tests for this release. The earlier 208 cases were omitted entirely from the new combined fit. Four neutral context variants per training row were tried; the final Lab choice used the unaugmented combined training rows.

The conservative validation-ranked candidate remains recorded separately. After its validation shortfall, a documented Lab-only selection ranked frozen candidates by Critical lower assignments, validation accuracy/macro-F1, and Serious lower assignments. The selected C16/0.50 model was then frozen before either final-v2 diagnostic was scored. This amended Lab selection does not bypass the deployment gate.

The fresh-v2 challenge was authored by a separate agent without reading training data, checkpoints, previous predictions or metrics. It is still AI-authored fictional evidence with provisional labels, not independently responder-labeled real reports. Its 24 scene families span 96 category/severity scenarios and 384 translations. Both final-v2 sets are now exposed and cannot be reused as fresh acceptance evidence for a later tuned version.

Rules can improve explicit hazard handling while also causing incorrect review routing or missing language variants. Current failures include excessive abstention and missed urgent indications. Unit tests establish defined software behavior; they do not establish medical or field accuracy. No automatic dispatch or death certification is authorized by a suggestion.

## Local availability and deployment

`active_text_config.json` adds only `test_model_directory` for the new release. The local Lab service on `127.0.0.1:8101` loads `multiincident-tfidf-20261109-v1`, six categories and cue v4. Open `http://127.0.0.1:8000/admin/text-ai-test` with an admin account. Lab requests do not create operational incident records.

The ordinary 8100 service still loads the road-only `dualpath-tfidf-20261016-v2` checkpoint with legacy cue v2. Local Laravel reporting remains `MANUAL_ONLY`. Existing civilian/LoRa category locks are unchanged. The automatic approved-feedback pipeline remains candidate-only and has no real approved training examples yet.

No online deployment was performed. Render remains the AI-disabled free-demo profile with local ephemeral file storage and free Postgres; it is not production-safe. Deploying these commits alone does not enable this Lab or validate the experiment. Keep the live selector unchanged until reviewed representative data, a fresh independent holdout and an explicit release decision support a replacement.

## Verification and evidence

- Full AI suite: 215 tests run; 214 passed and one optional transformer smoke test skipped.
- Runtime parity: 12 already-scored fictional Critical/review cases across all six categories matched the evaluation labels, methods and model probabilities through the running 8101 HTTP API (`final_diagnostic_v2/local_runtime_parity.json`). This is a consistency check, not another accuracy estimate.
- Local `/`, `/login` and `/register` returned 200; 8100 and 8101 readiness returned the expected distinct model versions.
- Independent static audit matched the packaged artifact, metadata and all four tested policy source hashes.
- Reproducibility: source scripts are `tools/train_multiincident_common.py` and `tools/augment_multiincident_contexts.py`; draft data and frozen split manifests are in `datasets/emergency_text/experiments/multiincident_common_20261109/`.
- Final evidence: `final_diagnostic_v2/frozen_context.json`, both `*_metrics.json` and `*_predictions.json`, `challenge.csv`, and `engineering_gate.json`. Intermediate trials, selection receipts and prior failed diagnostics are preserved.

`all_data_reviewed=false`, `human_reviewed=false`, `independent_validation=false`, and `approved_for_deployment=false`. No 80% real-report accuracy claim or human approval was generated.
