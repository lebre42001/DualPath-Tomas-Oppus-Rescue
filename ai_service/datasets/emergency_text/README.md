# Emergency text datasets: start in working

**Sunda ang [working dataset](working/README.md).** Its [reports.csv](working/reports.csv) is the current editable master. Browse [severity/language folders](working/by_severity/README.md); do not concatenate these copies with the master.

```text
emergency_text/
  working/
    reports.csv                 current editable master
    review_worksheet.csv        pending human decisions
    by_severity/                derived severity/language copies
    provenance.json             initial source/count record
  experiments/
    synthetic_9000words_20260920/ frozen 292-row historical experiment
  archive/
    initial_80/                 original 80 examples
  generation/                   synthetic sources, not extra training rows
  annotation_guide.md            provisional review rules
```

The working master starts with **400 draft descriptions / 99 declared scenario families**: 120 English, 120 Tagalog, 120 Bisaya, and 40 mixed-language. It preserves the 292 original selected descriptions, adds 68 unused seed translations and 40 authored code-switching variants. They are not 400 independent incidents, not 1,200 approved descriptions, and not a fresh release test set. No human approvals are supplied.

The 9,000-word count belongs only to the frozen experiment. The working copy is no longer constrained to that word budget. After edits, use `review_text.py audit` for current counts.

## Everyday commands

Run from `C:\laragon\www\CAPSTONE-EMERGENCY-SYSTEM-main\ai_service`:

```powershell
& '.\.venv-text\Scripts\python.exe' -B review_text.py audit
& '.\.venv-text\Scripts\python.exe' -B organize_text.py
& '.\.venv-text\Scripts\python.exe' -B organize_text.py --check
```

`organize_text.py` and `train_text.py validate/train/baseline` default to `working/reports.csv`. Explicit `--data` selects another source. Moving folders does not activate a model.

Edit descriptions and metadata in the working master, then refresh its browsing copies. Preserve headers and group IDs. Modified generated copies are not overwritten: preserve their changes and export to a new `--output` folder if recovery is needed.

## Human review

Follow [the working instructions](working/README.md) and [annotation guide](annotation_guide.md). Export leaves every decision blank. Import requires actual reviewer identity, date, label and rationale, checks the source fingerprint, and writes a new master version. It never silently approves drafts or activates a checkpoint.

Target 1,200 reviewed descriptions is a planning milestone, not a release criterion. Add permitted, de-identified real reports and independent scenarios. Collect a reviewed holdout that does not overlap previous experiments; source/semantic independence still needs human assessment even when exact hashes pass.

## CSV contract

UTF-8, exactly these columns:

```text
case_id,group_id,language,text,label,review_status,reviewer,notes
```

- `case_id`: stable unique example ID.
- `group_id`: shared by an incident, its translations and near-paraphrases.
- `language`: `en`, `tl`, `ceb`, or `mixed`.
- `text`: complete description available at report time; maximum 1,000 characters for the app.
- `label`: `minor`, `serious`, `critical`, or `needs_review`.
- `review_status`: `draft`, `approved`, or `rejected`; distinct from severity.
- `reviewer`: actual accountable reviewer for approved rows.
- `notes`: provenance, corrections, dated rubric decisions and limitations.

Critical does not declare death. Needs review is an abstention outcome, not low urgency. Folder names, photos and later outcomes must not become artificial classification cues.

## Training and history

Follow [the training runbook](../../TEXT_AI_WORKFLOW.md). Normal training excludes drafts and refuses zero approved data. Explicit `--allow-draft` runs are diagnostic only. Full fine-tuning requires `--unfreeze-backbone`; training defaults to validation-only scoring. Final test evaluation is a separate explicit step.

- [Original 80-row archive](archive/initial_80/README.md)
- [Frozen 9,000-word experiment](experiments/synthetic_9000words_20260920/README.md)
- [Workflow change and corrected-training report](../../artifacts/reports/text_workflow_20260920/README.md)
