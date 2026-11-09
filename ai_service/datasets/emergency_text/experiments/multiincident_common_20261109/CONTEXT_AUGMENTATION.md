# Train-only common context vocabulary

`context_vocabulary.json` contains four neutral reporting contexts for every existing category and language. The mechanisms and places are motorcycle, tricycle, van, pedestrian; smoke, stove, LPG, wiring, house; river, drain, landslide, water; home, clinic, work, school; fight, theft, disturbance, guard; and work tools, tree, fall, boat.

These developer-authored contexts describe the subject of a report. They do not add a new observed casualty, medical treatment, reassurance, active exposure, or immediate life-threatening condition. A report about a mechanism does not assert that its hazard is currently active. All four prefixes are applied to every training row in a category/language, regardless of proposed label, so prefix selection never depends on severity.

The constructor `ai_service/tools/augment_multiincident_contexts.py` reads the existing CSV and frozen split manifest. It replaces only the formal first clause before the first comma-space in each **training** description. The entire condition body after that comma-space remains character-for-character unchanged. Each variant keeps its source `group_id`, `incident_type`, `language`, and `label`. All rows remain synthetic `draft` records with blank reviewers and no approval.

The output `prepared_augmented/` is a new directory; the source is never overwritten. Its `reports.csv` begins with every original CSV byte, then appends only the newly serialized training variants. The validation and test manifest entries, row values, assignments, global-family partition, and descriptions remain unchanged. The source rows are not reordered. Structural validation reuses the existing `load_dataset` and `experiment_splits` functions and checks that no held-out record changed.

Run from `ai_service`:

```powershell
& '.\.venv-text\Scripts\python.exe' -B '.\tools\augment_multiincident_contexts.py'
```

For the frozen source with 1,152 total rows, 768 training rows, 176 validation rows, and 208 test rows, this adds **3,072 correlated training variants**. The new counts are 3,840 training rows and 4,224 total rows. The variants create **zero new independent incidents or semantic families**. The extra wording broadens context exposure; it does not independently verify any label, teach unseen patient states, provide new clinical evidence, or establish 80% real-report accuracy.

`prepared_augmented/provenance.json` records source/context/output digests, exact source-case links for all variants, source provenance, and the unchanged evaluation counts. `training_vocabulary.json` is recalculated from the augmented training partition only. No private reports, challenge predictions, validation/test description inspection, label-specific context choices, or live model activation are part of this operation.
