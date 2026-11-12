# Local reporting vocabulary expansion — 2026-11-12

These AI-authored, fictional contexts add common local reporting vocabulary for
road accidents, fire, flood/landslide, medical, security and other incidents in
English, Tagalog, Cebuano/Bisaya and mixed language. Examples include multicab,
habal-habal, barangay road, footbridge, irrigation canal, covered court and
coastal waiting shed. No private reports, real identities, contact details or
precise addresses were used.

Every source training description receives all four contexts for its category
and language, regardless of severity. The **complete original description** is
preserved after the prefix: no injury, negation, history, uncertainty, severity
label, group, language or category is rewritten. All new rows remain synthetic
drafts with blank reviewers. The contexts have not received fluent-language or
responder review.

The frozen source is `../multiincident_common_20261109/combined_plain/`.
`prepared/reports.csv` preserves every source CSV byte as its prefix, then adds
4,272 correlated training variants. Training grows from 1,068 to 5,340 rows;
validation remains 308 and test remains 144, for 5,792 total rows. This creates
**zero new independent incidents or semantic families**. The original family
groups and validation/test manifest entries remain unchanged.

Training-only unique word counts grow from 739 to 757 (English), 614 to 637
(Tagalog), 589 to 612 (Cebuano), and 736 to 757 (mixed). Counts overlap across
languages and do not measure clinical correctness or real-report coverage.

From this checkout's `ai_service` directory, the reproducible bounded CPU fit is:

```powershell
& .\.venv-text\Scripts\python.exe -B tools\refresh_local_text_vocabulary.py
```

The command refuses to overwrite existing prepared/model directories. To repeat
it, supply new `--prepared` and `--output` directories. It performs one fixed
C=16 / cutoff=0.50 fit, retains existing cue policy and records a validation-only
non-regression decision before scoring the known test. The test is exposed
synthetic regression evidence, not a fresh independent holdout. No command in
the recipe enables reporting, switches selectors, or grants deployment approval.

See the [candidate report](../../../../releases/triage-multiincident-tfidf-20261112-vocabulary-v1/README.md).
