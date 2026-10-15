# Text AI implementation validation

Date: 2026-09-19, Asia/Manila. Working copy: `C:\laragon\www\CAPSTONE-EMERGENCY-SYSTEM-main`.

## Delivered and deliberately not activated

The multilingual road/vehicle accident text workflow includes draft annotations,
review instructions, dataset validation, group-preserving splits, a TF-IDF baseline,
mBERT training/evaluation commands, a separate text API, and opt-in Laravel web/API
integration with responder review. No reviewed mBERT emergency checkpoint exists yet.
No public deployment was made. No image checkpoint/configuration was switched.

The initial pack contains 80 synthetic draft examples in 54 groups, with 20 examples
per language and label, and zero approvals. All proposed annotations need actual
language and responder review. Dataset fingerprint:

```text
9337a70b5a30de1135bd040a918d034befb6781372fdac1a78cb9ff700732878
```

## Executed checks

- Python: 30 tests passed, including the optional actual transformer training/save/evaluate/load smoke test. The smoke model is a tiny randomly initialized BERT created locally in a temporary directory. It validates code execution only; it is not pretrained mBERT and cannot demonstrate language or emergency understanding. No base model was downloaded.
- Dataset validation passed; default training rejects zero approved examples before model download or activation.
- Draft baseline training and saved-artifact evaluation both completed using the final frozen CSV.
- Laravel full suite after the build: 110 passed and two failures caused by outdated assertions expecting `Recent Account Activity` rather than the existing `Account access history` heading. Both assertions were corrected; both affected tests passed on rerun (21 assertions). New text integration and persistence checks separately passed 30 tests/156 assertions. No runtime account-management implementation was changed for these assertion fixes.
- Frontend production build passed. Vite emitted a plugin timing warning; no build failure.
- Laravel cache clear and Blade compilation passed. Reverb and the AI service were restarted to load the current code.

The first test run overlapped asset generation and had seven transient view failures;
these disappeared in the full run after the build. The final checks above use completed assets.

## Draft baseline result (not a release benchmark)

Artifact: `artifacts/checkpoints/text_draft_baseline_reviewpack`.
Saved re-evaluation: `artifacts/reports/text_draft_baseline_reviewpack`.
An earlier `text_draft_baseline` artifact predates the final semantic grouping and
must not be used as the result for the current CSV.

| Partition | Examples | Incident groups |
| --- | --- | --- |
| Train | 60 | 40 |
| Validation | 10 | 7 |
| Test | 10 | 7 |

Test raw accuracy was 7/10 (70%), macro-F1 about 0.679. The provisional score cutoff
0.65 routed all ten cases to review: coverage 0%, abstention 100%, accepted accuracy
undefined. Zero accepted undertriage therefore does not establish safety: nothing
was automatically classified. Test language counts were English 1, Tagalog 3,
Cebuano 2, mixed 4; this is far too small to establish language reliability.
The dataset and labels are artificial and unreviewed, so none of these numbers are
claims of real-world accuracy. Threshold selection and expert review remain outstanding.

## Local service observations

| Request | Result |
| --- | --- |
| `http://127.0.0.1:8000/` | HTTP 200 |
| `/login` | HTTP 200 |
| `/register` | HTTP 200 |
| `/dashboard` without an authenticated session | Redirected to `/login`; dashboard not verified as an authenticated live user |
| Built main JavaScript asset | HTTP 200 |
| `http://127.0.0.1:8100/health` | HTTP 200 |
| `/ready` | HTTP 200 (existing image readiness) |
| `/health/text` | HTTP 200, `enabled=false`, `status=disabled`, `model_loaded=false` |
| `/ready/text` | HTTP 503 as expected while disabled |

No live incident reports, notifications, or account changes were created for these
checks. Laravel feature tests use isolated SQLite memory, mocked AI calls and fake
storage/events. Live end-to-end text prediction remains unverified because there is
no approved checkpoint to run.

## Deployment and remaining work

Docker supports an optional `INSTALL_TEXT_AI=true` build. `render.yaml` keeps text
AI off in both services. This copy lacks `.git`, so no branch/commit match, push,
redeploy, or online parity is claimed. Existing blueprint image-AI/SQLite settings
are preserved and documented as differing from the older AGENTS assumptions.

Next: review the label guide and draft cases, add representative reviewed data,
train/compare mBERT, select a threshold on validation data, assess an independent
multilingual holdout, and record an actual responder release review before activation.
See [TEXT_AI_WORKFLOW.md](../../TEXT_AI_WORKFLOW.md) for commands and the compatibility
mapping between new Critical/Needs-review displays and legacy storage categories.
