# Final decision: blocked pending required evidence

This was a completed exploratory, synthetic-draft training experiment. It did not create a human-validated severity model. Final technical decision: `BLOCKED_PENDING_REQUIRED_EVIDENCE`.

The 850 descriptions (7,795 primary-language words plus 1,725 separate mixed-language words) remain `synthetic_ai_generated` and `draft`. No human-confirmed rows were included. Three full-backbone XLM-R learning-rate trials ran; the validation-only frozen rule selected `trial_lr_2e-5_seed42`, best epoch 6. The 84-row fresh test reached macro-F1 0.878, but Critical recall was 0.714 and raw Serious/Critical under-triage was 6/42. The proposed research acceptance gates therefore fail. Calibration and challenge sets are synthetic and small; calibration did not change the checkpoint. Language and short-text results have low support, and the independent historical overlap scan could not read 60 CSV copies.

Do not activate this checkpoint, use it to make dispatch decisions, or describe it as production ready. The existing active model/configuration were left unchanged, and no deployment was performed.

## Exact next action

Have a responder and fluent English, Tagalog, and Cebuano/Bisaya reviewers independently review the 850 draft cases using the current rubric; adjudicate high-risk disagreement and semantic-family links. Record genuine review provenance. Only after accepted data and prior-source access gaps are resolved should a newly versioned dataset and split be created and a fresh experiment run.
