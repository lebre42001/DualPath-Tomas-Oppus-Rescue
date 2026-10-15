# Southern Leyte Bisaya accident-description draft

This isolated package contains an **AI-generated, synthetic draft** prepared after reviewing public linguistic material about Bisaja/Binisaja. The sources discuss language variety and variation; they do **not** validate these descriptions as natural Southern Leyte speech. Every local-language sentence, label, and scenario remains unverified.

## Draft inventory

- `accident_descriptions_ai_draft.csv`: 36 fictional accident descriptions; exactly **2,000 whitespace-separated words** in the `text` column; 9 provisional examples per label (`minor`, `serious`, `critical`, `needs_review`).
- `SOURCES.md`: research context and source links.
- `emergency_examples_template.csv`: blank schema for future human-authored/reviewed entries.
- `lexicon_review_template.csv`: blank local-term review sheet.

The equal label counts are an artificial draft-design choice, not real-world prevalence. The word count uses Python-style whitespace splitting and does not include headers or metadata.

## Review status and restrictions

All CSV rows are marked `draft`; reviewer fields are blank. Labels are provisional, and local variety is set to `southern_leyte_bisaya_unverified`. Do not call these examples responder-approved, gold labels, or representative of Southern Leyte residents. Do not merge them into `working/reports.csv`, include them in a training run, or use them to promote/deploy a model.

Before any dataset use, arrange for (1) a fluent speaker from the target municipality to verify or rewrite each sentence while preserving its intended facts, (2) a second independent local-language reviewer, and (3) a qualified responder to label the full description under the currently approved rubric. Record reviewer IDs, dates, municipality/variety, provenance, disagreements, and adjudication. Resolve any mismatch with the checked-in annotation guide before accepting labels.

## Required next review

1. Ask local reviewers whether each sentence sounds natural in the intended community; do not ask them to merely proofread standard Cebuano.
2. Have reviewers flag words, spellings, code-switching, and any sentence that changes or obscures the emergency facts. Keep original and corrected text with provenance.
3. Have responders independently label the complete descriptions; adjudicate disagreements and revise rubric ambiguities.
4. Deduplicate and link paraphrases/translations by scenario family, then freeze group-based splits. Keep a separately collected, human-reviewed real-world test set untouched.
5. Only then consider a training experiment, followed by evaluation by language variety and severity, with explicit serious/critical under-triage reporting.

No model was trained, changed, enabled, or deployed while preparing this draft.
