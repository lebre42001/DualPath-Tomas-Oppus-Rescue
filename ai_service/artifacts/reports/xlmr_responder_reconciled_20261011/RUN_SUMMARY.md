# XLM-R full-backbone candidate runs ? 2026-10-11

**Status: research candidates only; not approved for activation or deployment.**

## Run scope

Full-backbone fine-tuning completed for seeds 42 and 43 using the existing 850-row responder-reconciled dataset and its frozen 600/123/127 train/validation/test split. The 36 Southern Leyte Bisaya AI-draft examples were excluded. Checkpoints were selected using validation only.

The frozen test split was **not rerun**: it was already evaluated in the earlier seed-41 experiment and has prior model-development exposure. These results therefore do not provide a new independent test estimate.

## Validation results

| Seed | Selected epoch | Macro-F1 | Critical recall | Serious recall | Raw high-risk under-triage | Coverage at provisional 0.65 |
|---|---:|---:|---:|---:|---:|---:|
| 41 (prior run) | 2 | 0.532 | 0.935 | 0.419 | 4 | 0.0% |
| 42 | 4 | 0.817 | 0.871 | 0.806 | 2 | 53.7% |
| 43 | 8 | 0.895 | 0.935 | 0.871 | 2 | 73.2% |

Seed 43 had the strongest validation macro-F1. Its per-language validation macro-F1 was English 0.875, Tagalog 0.949, Cebuano 0.854, and mixed 0.877. The Critical-recall target of 0.95 and zero-under-triage target were not met. Thus even the best validation candidate fails key safety gates.

## Decision and limitations

- Keep the active model and configuration unchanged; maintain manual responder severity.
- Do not call seed 43 accurate or production-ready based on validation results.
- The source narratives are synthetic-origin, and the responder identity is user-attested but not independently verified.
- The review threshold is provisional and not calibrated.
- Obtain a new, independent, de-identified real-world test set and responder adjudication before any deployment decision.

Machine-readable metrics: `RUN_SUMMARY.json`. Candidate checkpoints are under `artifacts/checkpoints/text_xlmr_responder_reconciled_20261011/seed_42` and `seed_43`.
