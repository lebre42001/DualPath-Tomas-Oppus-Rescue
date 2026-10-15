# AI Accuracy Training Run - 2026-05-13

Goal: improve and verify the captured-photo AI workflow used by civilian emergency reports.

## What Was Trained

### 1. Severity accuracy candidate

Config: `configs/bontoc_southern_leyte_accuracy_candidate.yaml`

Purpose: classify accepted emergency scene photos as:

- `minor`
- `serious`
- `fatal`

Dataset:

- train: `405` images
- validation: `87` images
- test: `84` images
- class balance: roughly even across `minor`, `serious`, and `fatal`

Result:

- best validation accuracy: `81.61%`
- test accuracy: `70.24%`
- macro F1: `70.24%`

Per-class test recall:

- `minor`: `82.14%`
- `serious`: `53.57%`
- `fatal`: `75.00%`

Important finding:

- `serious` is the weakest class.
- Some serious cases are being predicted as `minor` or `fatal`.
- Do not claim 95% severity accuracy yet without more reviewed local training data.

### 2. Photo relevance / dummy-photo gate candidate

Config: `configs/bontoc_southern_leyte_photo_relevance_accuracy_candidate.yaml`

Purpose: reject unrelated uploads before severity prediction, including dummy photos, UI screenshots, food, pets, rooms, and selfie-only images.

Dataset:

- train: `431` images
- validation: `94` images
- test: `91` images
- related test images: `84`
- unrelated test images: `7`

Result:

- best validation accuracy: `100.00%`
- test accuracy: `98.90%`
- unrelated recall: `85.71%`
- related recall: `100.00%`

Important finding:

- The dummy-photo gate is already strong on the current test set.
- It still needs more unrelated negatives because the test set only has `7` unrelated images.

## Local Validation Result

Both the active production candidate and the new severity accuracy candidate were evaluated on the local validation-only set.

- total local validation images: `7`
- active production local accuracy: `28.57%`
- new candidate local accuracy: `28.57%`

Per-class local validation accuracy:

- `minor`: `50.00%`
- `serious`: `0.00%`
- `fatal`: `100.00%`

Scenario weakness:

- motorcycle: `0.00%`
- multi-vehicle: `20.00%`
- rural scene: `0.00%`
- night / low light: `50.00%`

## Decision

Do not promote the severity accuracy candidate as a new production model yet.

Reason:

- It did not improve local validation accuracy over the current active model.
- The current dataset is still too weak for Bontoc/Southern Leyte-specific motorcycle, serious, rural, and night/rain cases.

Keep the current active config:

`configs/bontoc_southern_leyte_production_candidate_external.yaml`

## Current Civilian Report AI Flow

1. Civilian captures a real scene photo.
2. AI photo relevance gate checks if the photo is accident/emergency-related.
3. If unrelated/dummy, the report is rejected with a clean upload warning.
4. If related, the severity model predicts `minor`, `serious`, or `fatal` from the photo.
5. If the AI service fails, the web system falls back to short-description severity hints.

## Next Accuracy Work Needed

Add reviewed training images for:

- serious motorcycle crashes
- Philippine road scenes
- rural/barangay roads
- nighttime or rain/wet-road scenes
- multi-vehicle crashes
- more dummy negatives: food, pets, rooms, selfies, screenshots, app screens, random documents

Recommended minimum next batch:

- `50` serious motorcycle accident photos
- `30` fatal road accident photos
- `30` minor/low-damage road incident photos
- `50` unrelated/dummy negative photos

After adding those, rerun:

```powershell
cd "C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch\ai_service"
& "C:\laragon\bin\python\python-3.13\python.exe" train.py --config configs\bontoc_southern_leyte_accuracy_candidate.yaml
& "C:\laragon\bin\python\python-3.13\python.exe" train.py --config configs\bontoc_southern_leyte_photo_relevance_accuracy_candidate.yaml
& "C:\laragon\bin\python\python-3.13\python.exe" tools\evaluate_local_validation.py --config configs\bontoc_southern_leyte_accuracy_candidate.yaml --output-prefix accuracy_candidate_local_eval
```
