# Targeted human-review worksheets

These two sheets cover the deduplicated union of nine historical high-risk cases. They do not alter the existing 850-row batch attestation and contain no automatic human decisions.

1. Two fluent-language reviewers and an authorized responder should complete HUMAN_TEXT_FIRST_REVIEW.csv without consulting model predictions or probabilities. Use the approved rubric text. The repository evidence currently references TEXT-RUBRIC-0.2-B, but the exact rubric document was not located; retrieve and record the authoritative rubric version before making final severity decisions.
2. Only after the text-first review is recorded, use HUMAN_LABEL_DECISION.csv to compare the existing attested label with the independent judgment. Select KEEP_LABEL, CHANGE_LABEL, or UNRESOLVED. A reason is required for CHANGE_LABEL and UNRESOLVED. Record actual reviewer identity and evidence; verbal decision and transcription times are separate.
3. Do not infer a verbal responder decision from an assigned reviewer ID. No model suggestion is included in the text-first sheet. Model outputs may be compared only after human decisions are recorded.
4. Until completed by the relevant humans, these cases remain unresolved for targeted adjudication. No labels or training records have been changed.
