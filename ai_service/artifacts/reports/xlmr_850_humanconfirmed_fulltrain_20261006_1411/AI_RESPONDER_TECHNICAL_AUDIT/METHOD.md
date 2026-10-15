# 850-row XLM-R responder-style AI audit

**Purpose:** AI-only technical triage of the 850-row owner-attested synthetic batch. This is not responder adjudication and does not change labels.

## Inputs and integrity

- Source: `ai_service/datasets/emergency_text/experiments/civilian_multilingual_6000w_20261005/reports.csv`, SHA-256 `fd0fb65ccb718a1dee93fd315aa3a0e12cb5a8c2123bc08c8466e51c75fc7645`.
- Human label values are copied unchanged from `ai_service/datasets/emergency_text/reviewed/civilian_multilingual_850_humanconfirmed_20261006T141537+0800/reports.csv`, whose 850 IDs and text/labels match the source. They are described as **project-owner-attested verbal batch approval**. The attestation says the owner reports responder(s) accepted the existing text and labels as-is; no recording, transcript, direct responder identity, date/time, or row-level rationale independently verifies that claim.
- Attestation record: `ai_service/datasets/emergency_text/reviews/RESPONDER_850_BATCH_VERBAL_ATTESTATION_20261006T141537+0800/RESPONDER_850_BATCH_VERBAL_ATTESTATION_20261006T141537+0800.json`, batch `OWNER-ATTESTED-850-FD0FB65CCB718A1D`.
- All 850 examples remain synthetic-origin; the approval claim does not make them real-world emergency reports.
- Selected checkpoint: `ai_service/artifacts/checkpoints/text_xlmr_civilian_multilingual_20261005/trial_lr_2e-5_seed42`, XLM-R base, version `text-20261005T135449737752Z`, `model.safetensors` SHA-256 `13ad4e9ec1c45c817fd33082072bf430aabe3afa4571e8b88ed5d0e333269fa2`.

## Model inference

`run_inference.py` loads the checkpoint's local tokenizer/model in evaluation mode, runs a CUDA forward pass in batches of eight, applies softmax to logits, and preserves all four class probabilities. Every row fit the checkpoint's 64-token limit; 850/850 produced a prediction. `model_confidence_raw_softmax` is the maximum raw softmax probability and is **not calibrated**. The model's stored 0.65 review threshold remains provisional: `ai_suggested_label` is the raw model class at or above 0.65 and `needs_review` below it. `model_raw_prediction` retains the raw top class.

`ai_agreement` compares the raw model class with the owner-attested label. A low-confidence routed abstention can be `UNCERTAIN` when the raw top class differs from a non-abstention label. `undertriage`/`overtriage` compare raw severity ranks (`minor < serious < critical`); a prediction of `needs_review` is an abstention, not counted as a severity downgrade.

## Data exposure and metric caution

The frozen experiment partitions cover all 850 rows without overlap: train 516, validation 124, fresh test 84, calibration 83, challenge 43. The model directly trained on train rows; validation was used for checkpoint selection; fresh-test was used for the previously reported held-out diagnostic; calibration and challenge have their own historic roles. This audit scores the whole batch after those prior actions. Therefore full-batch agreement/metrics in `SUMMARY.json` are **descriptive only**, not a fresh evaluation, acceptance result, or safety claim. In particular, the 516 train examples are in-sample and the 124 validation examples affected selection.

## Independent text/rubric heuristics

The CSV separates `rule_flags` and `rubric_heuristic_signals` from model outputs. These are transparent keyword/regular-expression prompts, not NLP adjudications. The prompts encode the current `ai_service/datasets/emergency_text/annotation_guide.md` principles: require stated evidence; keep negation, timing, attribution, multiple people, and person-condition references intact; do not infer injuries from a vehicle or mechanism; do not treat missing injury detail as proof of no injury; preserve `needs_review` for insufficient or out-of-scope text; treat critical terms as reported concerns, not verified death. Literal cue indicators only tell a human where to inspect. `NEGATION_SCOPE_REQUIRES_READING` means a polarity/absence marker was detected; it does not assert that the danger itself is negated (for example, `walay ginhawa` reports absent breathing, while `walay nasamad` reports no injury). The person/object prompts likewise distinguish no stated person condition from an actual ambiguous person reference; a vehicle and a clearly named driver alone are not treated as an ambiguous link.

The repository guide calls itself provisional and is not explicitly versioned as `TEXT-RUBRIC-0.2-B`, while the reviewed dataset/attestation uses `TEXT-RUBRIC-0.2-B`; reconcile this documentation mismatch before future training or rubric-based audits. The present heuristic does not decide superficial-injury boundaries, negate clauses, determine who has which symptom, or resolve fluent Tagalog/Cebuano/code-switching.

`SHORT_PANIC_AI_REVIEW_301.csv` includes all messages of at most eight whitespace-separated words. It is a review queue for brevity/shortcut risk, not a severity set.

## Files

- `RESPONDER_STYLE_AI_AUDIT_850.csv`: one row per case, source-attested label unchanged, model raw/routed outputs, uncalibrated confidence, historical data exposure, model agreement, downgrade/upgrade flags, semantic/person-object/negation/keyword risks, heuristic-only rubric signals, text, and rationale.
- `SHORT_PANIC_AI_REVIEW_301.csv`: short-text subset of the same audit.
- `SIX_HIGH_RISK_CASES_DEEP_REVIEW.json`: reproduced six known fresh-test Critical downgrades with case-specific review focus. The six are model disagreements against attested synthetic labels, not verified label errors.
- `MODEL_PREDICTIONS_850.json`: deterministic output record from this local checkpoint inference run.
- `SUMMARY.json`: hashes, input coverage, metrics with exposure caveats, triage counts, and unresolved limitations.
- `run_inference.py`, `build_audit.py`: reproducible local scripts; the scripts only write to this audit folder.

## Safety boundary

No source data, human-reviewed labels, family links, active config, or checkpoint was edited. No model training, dataset splitting, activation, or deployment occurred. `GOOD` means only that this automatic audit found no configured flag and the raw prediction agreed; it does not mean the report is clinically/operationally correct or safe.

