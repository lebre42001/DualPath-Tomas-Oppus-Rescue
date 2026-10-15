# Final decision — 850-row human-confirmed synthetic XLM-R experiment

**Decision: NOT_ACCEPTABLE for live triage or controlled operational integration.** Full-backbone retraining completed, but the frozen fresh-test safety gates failed. Keep the checkpoint inactive and do not deploy it.

## Evidence and provenance

The source was `ai_service/datasets/emergency_text/experiments/civilian_multilingual_6000w_20261005/reports.csv`, SHA-256 `fd0fb65ccb718a1dee93fd315aa3a0e12cb5a8c2123bc08c8466e51c75fc7645`. It has 850 unique synthetic-AI-generated descriptions. The project owner attests that responders verbally accepted all 850 texts and existing labels. This is an owner-reported batch attestation, not directly or independently corroborated responder testimony. No individual responder identity, audio/transcript, row-specific rationale, or correction was supplied. Codex did not impersonate a responder. The attestation applies only to this exact batch; synthetic origin is preserved.

The reviewed snapshot has 850 unchanged text/label rows: minor 213, serious 214, critical 211, needs_review 212; English 250, Tagalog 240, Cebuano 240, mixed 120. It preserves 833 declared scenario-family IDs. AI technical adjudication reports 769 AGREE, 38 DISAGREE, 43 UNCERTAIN (81 not-AGREE); no AI finding is a human label correction. The AI language audit covered all 850 rows, but fluent human language validation remains outstanding. 674 rows contain 1–15 words.

Family reconciliation considered 41 candidate pairs: 19 were recommended SAME_SCENARIO_FAMILY and 22 RELATED_BUT_DISTINCT. These AI-supported links were used only to keep split components together; source family IDs were not rewritten. Frozen partitions are train 514, validation 126, calibration 83, fresh test 84, challenge 43. Independent leakage QA found no overlap across audited current split identifiers, normalized texts, or effective family edges. However, this corpus was used across prior model-development partitions; the new test is isolated for this run, not historically unseen. Sixty historical CSVs remain permission-denied; cause and overlap are unknown. No access settings were changed.

## Training

Full fine-tune of pinned `FacebookAI/xlm-roberta-base` revision `42f548f32366559214515ec137cdd16002968bf6`: 278,046,724 / 278,046,724 parameters trainable, RTX 3050 Laptop GPU, CUDA FP16, Adafactor, LR 2e-5, batch 1 / accumulation 16, gradient checkpointing, max length 64. Eight epochs completed; validation-only selection chose epoch 6; duration 963.81 seconds. Tokenizer warning was checked against XLM-R with 8 multilingual/negation probes and zero token-ID mismatches; no Mistral-specific fix was applied.

Checkpoint: `ai_service/artifacts/checkpoints/text_xlmr_850_humanconfirmed_fulltrain_20261006_1411/`

Weights SHA-256: `053a2851d3e1a9fb342825bc86a786a49baad7e06f51c6a8442a655f97fe6881`

## Fresh-test results and safety

On 84 fresh-run test rows: raw macro-F1 0.8456; Critical recall 0.550 (11/20); Serious recall 1.000 (21/21); Serious-or-Critical detection recall 0.878. Errors include 5 Critical→Minor and 4 Critical→Serious. Raw urgent undertriage was 9/41 (21.95%). At the existing provisional, uncalibrated 0.65 routing threshold, two Critical cases still auto-route to Minor and six high-risk cases remain undertriaged.

Raw test macro-F1 by language: English 0.912, Tagalog 0.908, Cebuano 0.775, mixed 0.729. Mixed support is only 12 (three per class) and Critical recall is 1/3. Test length buckets 1–3, 4–8, 9–15, 16+ have 7, 29, 33, 15 rows; Critical recall is 0.50, 0.714, 0.50, 0.333. These small slices cannot establish safety. `needs_review` support is 22, precision 1.0, recall 0.909; this does not remove dangerous undertriage elsewhere.

Calibration used only the separate 83-row partition. Temperature scaling selected T=1.05; ECE changed 0.1089→0.0988; checkpoint weights did not change. Threshold results are small-sample diagnostics only. At 0.65, calibration coverage was 88.0% with 3 undertriage. At 0.95, zero observed undertriage came with 15.7% total coverage and only 1/42 urgent examples auto-covered. No threshold was promoted.

Six historic failures: zero fixed, four still undertriaged (CEB-CRITICAL-001, TL-126, CEB-CRITICAL-024, MX-CRITICAL-013), and two routed to review (CEB-CRITICAL-003, MX-CRITICAL-002). On a matched 83-row subset, previous XLM-R scored macro-F1 0.875 / Critical recall 0.70 / Serious recall 0.952 / 6 undertriage; the new model scored 0.844 / 0.55 / 1.00 / 9. Same-split TF-IDF was blocked by Windows Application Control; an older 400-row baseline is not comparable. Challenge results cover 43 curated synthetic rows and were not used for selection.

## Acceptance and next work

Only 1/6 frozen research gates passed (overall macro-F1 ≥ .80). Critical recall ≥ .95, urgent detection ≥ .90, each-language macro-F1 ≥ .75, zero raw urgent undertriage, and zero automatic Critical→Minor all failed. These are project research gates, not clinical standards. Active model/config is unchanged, deployment was not performed, and approval remains false.

Next: obtain qualified responder adjudication of the 81 AI-disagreement/uncertain rows and six regression cases; fluent human review of English, Tagalog, Cebuano and mixed language; investigate historical-source access via authorized project access; and collect consented, de-identified real reports with responder labels. Create a separate versioned corpus and genuinely future family-safe holdout unseen by prior model runs, then repeat training and safety evaluation with gates frozen in advance. Do not change or reuse the current test examples as training data based on these results. Maintain human override and fail-safe review in any future evaluation.

Final independent artifact QA: PASS_WITH_LIMITATIONS. The reviewer verified reviewed dataset file hashes and handoff freshness, and independently reproduced core source/checkpoint/evaluation evidence. Operational model acceptance remains NOT_ACCEPTABLE; this is not deployment approval.

