# AI-assisted preliminary review — 36 fictional emergency narratives

**Status: NOT human approved.** The author of this file is an AI assistant, not a local fluent speaker or certified emergency responder. The original templates for Language Reviewers A/B, Responder Reviewers A/B and Adjudication remain **UNALTERED** and retain pending status. No independent human review, locality verification, or rubric-based professional adjudication has occurred.

## Summary

- Date of AI draft review (Asia/Manila): 2026-10-08T10:01:41+08:00
- Total records examined: 36
- Original labels: {'minor': 9, 'critical': 9, 'serious': 9, 'needs_review': 9}
- Provisional AI labels: {'minor': 8, 'needs_review': 10, 'critical': 10, 'serious': 8}
- Changed original proposed labels: SLB-DRAFT-005, SLB-DRAFT-019
- Records marked for immediate human attention on triage grounds: 22 (IDs: SLB-DRAFT-009, SLB-DRAFT-010, SLB-DRAFT-011, SLB-DRAFT-012, SLB-DRAFT-013, SLB-DRAFT-014, SLB-DRAFT-015, SLB-DRAFT-016, SLB-DRAFT-017, SLB-DRAFT-019, SLB-DRAFT-020, SLB-DRAFT-021, SLB-DRAFT-022, SLB-DRAFT-023, SLB-DRAFT-024, SLB-DRAFT-025, SLB-DRAFT-026, SLB-DRAFT-027, SLB-DRAFT-031, SLB-DRAFT-034, SLB-DRAFT-035, SLB-DRAFT-049)
- Original word count from coordinator metadata: 2000
- Proposed rewritten text whitespace word count: 1910
- Local Southern Leyte variety: NOT VERIFIED; must be validated by fluent community reviewers.
- Labels were suggested from scenario wording; no formal severity rubric was present. These labels are not clinical decisions and are NOT training ground truth.

## Main findings

1. **SLB-DRAFT-019:** Originally serious; ongoing bleeding despite pressure plus pallor/trembling make **critical** a plausible safer provisional label. Must be formally adjudicated by qualified responders.
2. **SLB-DRAFT-005:** Source says the rider 'nahulog' yet 'wala siya matumba'. Kept the contradiction visible and provisionally changed label to **needs_review** rather than silently invent a resolution.
3. **SLB-DRAFT-024:** Source implies moving or seating a potentially back-injured patient. Reworded as a **reported** action, not guidance; verify with responders and correct unsafe handling advice.
4. **SLB-DRAFT-021/026:** Head-injury vomiting and post-impact chest pain require urgent clinical review; serious is **provisional**, and severity may be escalated if clinical signs warrant it.
5. **SLB-DRAFT-034/035/049:** Still **needs_review** because facts are incomplete, but unresolved breathing, possible loss of consciousness, or an unverified call for help means **immediate follow-up** should be considered. "needs_review" must not imply low urgency.
6. Recurring language problems include initial **"Ag"** instead of **"Ang"**, mixed Cebuano and English phrasing, misspelling **"batiis"**, repetitive negations, and unnatural narration. Proposed rewrites keep their synthetic provenance and must be assessed for municipality-specific vocabulary.

## Files

- `AI_PRE_REVIEW_36.csv`: full 36-record case-by-case review, rewritten wording, provisional label and rationale, safety flags and provenance.
- `HUMANIZED_TEXTS_36.csv`: human-readable proposed edits only.
- `AI_LANGUAGE_REVIEW.csv`: draft language-review suggestions; NOT a completed human language reviewer sheet.
- `AI_RESPONDER_REVIEW.csv`: draft responder-like reasoning; NOT a completed human responder review.
- `coordinator_mapping.csv` and all original reviewer and adjudication CSVs: **unchanged**, for the genuine independent/blinded workflow.

## Required real review before any approval

1. Have two independent fluent speakers from the intended Southern Leyte community review wording and meaning, using the original unfilled `language_reviewer_a.csv` and `language_reviewer_b.csv`.
2. Have two qualified emergency responders independently apply an approved and named triage/severity rubric to the revised language, especially high-risk and ambiguous items.
3. Resolve label disagreements in `adjudication.csv` with a named human adjudicator, including dates and rationales; recheck source facts and locality for 005.
4. Only after sign-off consider updating the coordinator records to approved status; perform an additional dataset safety and validity evaluation before training or deployment. Preserve the original synthetic-data provenance.
