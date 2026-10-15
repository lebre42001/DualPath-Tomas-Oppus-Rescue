# Working review dataset

**Dinhi magtrabaho:** [reports.csv](reports.csv). [Browse by severity/language](by_severity/README.md).

Initial state: 400 synthetic drafts, 99 scenario families, zero approved rows. English/Tagalog/Bisaya each have 120 descriptions; mixed has 40. All four categories have 100 rows. See [provenance](provenance.json). Expanding translations does not establish independent incident diversity or model readiness.

## Review procedure

1. Agree on the [provisional label rubric](../annotation_guide.md) with the responder reviewer and fluent language reviewers.
2. Read descriptions independently before considering suggested labels. Start with critical cases and Tagalog/Bisaya; the worksheet is ordered accordingly.
3. Correct text, translation meaning or group assignments in `reports.csv` first, preserving provenance in `notes`. Export a **new** worksheet after source edits; stale worksheets are deliberately rejected.
4. Fill only `decision`, `final_label`, `reviewer`, `reviewed_at` (YYYY-MM-DD), and `rationale` in [review_worksheet.csv](review_worksheet.csv). Decisions are `approved` or `rejected`; leave all decision fields blank for unfinished work. Never enter an invented reviewer or approve automatically.
5. Related translations must agree on label. Resolve the entire scenario family together when changing a label. Worksheet identity, description and suggested-label columns are checked against the source master.
6. Import to a **new CSV** for inspection. The command preserves the source master and refuses an occupied output. After checking that version, explicitly adopt it as the working master, preserving the previous version in a new experiment/version directory. Then refresh the browsing copies.

From `ai_service/`:

```powershell
# Existing initial worksheet is supplied. Use a NEW filename after edits.
& '.\.venv-text\Scripts\python.exe' -B review_text.py worksheet --output datasets/emergency_text/working/review_round2.csv

# Run only after actual human decisions have been recorded.
& '.\.venv-text\Scripts\python.exe' -B review_text.py import-review --worksheet datasets/emergency_text/working/review_worksheet.csv --output datasets/emergency_text/working/reviewed_candidate.csv
& '.\.venv-text\Scripts\python.exe' -B train_text.py validate --data datasets/emergency_text/working/reviewed_candidate.csv
```

Import records the supplied review; it cannot verify a person's qualifications or replace human adjudication. It does not grant model-release approval. Importing a blank worksheet leaves every row draft.

## Fresh holdout and calibration

Current working scenarios overlap historical experiments. They cannot be described as a fresh independent release test set. Collect permitted descriptions of new incidents, independently label them, and check against every relevant prior checkpoint:

```powershell
& '.\.venv-text\Scripts\python.exe' -B review_text.py check-holdout --data path/to/reviewed_holdout.csv --exclude-model artifacts/checkpoints/text_synthetic_9000_mbert_full_20260920 --exclude-model artifacts/checkpoints/text_weighted_full_20260920
```

Replace `path/to/reviewed_holdout.csv` with the actual reviewed file. This checks IDs, declared groups and exact normalized text, including old test examples. Humans must still assess source independence and near-paraphrases. Keep calibration/threshold-selection examples separate from the final release test set. No such reviewed holdout or calibration data is available yet.

Record operational acceptance criteria and the actual decision in [the pending acceptance form](ACCEPTANCE_REVIEW.md). Its blank entries are not approvals.
