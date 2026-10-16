# Counted synthetic text review pack - 2026-10-16

**2,000 description words per language; 8,000 total words in 764 complete descriptions.**

| Language | Description words | Descriptions |
| --- | ---: | ---: |
| English | 2,000 | 223 |
| Tagalog | 2,000 | 206 |
| Bisaya | 2,000 | 204 |
| Mixed languages | 2,000 | 131 |

Each language has 500 words for each proposed label: minor, serious, critical and needs_review.
Counts use whitespace-separated tokens from the `text` column only. CSV headers, notes, labels and the same rows in language files do not add to the count.

## Files

- [reports.csv](reports.csv): master selection with the existing training schema.
- [English](en.csv), [Tagalog](tl.csv), [Bisaya](ceb.csv), [mixed](mixed.csv): derived browsing/review copies, not extra training rows.
- [generation_manifest.json](generation_manifest.json): exact word/label counts, source hash and limitations.

## Source and review status

Selected from [the existing 1,366-row synthetic corpus](../full_pool_20261011/reports.csv). Every description, case ID, group ID and proposed label is preserved; text is not cut, padded or duplicated to reach the budget.
The source already exceeds 2,000 words in every language. This is a counted subset, not an expansion or newly authored data. Do not combine it with the source as extra examples.
**All selected rows are draft, with blank reviewer fields.** Historical approval claims are not carried forward as verified approvals. Follow [the annotation guide](../../annotation_guide.md) for actual language and responder review.

## Model and evaluation

Selection itself does not train or activate a model. Any subsequent retraining needs its own evaluation/release record.

The owner subsequently requested [v2 retraining and deployment](../../../../artifacts/reports/text_retrain_20261016_v2/EVALUATION.md). That fit uses the full canonical source without duplicating this subset: 537 selected rows remain in training, 118 in validation and 109 in test. All validation/test rows remain excluded from fitting. The resulting weights and historical diagnostic scores match v1; no added information or accuracy improvement is claimed.
The source was already used in model development. This is not an independent holdout and cannot establish 80-85% real-report accuracy. Keep scenario groups together if designing future experiments; language files are not train/test splits.

## Reproduce

From `C:\laragon\www\TomasOppusRescue-main\ai_service`, use a new output directory:

```powershell
.\.venv-text\Scripts\python.exe tools/build_counted_text_dataset.py --source datasets/emergency_text/experiments/full_pool_20261011/reports.csv --output datasets/emergency_text/experiments/counted_2000words_reproduction --words-per-language 2000 --seed 20261016
```
