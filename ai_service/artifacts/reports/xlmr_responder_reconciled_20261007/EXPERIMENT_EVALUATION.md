# XLM-R candidate evaluation ? 2026-10-07

Status: **research candidate only; do not activate or deploy**.

## Dataset and split

- Version: `civilian_multilingual_850_responder_reconciled_20261007`; 850 unique cases and 832 conservative split groups.
- Labels, language, normalized text, and current scenario-family IDs matched 850/850 between the supplied review and canonical records. The reviewed label matched the existing label on all 850 rows, so no new training examples or label changes were created.
- Decisions: 840 confirm, 10 override AI. The 10 overrides had AI predictions; 766 rows had no prediction/confidence and cannot count toward AI correctness evaluation.
- Frozen split (seed 20261007): 600 train / 123 validation / 127 test. No case ID, group, or normalized text overlap.
- The set was already used by prior model-development workflows. This test split is held out from this run, but is **not an independent external validation**. Texts remain synthetic-origin.

## Training

Full-backbone `FacebookAI/xlm-roberta-base` fine-tuning, pinned revision `42f548f32366559214515ec137cdd16002968bf6`, seed 41, max length 64, batch size 1, gradient accumulation 16, 8 epochs maximum, early stopping patience 2, balanced-safety validation selection. Early stopping ended after epoch 4 and selected epoch 2. Checkpoint: `artifacts/checkpoints/text_xlmr_responder_reconciled_20261007/seed_41`.

## Frozen test results

- Accuracy: **59.1%**
- Macro-F1: **0.579**
- Critical recall: **93.9%** (33 Critical cases)
- Serious recall: **48.4%** (31 Serious cases)
- Serious/Critical detection recall: **95.3%**
- Serious/Critical under-triage: **3 / 64**
- Responder-routing coverage at the provisional 0.65 threshold: **0.0%**; all test predictions abstained to `needs_review`.

Raw per-language macro-F1: ceb 0.578 (n=39), en 0.703 (n=40), mixed 0.394 (n=17), tl 0.459 (n=31).

## Decision

All predefined promotion-style metric gates fail: overall macro-F1 ? 0.80, Critical recall ? 0.95, zero raw high-risk under-triage, each observed language macro-F1 ? 0.75, and nonzero routed coverage. Therefore this checkpoint must remain inactive. It may be used only for controlled, responder-supervised research; do not present it as accurate or ready for live severity decisions.

## Human and data limitations

The user attests that a real responder completed the worksheet. The supplied ID `SIM_HUMAN_REVIEW_001` is preserved verbatim, but identity was not independently verified. The same timestamp appears on every row and is treated only as the supplied batch time. All text examples are synthetic-origin and do not establish real-incident representativeness.

## Files

- Reconciled dataset: `datasets/emergency_text/reviewed/civilian_multilingual_850_responder_reconciled_20261007`
- Machine-readable evaluation: `artifacts/reports/xlmr_responder_reconciled_20261007/EXPERIMENT_EVALUATION.json`
- Test metrics/predictions: `artifacts/reports/xlmr_responder_reconciled_20261007/seed_41_test_evaluation`
- Candidate checkpoint: `artifacts/checkpoints/text_xlmr_responder_reconciled_20261007/seed_41`
- Active model/config changed: **No**
- Deployment approved: **No**
