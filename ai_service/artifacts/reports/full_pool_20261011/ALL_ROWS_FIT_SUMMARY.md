# All-rows emergency text fit ? 2026-10-11

**Completed: 8 epochs on every selected row. Offline experimental checkpoint; no evaluation performed and no deployment approval.**

- Rows used: **1366 / 1366** across 1021 groups.
- Review status: **850 approved, 516 draft**. Draft labels remain unverified; they were included only under the explicit full-train request.
- Model: `FacebookAI/xlm-roberta-base` revision `42f548f32366559214515ec137cdd16002968bf6`; full backbone, CUDA, seed 42, batch 1 ? accumulation 16.
- Epochs: 8; final weighted training objective 0.0461. This is a training loss, **not an accuracy score**.
- Optimizer steps: 683 succeeded, 5 skipped for mixed-precision overflow.
- Input truncation: **82** descriptions exceeded the 64-token limit.

## Important interpretation

This fit used all rows, so it has no held-out test score. The earlier split-run candidate had macro-F1 0.945 and Critical recall 0.895 on a historically exposed, mostly synthetic-origin test split, with 2 serious/critical under-triage cases. Those numbers describe the earlier split-run checkpoint, **not this all-rows checkpoint**. Keep this candidate inactive; its metadata marks draft data and no deployment approval.

Checkpoint: `artifacts/checkpoints/full_pool_20261011/xlmr_all_rows_final_fit`  
Training manifest: `artifacts/checkpoints/full_pool_20261011/xlmr_all_rows_final_fit/training_manifest.json`  
Source manifest: `datasets/emergency_text/experiments/full_pool_20261011/source_manifest.json`  
Prior split-run diagnostic: `artifacts/reports/full_pool_20261011/RUN_SUMMARY.md`
