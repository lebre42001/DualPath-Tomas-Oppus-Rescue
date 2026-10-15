# xlmr_fulltrain_20261005_1628_pht

Versioned, byte-preserving snapshot of the current emergency-text working corpus for a possible offline provisional experiment. The source CSV was copied exactly; no examples were added and no IDs, text, labels, review status, or split assignments were changed.

## Contents

- `reports.csv`: exact copy of the 400-row working CSV.
- `dataset_receipt.json`: hashes and preparation status.
- `dataset_statistics.json`: class, language, review, and length counts.
- `family_manifest.json`: mapping by existing `group_id`; this does not establish semantic ancestry.
- `split_manifest.json`: exact frozen split from the prior sealed Colab package.
- `provenance.json`: source lineage and limitations.

## Status and restrictions

This is a provisional development snapshot, not a release-ready training set or independent evaluation package. All 400 rows are synthetic drafts and zero are human-approved. The historical test has cross-experiment scenario overlap; it must not be presented as a fresh holdout. The current test has no mixed-language Serious or Critical support, and the corpus has no text at 15 words or fewer. The separate `provisional_xlmr_challenge_intake_20261005` is a blank human intake worksheet and is not part of this dataset.

No training, calibration, test evaluation, model activation, or deployment was performed when this snapshot was prepared.
