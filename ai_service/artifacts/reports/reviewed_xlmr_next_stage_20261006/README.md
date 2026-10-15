# Human-review preparation package

This package prepares the 850 synthetic draft emergency descriptions for genuine responder and language review. It does not assert that any draft label is correct. No human decisions are prefilled.

## Start here

1. Use `HIGH_RISK_REVIEW_PRIORITY.csv` first for the six held-out Critical undertriage errors.
2. Continue using `RESPONDER_REVIEW_PRIORITY.csv` and enter only actual reviewer decisions in `RESPONDER_HUMAN_REVIEW.csv`.
3. Use the language sheets separately for naturalness and meaning; do not use language-review judgment as a severity decision.
4. Review `SHORT_PANIC_REVIEW_PRIORITY.csv` as a human queue. Its flags are lexical screening candidates, not verified semantic findings.
5. The mixed-language worksheet is `MIXED_LANGUAGE_HUMAN_REVIEW_INTAKE.csv`; its heuristic language tags must be verified by a fluent reviewer.

## Responder fields

Allowed `human_decision`: `APPROVE`, `REVISE`, `UNRESOLVED`. Keep blank until that row is actually reviewed. For `REVISE`, fill `human_corrected_label` and keep the old label in `current_label`. `immediate_attention` should reflect the responder's judgment and the adopted rubric, not the model alone. Record reviewer identity and a timestamp. Use `UNRESOLVED` when the evidence is insufficient; do not force a label.

## Language fields

Use `natural`, `mostly_natural`, `awkward`, or `unclear` for naturalness. Record meaning clarity and any correction without overwriting `original_text`. Severity is a separate responder-review function.

## Known limitations

All 850 candidates remain synthetic drafts. Raw model confidence is uncalibrated. The worksheet contains model predictions only for the 84 examples in the existing held-out test file; model predictions are deliberately omitted for the 766 in-sample training rows. The current experiment had 84 fresh-test examples with 6 raw Critical undertriage errors, only 21 Critical/21 Serious rows, and only 12 mixed-language test rows. The selected checkpoint is inactive and not deployment-approved.

No reviewed dataset, frozen split, retraining, active-model switch, or deployment was performed.


## How the ordered responder queue handles overlapping priority groups

`RESPONDER_REVIEW_PRIORITY.csv` uses six non-duplicating primary stages: (1) six held-out Critical undertriage cases, (2) other Critical drafts, (3) Serious drafts, (4) needs-review drafts, (5) Minor drafts with lexical boundary cues, and (6) remaining drafts. The requested short-report and mixed-language Critical/Serious groups overlap with stages 1-3, so they are surfaced as secondary `priority_tags` instead of being duplicated or placed after the entire Critical/Serious queue. These tags are review-routing hints, not human findings.

The responder sheet includes raw predictions for the 84 held-out test examples only. Those are uncalibrated experimental model outputs and are shown as suggestions; they are not review decisions. The other 766 examples are not model-scored in this sheet because the selected model saw them during training.

The short/panic sheet contains all 674 rows in the 1-3, 4-8, and 9-15 word buckets. A blank lexical-flag field means no listed heuristic fired, not that the case needs no review.
