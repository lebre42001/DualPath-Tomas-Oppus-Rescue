# Photo Relevance Gate Recommendations - 2026-05-13

## Problem

The severity classifier can still classify an unrelated or dummy photo as Minor, Serious, or Fatal if the photo relevance gate is unavailable or under-trained.

This is not only a severity accuracy issue. The system needs a separate first step:

1. Check if the uploaded image is a real accident or emergency scene.
2. Reject dummy, app UI, selfie-only, room, food, pet, and unrelated photos.
3. Only then run severity classification.

## Current Findings

- The Laravel app already calls the AI service before storing civilian photo reports as accepted.
- If the AI service rejects the image, Laravel returns a validation error.
- If the AI service is unavailable and strict gate mode is disabled, Laravel falls back to description-based severity, so dummy photos can pass.
- Before the Wikimedia negative acquisition, the photo relevance training set was imbalanced:
  - train related: 405
  - train unrelated: 26
  - validation unrelated: 7
  - test unrelated: 7
- After adding Wikimedia non-incident negatives, the active split now has:
  - train related: 405
  - train unrelated: 110
  - validation unrelated: 19
  - test unrelated: 31
- The latest retraining run reached 99.13 percent test accuracy, with 31 of 31 unrelated test photos rejected.
- The remaining observed error was one related accident/emergency image rejected as unrelated, so the gate is now much safer against dummy photos but still needs more local positive examples to reduce false rejects.

## Changes Applied

- Added a Laravel server-side pre-screen for obvious dummy evidence:
  - screenshots
  - app UI / dashboard / login / settings images
  - profile, selfie, avatar, icon, and logo-like files
  - food, pet, room, and other filename-based dummy indicators
  - screenshot-like tall image ratios
- Added `AI_SEVERITY_REQUIRE_CIVILIAN_PHOTO_GATE`.
  - When true, civilian photo reports are not accepted if the AI photo gate is unavailable.
  - This prevents dummy photos from silently passing during AI downtime.
- Lowered the photo relevance reject threshold:
  - `reject_threshold`: `0.92` to `0.70`
  - `low_confidence_threshold`: `0.65` to `0.75`
- Added `ai_service/tools/acquire_wikimedia_relevance_negatives.py`.
  - It pulls public Wikimedia Commons images from reviewed non-incident categories.
  - It stores source URL, license, artist, and credit metadata in `metadata/wikimedia_relevance_negative_sources_2026_05_13.csv`.
  - It appends only metadata/manifests to Git; the downloaded training images stay in the ignored local dataset image tree.
- Added 120 Wikimedia negative examples across food, meals, cats, dogs, rooms, documents, receipts, and normal Philippine road images.
- Retrained the photo relevance checkpoint at `ai_service/artifacts/checkpoints/bontoc_photo_relevance_best.pt`.

## Recommended Dataset Fix

Keep adding reviewed examples until the validation and test sets each have at least 50 unrelated examples and at least 50 hard real accident/emergency positives.

Recommended negative classes:

- phone screenshots of the PWA
- Messenger, Facebook, TikTok, and browser UI screenshots
- normal selfies
- profile photos
- bedrooms, kitchens, classrooms, offices, walls, and ceilings
- food, pets, plants, documents, receipts, IDs
- random motorcycles or vehicles with no accident
- normal roads with no incident
- blurred or dark photos where no scene can be confirmed

Recommended positive classes:

- real motorcycle crashes
- real multi-vehicle crashes
- roadside injuries
- night/rain road incidents
- rural road emergencies
- Philippine-like roads and Bontoc/Southern Leyte-like environments

Current priority after this run:

- more normal selfies and profile photos
- more phone screenshots from Messenger/Facebook/TikTok/browser
- more dark, blurry, or low-light dummy photos
- more local positive motorcycle, rain/night, and rural road emergency photos

## Recommended Split

Move the relevance gate closer to balanced:

- train: roughly 50 percent related, 50 percent unrelated
- validation: at least 50 unrelated examples
- test: at least 50 unrelated examples

Do not measure only overall accuracy. Track:

- unrelated recall
- false accept rate for dummy photos
- false reject rate for real accident photos

Latest local metrics:

- test accuracy: 99.13 percent
- unrelated recall: 100 percent
- unrelated false accepts in test: 0
- related false rejects in test: 1

## Deployment Recommendation

For online testing, the AI service must have the trained checkpoint available in deployment.

Current repo behavior:

- AI checkpoints are ignored by Git under `ai_service/artifacts/checkpoints/`.
- If Render deploys without the checkpoint, `/predict` cannot run the real model.

Best options:

- Use Git LFS for selected `.pt` checkpoints.
- Or store checkpoints in R2/S3 and download them during AI service startup.
- Or attach a persistent Render disk to the AI service and upload the checkpoints there.

Without this, online dummy-photo rejection cannot be trusted because the web app may be running without the trained relevance model.
