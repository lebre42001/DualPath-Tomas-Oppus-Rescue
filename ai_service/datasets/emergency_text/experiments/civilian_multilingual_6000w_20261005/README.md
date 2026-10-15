# civilian_multilingual_6000w_20261005

Versioned multilingual road/vehicle emergency-description experiment.

- 850 synthetic AI-generated draft descriptions; 9520 whitespace-separated words.
- All proposed labels use TEXT-RUBRIC-0.2-B; no row is human-confirmed.
- `needs_review` is an abstention/uncertainty state.
- `provenance.csv` carries row-level source/language/family/word/hash fields. `reports.csv` is the all-row audit master and includes every partition; NEVER pass it to the training CLI.
- Train only from `model_development.csv` with `training_split_manifest.json`; this pair contains train, validation and fresh_test (mapped to CLI `test`) only. Calibration and challenge rows are withheld from training and selection.
- `fresh_test.csv`, `calibration.csv`, and `challenge.csv` are family-disjoint from train/validation but are synthetic drafts, not independent real-world evidence.
- `training_split_manifest.json` is the immutable three-way split expected by `train_text.py`; full five-way partition hashes are in `split_manifest.json`.
- No deployment, model activation or human-review approval is implied.
