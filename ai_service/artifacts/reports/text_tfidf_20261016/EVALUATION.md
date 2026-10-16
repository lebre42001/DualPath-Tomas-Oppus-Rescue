# Text severity training and evaluation ? 2026-10-16

Model: **DualPath Trilingual TF-IDF Logistic Regression**, `dualpath-tfidf-20261016-v1`.

Status: explicitly requested experimental admin-supervised pilot. No independent real-report validation. No clinical/production approval. Do not describe the requested 80?85% real-report accuracy as achieved.

## Data and selection

1,366 synthetic-origin descriptions, 1,021 declared groups. Dataset language counts: Cebuano 416, English 390, Tagalog 380, mixed 180. Original source labels and review status preserved: 850 historical approved flags (identity not independently verified), 516 drafts. Nothing was relabeled as human-reviewed by this experiment. Actual website reports were not used as invented training labels.

Group-stratified split with seed 20261016: **947 training / 199 validation / 220 test**. No overlap in declared group IDs, case IDs or normalized text between partitions. Scenarios have prior model-development exposure and possible semantic/template similarity beyond these grouping checks; this is not an independently collected release holdout.

Fixed character-word-boundary TF-IDF 3?5 grams, maximum 20,000 features; balanced multiclass Logistic Regression. Three C values were predefined: 1, 4, 16. Select using validation Critical recall, then macro-F1, matching the existing training priority. C=16 selected: 96.5% validation raw accuracy and 95.0% Critical recall. Test evaluated once after this choice. No retraining on test rows. Cutoff 0.65 was fixed before comparison and remains provisional and uncalibrated.

## Held-out synthetic test results

| Language | Correct / total | Raw classifier accuracy |
| --- | --- | --- |
| English | 64 / 69 | 92.8% |
| Tagalog | 58 / 61 | 95.1% |
| Cebuano/Bisaya | 50 / 57 | 87.7% |
| Mixed | 32 / 33 | 97.0% |
| Overall | 204 / 220 | 92.7% |

Macro-F1: 0.928. Critical recall: 49/51 (96.1%). Raw Serious/Critical underclassification: 0/101 in this sample. Model classes include Needs review; raw accuracy is the four-class argmax score, before the uncertainty cutoff.

At cutoff 0.65, **133/220 (60.5%)** receive a Minor/Serious/Critical suggestion; 131/133 match the synthetic labels (**98.5% accepted accuracy**). The other 87 receive no severity suggestion and require review. Accepted accuracy excludes abstentions and must never replace full-test accuracy. No claim of calibrated confidence, zero operational risk, or real-report representativeness follows from these numbers.

## Reproduction

From ai_service, using Python with requirements-advisory.txt installed:

```powershell
# Repeat into new output directories; never overwrite a completed run.
python train_text.py baseline --data datasets/emergency_text/experiments/full_pool_20261011/reports.csv --output artifacts/checkpoints/reproduce/c16 --allow-draft --seed 20261016 --regularization-c 16 --review-threshold 0.65
python train_text.py evaluate --data datasets/emergency_text/experiments/full_pool_20261011/reports.csv --model artifacts/checkpoints/reproduce/c16 --output artifacts/reports/reproduce/test --allow-draft --split test
```

Use C=1 and C=4 on the same seed/split to reproduce the validation comparison. The numeric model artifact and its checksum are in releases/triage-text-tfidf-20261016. All candidate validation metrics, selected frozen split, source hash, test metrics, confusion matrix and case-level test predictions are retained beside this document. No private witness descriptions or credentials are published in these evidence files.

## Pilot activation and remaining validation

The system owner requested training, evaluation, online suggestions and admin confirmation on 2026-10-16. The lightweight existing baseline was selected because no GPU/transformer checkpoint is available and free local memory is approximately 1.6 GB. This permits an explicitly experimental pilot without pretending that blank reviewer acceptance records are approvals. Metadata retains approved_for_deployment=false and all_data_reviewed=false. Both services must explicitly allow experimental models. Every AI suggestion stays outside final operational severity until an authorized admin confirms/corrects it. No service error may prevent report receipt or silently assign a final label. Human severity reviews are never overwritten by reanalysis.

Fresh real incidents, independent labels, per-language validation, scope/negation error review and agreed operational acceptance criteria remain required for a validated release. The observed synthetic per-language scores meet the numerical 80?85% target on this benchmark only. They cannot guarantee that target on the website. The current small per-language samples have wide sampling uncertainty.

Architecture reference: [scikit-learn text feature extraction](https://scikit-learn.org/stable/modules/feature_extraction.html). This documents TF-IDF, not the accuracy of this project.
