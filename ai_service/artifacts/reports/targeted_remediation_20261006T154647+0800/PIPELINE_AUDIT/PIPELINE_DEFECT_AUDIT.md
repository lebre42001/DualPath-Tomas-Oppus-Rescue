# Track A training and inference pipeline audit

Status: read-only audit complete. No GPU/model inference, active selector/config/data/code changes, or retraining.

## Summary

No demonstrated label-order, destructive text preprocessing, truncation, loss-weight ordering, partial accumulation, train/eval mode, checkpoint selection/loading, or Adafactor scheduler configuration bug was found. The current error pattern therefore should not be attributed to a label-index or tokenizer defect without new evidence. Training support is balanced and class weights are almost 1.0, so class imbalance is not supported as the explanation for low Critical recall.

One actionable conditional defect was found: in CUDA AMP, `GradScaler.step(optimizer)` can skip an optimizer update after detecting nonfinite gradients, but training calls `scheduler.step()` unconditionally and records no skipped-step or scaler telemetry. No existing log proves this happened in the completed run. Fix and unit-test this before a new bounded experiment; do not describe it as the cause of the observed errors.

## Verified implementation

- Four-label order is consistent from `text_training.LABELS` through model `id2label`/`label2id`, targets, class weights, saved metadata, evaluation validation, and API labels.
- Source text is read from UTF-8 CSV; only outer whitespace is stripped. NFKC/casefold/whitespace canonicalization is confined to fingerprints and duplicate checks; original text is passed to XLM-R. No negation-removal or translation rewrite found.
- Tokenizer and model load from the same pinned XLM-R base revision and tokenizer files are saved with the selected checkpoint. Dynamic batch padding is used. Training truncates to max_length=64 but metadata says 0 rows truncated for train/validation/test; evaluation and runtime instead reject overlength content to review. Earlier eight tokenizer probes are helpful but not exhaustive.
- Targets, class weights and loss share `(minor, serious, critical, needs_review)` order. Training class weights: .9961, .9885, 1.0039, 1.0118. Weighted cross-entropy uses fp32 logits and normalizes on total target weight per accumulation window.
- With 514 rows, batch size 1 and accumulation 16 there are 32 complete windows and one final 2-microbatch window per epoch; source handles it and steps on end-of-loader. Planned steps: 33 per epoch, 264 total.
- Training and validation modes are switched correctly. Checkpoint selection reads validation only, then reloads the saved selected checkpoint. Predictor uses eval and inference mode.
- Adafactor specifies explicit LR with `scale_parameter=False`, `relative_step=False`, and `warmup_init=False`; the separate linear warmup/decay scheduler therefore is not double-applied. Run config shows 14 warmup / 264 steps.
- Structural full-backbone trainability is recorded as 278,046,724/278,046,724 trainable params. The run did not capture gradient norms or tensor update checksums, so actual per-layer updates cannot be independently audited from these artifacts.

Code references: `ai_service/src/ai_service/text_training.py` lines 21, 28-30, 41-57, 243-267, 346-360, 364-435, 442-507; `ai_service/src/ai_service/text_inference.py` lines 11, 31-62, 101-136.

## Existing run facts recomputed from saved evidence

- Fresh test: Critical recall 11/20=.55; five Critical→Minor, four Critical→Serious; 9/41 Serious/Critical raw undertriage.
- No train-time truncation; class weights nearly uniform.
- Validation-selected epoch 6; full trainable parameter count 278,046,724.
- Final accumulation window is correctly represented by code: 2 items after 32 full 16-item windows.

`TRAINING_HISTORY.json` train_loss should be read only as an unweighted per-microbatch CE diagnostic under the observed batch size of one; `text_training.py:464` rescales each item by its own class weight. This does not change the weighted backward objective but means the printed train-loss scalar is not the accumulated weighted objective.

## Recommended bounded remediation

1. First, fix AMP step accounting and instrument scaler scale, finite gradient norm, skipped optimizer updates, and actual optimizer-step count; add tests using a controlled/mock overflow to ensure the LR scheduler advances iff the optimizer updates. This improves correctness and evidence but is not shown to explain the nine errors.
2. Prepare exact-ID blinded responder reconciliation for the current nine undertriage cases plus the 81 AI disagree/uncertain rows, unioned with older six diagnostics. Preserve the project-owner batch attestation separately; no rewrite or AI alternative inherits it.
3. If real human corrections exist, include only documented corrections in a versioned training dataset. Keep current exposed test/challenge rows out of training and selection. If no corrections are provided, do not manufacture target labels or add synthetic paraphrases as attested rows.
4. For a bounded development experiment, pre-register a single controlled objective comparison (balanced CE versus a responder-/protocol-defined Critical cost) on the frozen training partition, fixed seeds and validation-only selection. Do not pick a cost multiplier after looking at the exposed test. Report false-critical and urgent-recall tradeoffs; no activation.
5. Record representative backbone gradient norms and pre/post update checksums on the new run. Keep one GPU-heavy process at a time.

Risk: higher Critical cost may raise Critical recall while increasing false escalation. Since the corpus is synthetic-origin and previously exposed in project development, another run remains research-only and cannot establish operational safety.

## Commands/evidence

Read-only searches and inspections were run with `rg` and PowerShell `Get-Content` against the code and artifacts listed above. No GPU or inference process was launched. Full machine-readable findings are in `PIPELINE_DEFECT_AUDIT.json`.
