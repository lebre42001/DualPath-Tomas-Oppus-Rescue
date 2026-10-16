# DualPath Trilingual TF-IDF Logistic Regression v2

Version: `dualpath-tfidf-20261016-v2`, requested retraining on 2026-10-16.

**Experimental admin-supervised suggestions; no clinical approval or independently established real-report accuracy.** Every final operational severity requires admin confirmation. Scores are uncalibrated; phrase-rule suggestions have no manufactured confidence.

Freshly fitted on the canonical 1,366-row synthetic corpus with the original 947/199/220 group split. The counted 8,000-word pack already occurs in that source; 537 of its cases are training, 118 validation and 109 test. No duplicates or held-out examples were added to fitting. The new fit reproduces the same numeric artifact as v1, so no accuracy improvement is claimed.

Artifact SHA-256: `58d859a25a01f0fb95dd70576ed1523a9a09051e8d097776afb92b80e4085b90`.

Historical diagnostic: raw accuracy 92.7%; actual classifier-plus-rule routed accuracy 85.9% (English 79.7%, Tagalog 86.9%, Bisaya 86.0%, mixed 97.0%). These are exposed synthetic samples, not a fresh release test. See [the full evaluation, provenance and rollback notes](../../artifacts/reports/text_retrain_20261016_v2/EVALUATION.md).

Local startup and Dokploy Compose explicitly select this directory. The old v1 release remains available. Numeric JSON is loaded with byte-integrity checks; no pickle or image checkpoint is used.
