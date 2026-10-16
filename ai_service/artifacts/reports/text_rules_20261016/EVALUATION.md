# Multilingual phrase policy diagnostic ? 2026-10-16

Rule version: `text-cues-20261016-v1`. Unchanged classifier: `dualpath-tfidf-20261016-v1`.

Status: experimental admin-supervised assistance. **No independent real-report accuracy or clinical approval.**

## Evidence and iteration

The 220-row synthetic test from the earlier TF-IDF experiment was already exposed. These results are regression diagnostics, not a fresh holdout or a release accuracy certification. No training text, source labels, reviewer sign-offs, model weights or the 0.65 cutoff were modified.

An initial design that let every lexical match replace the classifier produced only 65.0% routed accuracy (`diagnostic_metrics.json`) and was rejected. A priority design retains accepted model outputs, uses clear Critical/context overrides and lets other phrases fill abstentions. Subsequent checks found two lower-severity errors caused by handling ?bleeding does not stop? as negation or by generic injury words downgrading an uncertain Critical signal. These were corrected before deployment. Intermediate diagnostic metrics/predictions are preserved; none are independent validation. The final script uses the same `combine_assessments` implementation as the service.

## Final historical diagnostic

| Language | Examples | Routed four-class accuracy |
| --- | ---: | ---: |
| English | 69 | 79.7% |
| Tagalog | 61 | 86.9% |
| Cebuano/Bisaya | 57 | 86.0% |
| Mixed | 33 | 97.0% |
| Overall | 220 | 85.9% |

Suggestion coverage: **65.5%**. Accuracy among non-abstained severity suggestions: **91.7%** (excludes review cases). Observed Serious/Critical underclassification: **0/101**. This does not establish a zero field error rate. See `final_policy/metrics.json` for per-class confusion matrices and recall, and `predictions.json` for auditable case IDs.

English routed diagnostic accuracy is below 80%; the desired 80?85% per-language or real-report target is **not established**. The original 92.7% raw-model accuracy is a different metric and must not be advertised as the new combined service accuracy. Scores shown to admins remain uncalibrated classifier outputs, not these dataset accuracy statistics or rule confidence values.

## Behavior checks

The cue tests cover 70 authored English/Tagalog/Bisaya statements, including positive indicators, negations, patient/nonpatient contexts, financial fifty-fifty references, conflicting statements, historical/hypothetical reports, and bleeding that does not stop. Integration tests verify automatic civilian intake, no fabricated rule scores, real classifier-score retention, and persistence of the original AI result after confirmation and a later correction. These are design checks, not independent benchmark examples.

## Reproduce into a new directory

From ai_service with requirements-advisory.txt installed:

```powershell
python evaluate_text_cues.py --data datasets/emergency_text/experiments/full_pool_20261011/reports.csv --manifest artifacts/reports/text_tfidf_20261016/split_manifest.json --model releases/triage-text-tfidf-20261016 --output artifacts/reports/reproduce_text_cues --allow-draft --allow-experimental
python -m unittest tests.test_text_cues tests.test_tfidf_inference tests.test_text_inference tests.test_operation_mode
```

See [the policy](../../../TEXT_CUE_POLICY.md) for the language mappings, bounded context handling, explicit experimental opt-in, retained admin-review snapshots and limitations.
