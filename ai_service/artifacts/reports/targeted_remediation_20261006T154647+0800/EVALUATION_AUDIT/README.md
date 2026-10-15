# Saved-probability evaluation audit

This is a CPU-only audit of the selected targeted-remediation checkpoint's saved probability vectors. It joins rows by `case_id` to the authoritative frozen split CSVs and recomputes metrics; it does not load a model, run inference, edit labels, or change the active selector.

## Main findings

| Split | n | Raw macro-F1 | Critical recall | Raw Serious/Critical undertriage | .65 coverage | .65 routed undertriage |
|---|---:|---:|---:|---:|---:|---:|
| Exposed test | 84 | 0.8523 | 13/20 = 0.650 | 7/41 | 57/84 = 67.9% | 6/41 |
| Calibration diagnostic | 83 | 0.8594 | 18/21 = 0.857 | 4/42 | 58/83 = 69.9% | 3/42 |
| Exposed challenge | 43 | 0.9067 | 11/11 = 1.000 | 0/21 | 31/43 = 72.1% | 0/21 |

The new 84-row output was independently checked against its saved evaluator metrics: counts, fingerprints, raw confusion matrix/macro-F1, .65 routed confusion matrix/coverage, and routed undertriage all match. It shares the same fingerprint and 84 IDs as the prior rejected checkpoint report. On that same 84-row set, new vs prior raw macro-F1 was 0.8523 vs 0.8456, Critical recall 0.65 vs 0.55, and raw high-risk undertriage 7 vs 9; .65 routed undertriage remained 6 vs 6. These are exposed synthetic regression metrics, not fresh validation.

The separate historical 83-row matched comparison is preserved as its own comparison: the prior checkpoint scored macro-F1 0.8749, Critical recall 0.70, raw undertriage 6, routed undertriage 4; the earlier new checkpoint scored 0.8441, 0.55, 9, and 6 respectively. Its fingerprint matches between the two historical reports. It is not combined with the new targeted checkpoint's 84-row results.

Calibration-only fitting selected temperature `T=0.96508155` by bounded minimization of calibration NLL. NLL changed 0.41618→0.41573, while multiclass Brier changed 0.20798→0.20807 and 10-bin ECE changed 0.02503→0.05423. This small synthetic sample does not show consistent calibration improvement.

The pre-existing `.65` comparative threshold was retained. A calibration-only research rule was then frozen: on temperature-scaled calibration probabilities, choose the 0.01-grid threshold with the greatest coverage and zero observed Serious/Critical undertriage. It selected `.96`, with 4/83 total coverage (4.8%), zero calibration undertriage, and 4/42 Serious/Critical examples automatically handled (all four correctly classified in this sample); the other 38/42 high-risk examples were routed to people. Conditional auto-urgent accuracy is 4/4 = 100% observed, not a safety guarantee. The requested combination of 4/83 total coverage and 0/42 automatically handled high-risk cases is **not** what the recorded single-threshold routing rule yields. At `.97`, calibration accepted 0/83 and routed all 42/42 high-risk cases to people; conditional auto-urgent accuracy and undertriage are undefined because the denominator is zero. Neither threshold is safe, operationally selected, or deployable.

Under the frozen `T=.96508155`, `.96` research threshold, exposed test coverage is 4/84 (4.8%) with 4/41 high-risk examples automatically handled and routed undertriage 0/41; the challenge coverage is 0/43. The same policy routes all 9 deduplicated target cases to human review. Raw argmax and original `.65` results remain separately reported in the JSON.

The nine latest target cases and earlier six-case list have six overlapping IDs, yielding a deduplicated union of nine. On the exposed-test raw argmax, that union contains 2 Critical→Minor and 4 Critical→Serious predictions; under the frozen `.96` diagnostic routing, all nine are sent to human review. No case label was changed or adjudicated.

## Subgroups and limits

Exposed-test raw language macro-F1 / Critical recall are: Cebuano 0.777 / 1 of 3; English 0.958 / 5 of 6; mixed 0.738 / 1 of 3; Tagalog 0.873 / 8 of 8. By whitespace word count, 1–3 words n=7 (macro-F1 0.667), 4–8 n=29 (0.885), 9–15 n=33 (0.781), and 16+ n=15 (0.922). Each slice is small; these are descriptive only.

All source rows remain synthetic. The current 84-row test and 43-row challenge have historical exposure and must not be called fresh or independent. Calibration is also synthetic and low-support. No responder real-world final test is available. The initial sequential runner invoked exposed-test inference before calibration; preliminary exposed metrics were inspected before the second calibration threshold policy froze. Training and checkpoint selection did not change, but the ordering was imperfect. The final summaries here were recalculated from preserved probabilities after that policy freeze and are therefore not a confirmatory untouched-heldout evaluation.

## Artifacts and reproducibility

- `EVALUATION_AUDIT.json`: complete raw, routed, per-class, per-language, word-length, case-set, calibration, comparison, consistency-check and SHA-256 records.
- `CALIBRATION_DIAGNOSTIC.json`: calibration-only temperature metrics and 0.01-grid threshold sweep through 1.00.
- `FROZEN_DIAGNOSTIC_POLICY_V2.json`: calibration-only frozen T and research threshold, with the sequencing limitation recorded.
- `FROZEN_DIAGNOSTIC_POLICY.json`: preliminary policy record corrected to say exposed metrics had been inspected before that first freeze.
- `TARGET_CASE_PREDICTIONS.csv`: case-level predictions for latest-nine, older-six and deduplicated union under the raw/.65 and frozen research-threshold views.
- `audit_evaluations.py`, `freeze_calibration_policy.py`: reproducible CPU analysis scripts.

Commands, from repository root:

```powershell
ai_service/.venv-xlmrt-fulltrain/Scripts/python.exe -m py_compile ai_service/artifacts/reports/targeted_remediation_20261006T154647+0800/EVALUATION_AUDIT/audit_evaluations.py ai_service/artifacts/reports/targeted_remediation_20261006T154647+0800/EVALUATION_AUDIT/freeze_calibration_policy.py
ai_service/.venv-xlmrt-fulltrain/Scripts/python.exe ai_service/artifacts/reports/targeted_remediation_20261006T154647+0800/EVALUATION_AUDIT/freeze_calibration_policy.py
ai_service/.venv-xlmrt-fulltrain/Scripts/python.exe ai_service/artifacts/reports/targeted_remediation_20261006T154647+0800/EVALUATION_AUDIT/audit_evaluations.py
```

The freeze command ran on calibration data only. The audit script refuses to score exposed outputs before verifying the calibration-prediction hash recorded in the frozen policy. Key input hashes are recorded in `EVALUATION_AUDIT.json`, including the new checkpoint metadata and `model.safetensors`.

No deployment approval, checkpoint activation, or label change is implied.
