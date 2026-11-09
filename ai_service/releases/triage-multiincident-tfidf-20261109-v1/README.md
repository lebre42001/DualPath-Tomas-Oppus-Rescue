# Experimental six-category AI Lab checkpoint

Version: `multiincident-tfidf-20261109-v1`. **The requested 80% complete-system target was not met.**

This CPU TF-IDF/logistic-regression checkpoint was fitted on 1,068 AI-authored draft descriptions, with 308 validation cases. It uses the selected incident category and description in English, Tagalog, Cebuano/Bisaya or mixed language. Supported types: road accident, fire, flood/landslide, medical emergency, security incident and other. The 0.50 review cutoff is provisional and scores are uncalibrated.

It is configured only for the isolated local admin AI Lab on port 8101. The ordinary road-only v2 model on 8100 and Laravel manual reporting mode are unchanged. Loading requires explicit experimental opt-in. This is not approved for operational deployment or automatic dispatch.

Final synthetic evidence:

| Diagnostic | N | Raw classifier | Complete serving policy | Critical recall | Urgent lower assignments |
| --- | ---: | ---: | ---: | ---: | ---: |
| Family test | 144 | 73.61% | 72.92% | 69.23% | 5 |
| Separate fresh-v2 challenge | 384 | 83.33% | 68.49% | 50.00% | 10 |

All labels are unreviewed drafts. These are not real-report accuracy estimates. Needs review remains in the denominator. No metadata approval was granted. A fresh independently reviewed, representative holdout is required for a validated replacement.

See [the full evaluation](../../artifacts/reports/multiincident_common_20261109/EVALUATION.md). `tested_policy_context.json` binds the tested model metadata, artifact, rule/evaluation source hashes and separate challenge. Changing policy code requires fresh evaluation; the same weights do not imply the same serving behavior. Intermediate selection receipt and validation metrics are included for audit; validation accuracy is not final test accuracy.
