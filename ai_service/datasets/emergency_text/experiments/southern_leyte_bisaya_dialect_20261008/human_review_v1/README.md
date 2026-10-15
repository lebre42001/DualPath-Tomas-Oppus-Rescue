# Human review package ? Southern Leyte Bisaya draft v1

This package contains 36 AI-generated fictional drafts. It is for review only; it is not real incident data, gold-standard labels, or an approved training set. The source CSV has 2,000 whitespace-separated text words at creation. Editing sentences will change that count; record the revised count after review.

## Blind review sequence

1. Give `language_reviewer_a.csv` and `language_reviewer_b.csv` to two fluent speakers from the exact target municipality/community. They should work independently. The files intentionally omit proposed severity labels and rationale. Tell them the texts are synthetic drafts, not authentic local reports.
2. Ask them to check naturalness, locality, spelling, code-switching, and whether any rewrite changes the scenario facts. Preserve original wording and record each proposed revision; do not silently overwrite the source.
3. After language review, provide the reviewed text (with changes tracked) to two qualified responders in `responder_reviewer_a.csv` and `responder_reviewer_b.csv`. They should label independently using the approved rubric and leave uncertain rows unresolved rather than guessing.
4. Compare responder decisions in `adjudication.csv`. Adjudicate disagreements, especially any Critical decision, high-risk under-triage, negation, uncertain injury, or language issue. Do not auto-approve.
5. Use `coordinator_mapping.csv` only as a coordinator file; it contains the original proposed labels and draft metadata. Do not send it to blinded reviewers before they finish.
6. Keep reviewer IDs, locality, review timestamps, rubric version, revisions, rationale, and provenance. Never put personal details from real emergencies into these draft files.

Allowed labels for responder review: `minor`, `serious`, `critical`, `needs_review`. A blank label with status `pending` means not yet reviewed; it is not equivalent to `needs_review`.

No training, merge, model promotion, or deployment is authorized by these worksheets. After human review, perform a separate dataset and safety evaluation before considering any training experiment.

## Imported AI-assisted review (2026-10-08)

The ZIP review artifacts are an AI-only preliminary review, not completed human review. All 36 entries still require local-language and qualified responder validation. The AI suggested label changes for SLB-DRAFT-005 (`minor` to `needs_review`) and SLB-DRAFT-019 (`serious` to `critical`); neither change is accepted as a final label. The proposed rewritten text has 1,910 whitespace-separated words and remains unverified. See `AI_REVIEW_REPORT.md`, `AI_PRE_REVIEW_36.csv`, `AI_LANGUAGE_REVIEW.csv`, `AI_RESPONDER_REVIEW.csv`, and `HUMANIZED_TEXTS_36.csv`. Do not show the `AI_*` files or AI-suggested labels to independent reviewers until they have submitted their own blind sheets. Keep the original source descriptions and blank independent-review worksheets unchanged until actual reviewers complete them.
