# Emergency text XLM-R provisional experiment evaluation

Date: 2026-10-05 (Asia/Manila)

Decision: **Research run completed and technically auditable. The preset acceptance gates did not all pass. This evidence does not support live severity automation.**

## Evidence and provenance

- Evaluated the user-returned `C:\Users\Christian\Downloads\emergency_text_xlmr_summary.zip` (SHA-256 `bd15db9439bcd14382e69a3e105a3c28b101af1f4241f28e35e9a5040eae1189`). The archive contained 34 readable JSON entries and passed ZIP integrity and JSON parsing checks.
- Package: `emergency-text-xlmr-provisional-20261004`; saved `RUN_COMPLETE.json` has `complete: true` and selects seed 42. The run receipt names sealed bundle SHA-256 `197f16b0cf0a01867c3dae2f5a5945724214b254d6f7d054da8b5c2b7854969d`, matching the local package receipt. The selected model metadata names `FacebookAI/xlm-roberta-base` at revision `42f548f32366559214515ec137cdd16002968bf6` and reports a fully trainable backbone.
- The ZIP is a **metrics summary**. It does not contain `model.safetensors` or the complete tokenizer. The model weights remain in the user's Google Drive. Thus this audit checks the saved metrics and receipts; it cannot independently hash the full checkpoint or rerun inference from the ZIP. The repository's full-artifact validator requires that checkpoint.
- The saved split has 304 train rows in 73 groups, 54 validation rows in 13 groups, and 42 test rows in 13 groups. The recorded group hashes and normalized-text hashes are disjoint between all three splits. That check does not establish that unseen real incidents are independent of the synthetic scenario templates.

## Results

| Measure | Saved result | Interpretation |
| --- | ---: | --- |
| Test accuracy and macro-F1 | 42/42 correct; macro-F1 1.00 | Performance on this synthetic test only. |
| Critical recall | 6/6 | Too few Critical cases to establish reliable field performance. |
| Serious or Critical detection recall | 18/18 | No observed high-risk miss on these synthetic cases. |
| Raw high-risk under-triage | 0/18 | Does not establish a real-world zero error rate. |
| English, Tagalog, Bisaya | 13 test rows each; macro-F1 1.00 each | Small language-specific samples. |
| Mixed language | 3 test rows; macro-F1 0.50 | All 3 predictions were correct, but this split contains only 1 Minor and 2 Needs Review cases. Serious and Critical have zero support, and the fixed four-class macro-F1 counts both absent classes as zero. There is no mixed-language urgent-case measurement. |
| Confidence threshold | 0.65; coverage 28/42 (66.7%) | Threshold is marked uncalibrated in model metadata. |

On validation, seed 42 has 54/54 correct and macro-F1 1.00. Seed 43 also reached 1.00; the saved tie rule selected the lower seed. Seed 41 had macro-F1 about 0.942. The TF-IDF baseline had validation macro-F1 about 0.980; this is not an independent test comparison with the selected XLM-R model.

`ACCEPTANCE_REPORT.json` agrees with the underlying test metrics and records `metrics_passed: false`. Four of five checks pass; the check requiring every observed language's macro-F1 to reach 0.75 fails because mixed language is 0.50. The gate remains failed. The lack of Serious/Critical support in mixed-language test data is a test-design gap; it is not evidence of either safe or unsafe mixed-language urgent classification.

## Human feedback and limits

The project owner reported on 2026-10-05 that they spoke with the responder client and the client said the result was “goods.” This is useful product feedback, but the conversation does not identify the checkpoint, test examples, review protocol, decision date, or scope of that approval. It is recorded here as **project-owner-reported verbal feedback**, not as signed acceptance of model labels or deployment.

The training dataset remains 400 synthetic/draft rows, with zero approved reviewed rows in the saved metadata. The separate short-panic review package's last recorded readiness report also does not establish completed independent human reviews. Therefore the experiment does not demonstrate agreement with independently adjudicated emergency labels or real incoming reports. A high score on generated examples may reflect their limited wording and shared construction patterns.

For a consequential triage assistant, a representative independent test set, clear human role, and relevant-use testing are sensible evaluation practices. This recommendation is informed by the [WHO's health-AI governance guidance](https://www.who.int/publications/i/item/9789240037403) and the [FDA-hosted Good Machine Learning Practice principles](https://www.fda.gov/medical-devices/artificial-intelligence-enabled-medical-devices/good-machine-learning-practice-medical-device-development-guiding-principles); citing these principles does not assert this project is regulated as a medical device.

## Next evaluation milestone

1. Record the responder's actual review of the **specific** seed-42 checkpoint and the severity rubric, including what “goods” covered. Keep reviewer identity and date traceable.
2. Independently label a fresh set of permitted road-accident descriptions from realistic use, including short panic wording, negation, ambiguous cases, and code switching. Ensure mixed-language Serious and Critical cases have enough support for separate estimates. Review translation and scenario-family relationships before sealing a holdout.
3. Keep calibration data separate from the final holdout. Set the confidence threshold and coverage target using calibration data, then evaluate the unchanged selected model once on a fresh, family-disjoint final holdout with human-reviewed labels.
4. Report confusion matrices, Critical and high-risk misses, false alarms, language-specific support, review workload, and responder corrections. Apply the pre-agreed release criteria to those results before any promotion decision.

This evaluation does not alter `active_config.txt`, the dataset, labels, checkpoints, Laravel application, or deployment.
