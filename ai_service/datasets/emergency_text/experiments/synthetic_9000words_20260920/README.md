# Synthetic road-accident text review pack

**9,000 description words** across 292 complete reports and 93 scenario groups.
**All rows are draft. Zero approved examples; not a validated emergency triage model.**

| Language | Description words | Descriptions |
| --- | ---: | ---: |
| English | 3000 | 99 |
| Tagalog | 3000 | 97 |
| Bisaya | 3000 | 96 |

Each language has 750 words in each of minor, serious, critical and needs_review.
Words are counted with Python `len(text.split())` in the master text column; CSV metadata, README headings and browsing copies do not add to the count.

- Experiment master: [reports.csv](reports.csv). For subsequent review, use a separate working copy as explained below.
- Browsing: [severity and language folders](by_severity/README.md). These are derived copies; edit the master, then refresh them.
- Provenance and exact counts: [generation_manifest.json](generation_manifest.json).
- Label policy and human review: [annotation guide](../../annotation_guide.md).
- Keep translations of a scenario in the same group; folder or language separation is not a train/test split.
- Original 80-row draft/review master files are preserved separately.

Training on this pack is an explicitly synthetic experiment. It must not enable live severity predictions or fabricate expert approval.

**Training completed:** three full-backbone mBERT epochs in 11 minutes 1 second on CPU, followed by independent checkpoint reload verification. See the [training report](../../../../artifacts/reports/text_synthetic_9000words_20260920/README.md) for metrics and limitations. The candidate remains inactive: only 1/5 critical test cases was recognized before abstention, and all test examples required review at the threshold.

Now that this pack has been evaluated, preserve this master for reproducibility. Copy it to a separate working CSV for human review, corrections, and new examples, then export that CSV to a new set of browsing folders. Record actual reviewers; this experiment does not approve the labels.
