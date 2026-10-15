# Provisional XLM-R Challenge Intake — 2026-10-05

## Purpose

This folder prepares an independent, human-authored challenge set for the provisional XLM-R emergency-text severity experiment. It targets short panic-style reports, mixed-language urgent reports, and ambiguous wording that the current test set does not adequately cover.

This is an **intake plan**, not a dataset, evaluation, or approval. It contains no generated emergency examples, no assigned severity labels, no reviewer claims, and no model predictions. Do not train on this worksheet. Do not merge it into `working/reports.csv` or any frozen evaluation split.

## Evidence that shaped the plan

- Current `working/reports.csv`: 400 fictional draft rows across 99 `group_id` values; all rows have `review_status=draft`.
- Language counts: 120 each for English, Tagalog, and Cebuano, plus 40 mixed-language rows. The mixed subset has only 10 rows per label.
- None of the 400 current reports has eight words or fewer under whitespace tokenization.
- Existing `expansions/short_panic_v1/candidates.csv`: 80 fictional draft candidates across 18 proposed scenario families. Family ancestry is unadjudicated, and the preview is not an independent test set.
- Working policy: `TEXT-RUBRIC-0.2-B` (Policy B). Its file explicitly states formal accountable approval is pending. Follow it as the current review rubric, and record any policy question rather than silently changing it.
- The Colab summary reported a 42-row synthetic test set and only three mixed-language test rows; it did not establish performance on mixed-language serious/critical reports. The full checkpoint weights were not present in the metrics-only summary used for this intake preparation.

## How to use the worksheet

1. Have fluent English, Tagalog, and Cebuano responders or language reviewers author or verify each report in the requested stratum. The scenario guidance is a coverage prompt, not a script. Use natural wording and avoid translating one source sentence mechanically into multiple languages.
2. Use fictional examples or properly de-identified, authorized reports. Do not enter names, phone numbers, exact home addresses, or other identifying details.
3. Keep each independently designed incident in a distinct new scenario family. Before considering any item independent, compare its normalized text and meaning against `working/reports.csv`, `short_panic_v1/candidates.csv`, and prior holdouts. A different wording or new ID alone does not prove independence.
4. Leave `proposed_final_label` blank during initial collection. Two qualified reviewers should independently label each completed text using the same `TEXT-RUBRIC-0.2-B`; preserve `deferred` or `unresolved` when the language or policy is unclear. Never convert missing review into `needs_review`.
5. Record the original language and any meaning-affecting code-switch. Have a fluent reviewer verify the text before severity review. Do not treat translations/paraphrases of one event as independent split groups.
6. Adjudicate all label disagreements, every human Critical judgment, and the rubric's flagged ambiguity boundaries before a future evaluation set is frozen.
7. Keep all related variants, translations, and counterfactuals in one dependency group and one eventual data split. Freeze the groups, labels, rubric version, and text before running the model. Evaluate the held-out challenge set once; do not tune against it.

## Coverage in the blank intake worksheet

The 24 blank slots include:

- 12 mixed-language slots: English–Cebuano, English–Tagalog, and Cebuano–Tagalog. Each pair is requested in serious/critical-relevant urgent scenarios, with short-message targets.
- 12 single-language slots: four each for English, Tagalog, and Cebuano, spanning the Policy B superficial-injury boundary, uncertain bleeding, physical versus digital nonresponse, and ambiguous entrapment or inability to move.
- Target message length is at most eight whitespace-separated words for every slot. Reviewers may record a naturally longer report if shortening would remove decisive information; explain that in the notes.

These are **coverage targets**, not quota labels. A reviewer must determine severity from the text and rubric. Do not force a class balance or infer severity from the slot title.

## Safety and acceptance limits

- The current model output is provisional research evidence. A small synthetic test score does not establish real-world accuracy, language fairness, or operational safety.
- Do not represent responder feedback as formal model approval unless it is documented against the exact model/checkpoint, rubric, test protocol, and intended use.
- This challenge intake cannot establish acceptance thresholds by itself. Before future use, agree on the intended role of the model, human override and escalation behavior, minimum per-language and high-risk sample sizes, and acceptance criteria with accountable responders.
- Keep the model advisory. The AI must not delay emergency dispatch or replace human triage.

## Files

- `challenge_intake.csv`: 24 blank, structured slots for qualified human authors and reviewers.
- `receipt.json`: scope, source fingerprints, counts, and explicit non-approval status.

