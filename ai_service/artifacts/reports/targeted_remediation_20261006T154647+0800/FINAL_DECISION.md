# Final decision — targeted XLM-R remediation and manual-only release

**AI decision: NOT_ACCEPTABLE.** The bounded full-backbone run is complete and produces an auditable research checkpoint, but only 2 of the six frozen project gates pass on the historically exposed 84-row regression set. The checkpoint is `NO_ELIGIBLE_CHECKPOINT`; it is not for operational integration, live triage, or deployment.

**Application decision: MANUAL_ONLY code changes and tests are prepared.** The release is not staged, built into a container, or deployed. The server-side Laravel and FastAPI gates disable model influence on report intake and operational severity. The existing active selector is unchanged.

The 850-row source remains a synthetic-origin batch with project-owner-attested verbal confirmation, not independently corroborated responder testimony. No labels or data were changed. The targeted human review package covers the deduplicated union of nine flagged cases, but decisions remain blank because the authoritative rubric text and exact responder decisions were not supplied. The 81 AI non-agree cases remain targeted follow-up.

The best validation checkpoint is epoch 6: validation macro-F1 0.9047, Critical recall 26/31 (0.8387), Serious recall 31/32 (0.9688), and four raw urgent undertriage cases. No checkpoint met all gates. The chosen checkpoint has 278,046,724/278,046,724 trainable parameters and SHA-256 `ce7b62bbe3d4f50d8952f8508b67a6b5adba0a04d5fc0175fc3187639adbbad9`.

On exposed test, n=84, raw macro-F1 is 0.8523; Critical recall is 13/20 (0.65); Serious recall is 21/21 (1.0); raw Serious/Critical undertriage is 7/41; combined Serious/Critical detection recall is 39/41 (0.9512). At the old diagnostic .65 routing threshold, coverage is 57/84, nine auto-handled errors occur, six urgent examples remain undertriaged, and two Critical examples still auto-route to Minor. The exposed challenge has n=43 and is synthetic and historically exposed. No real-world final test exists.

Independent final QA reproduced the six gates using a one-to-one case-ID join against the frozen test CSV. It found that the saved `evaluation_metrics.json` per-language subgroup values disagree with this recomputation, although aggregate confusion/macro-F1 agree. For gate decisions, the case-ID rejoin values are used; the source discrepancy is recorded in `PER_LANGUAGE_RECONCILIATION.json` and has not been hidden or overwritten.

Calibration is low-support synthetic diagnosis only. The calibration-only temperature is 0.96508155. The diagnostic .96 policy accepts 4/83 total examples, including 4/42 high-risk examples; zero observed errors in four automatic high-risk cases is not a safety guarantee. At .97 the rule accepts 0/83 and automates 0/42 high-risk examples, so conditional automation quality is undefined. Neither threshold is approved or selected for operations.

Four AMP updates were skipped during the new run; the fixed scheduler advanced on 260 of 260 successful optimizer updates. This confirms the skip path occurred in this run and the guarded scheduler behaved as intended, but it does not establish that the prior model's errors were caused by scheduler drift. Tokenizer probes showed equal token IDs for eight targeted strings; that is not exhaustive language validation.

The old six-case list overlaps the latest nine; the deduplicated union remains nine. No human case decisions were inferred. The current split has no reconstructed family/group crossings, but every record has prior development exposure. Sixty historical files remain unreadable due to access denial, and prior inventory totals 1,732 vs 2,708 remain unreconciled.

## Release boundary

Automated verification passed: 19 training tests; 21 FastAPI mode/inference tests; 115 focused Laravel tests with 1,267 assertions; Vite build; Python syntax; Render YAML assertions. Independent QA status is PASS_WITH_LIMITATIONS; it does not override the four failed AI gates. This does not replace a persistent-database migration test or runtime smoke test. Docker is unavailable, no isolated staging target is configured, and the required localhost service ports were not listening. No localhost server, persistent database, public endpoint, production environment, or responder was contacted or changed.

The exact active selector value and SHA-256 are recorded in `CURRENT_EVIDENCE_VERIFICATION.json`; the new research checkpoint is separate. Do not modify `active_config.txt` or deploy the new checkpoint as part of this handoff.

## Required next action

Have the authorized responder and fluent Cebuano/Tagalog reviewers complete the text-first and label-decision forms in `CASE_AUDIT/` for the nine-case union, using the actual approved rubric. Separately preserve documented corrections for the 81 AI non-agree rows. Then obtain authorized, de-identified real reports for a genuinely unseen family-safe final test. Keep the current checkpoint inactive and the application in MANUAL_ONLY while those data and release blockers are addressed.
