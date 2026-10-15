# Emergency Text XLM-R Colab Training

The 2026-10-05 returned provisional run is evaluated in [the experiment evaluation](artifacts/reports/text_xlmr_provisional_20261005/EVALUATION.md). Training completed with seed 42 selected; the preset test acceptance gates did not all pass. The result remains a research artifact.

This workflow full-fine-tunes `FacebookAI/xlm-roberta-base` at pinned revision `42f548f32366559214515ec137cdd16002968bf6` for the current multilingual road-accident text experiment. It keeps the Laravel application, active AI configuration, image evidence feature, and existing checkpoints unchanged.

## Current package status

- Dataset: `datasets/emergency_text/working/reports.csv`
- Rows: 400
- Scenario groups: 99
- Languages: English, Tagalog, Bisaya, and mixed
- Labels: minor, serious, critical, and needs_review
- Review status: all 400 rows are draft/synthetic
- Mode: provisional research
- Deployment approval: false

The current package can measure whether full XLM-R fine-tuning improves the earlier frozen-mBERT result. It cannot establish production quality because its labels are not independently reviewed ground truth.

## Files

- Notebook: `notebooks/EMERGENCY_TEXT_XLMR_COLAB.ipynb`
- Upload bundle: `artifacts/colab_packages/emergency_text_xlmr_provisional_20261004/emergency_text_xlmr_provisional_20261004.zip`
- Package receipt: `artifacts/colab_packages/emergency_text_xlmr_provisional_20261004/PACKAGE_RECEIPT.json`
- Package builder: `tools/prepare_colab_text_training.py`
- Pre-upload validator: `tools/validate_colab_training_package.py`
- Returned-run validator: `tools/validate_colab_text_artifact.py`

## Run in Google Colab

1. Upload and open `EMERGENCY_TEXT_XLMR_COLAB.ipynb` in Google Colab.
2. Select **Runtime > Change runtime type > GPU**.
3. Choose **Run all**.
4. When requested, upload the exact ZIP bundle listed above.
5. Authorize Google Drive. Outputs are written to `MyDrive/emergency_text_xlmr_runs/emergency-text-xlmr-provisional-20261004/`.
6. Keep the browser connected until all three seeds and the final test evaluation finish. Completed seed directories are reused if the notebook is run again.

The notebook performs these operations in order:

1. Verifies the ZIP and every bundled file by SHA-256.
2. Validates the CSV schema and frozen family-disjoint split.
3. Requires a CUDA GPU.
4. Trains the character TF-IDF logistic-regression baseline.
5. Full-fine-tunes XLM-R for seeds 41, 42, and 43.
6. Selects the checkpoint using validation Critical recall, then validation macro-F1.
7. Evaluates the untouched test split once using only the selected checkpoint.
8. Writes acceptance metrics while keeping deployment and active-model flags false.

## Validate a returned run locally

Copy the completed Drive run folder to a local directory, then run:

```powershell
cd "C:\laragon\www\CAPSTONE-EMERGENCY-SYSTEM-main\ai_service"
.\.venv-text\Scripts\python.exe .\tools\validate_colab_text_artifact.py `
  --run-root "C:\path\to\emergency-text-xlmr-provisional-20261004" `
  --bundle ".\artifacts\colab_packages\emergency_text_xlmr_provisional_20261004\emergency_text_xlmr_provisional_20261004.zip"
```

Validation does not activate the checkpoint. Promotion requires a separately authorized decision after independently reviewed data, adjudication, challenge testing, and responder acceptance.

## Build a future reviewed-release package

After at least 800 rows have accountable reviewer approval and adjudication, build a separate reviewed package:

```powershell
cd "C:\laragon\www\CAPSTONE-EMERGENCY-SYSTEM-main\ai_service"
.\.venv-text\Scripts\python.exe .\tools\prepare_colab_text_training.py `
  --mode reviewed_release `
  --data ".\datasets\emergency_text\working\reports.csv" `
  --output ".\artifacts\colab_packages\emergency_text_xlmr_reviewed_v1" `
  --notebook ".\notebooks\EMERGENCY_TEXT_XLMR_REVIEWED_V1_COLAB.ipynb"
```

The builder rejects reviewed-release mode when rows lack accountable reviewer identities or the reviewed-row minimum is not met.
