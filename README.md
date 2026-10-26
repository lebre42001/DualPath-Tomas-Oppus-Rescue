# AksyRes

This workspace now contains the mobile app, web system, database references, AI training service, plus the original UI references.

## Stack

- Mobile experience: Progressive Web App on top of Laravel
- Android download: signed APK launcher for the same online PWA, using Trusted Web Activity
- Legacy mobile app reference: Flutter (Dart)
- Web system: Laravel (PHP)
- Database: MySQL
- AI service: Python + FastAPI, experimental TF-IDF/Logistic Regression text pilot; optional PyTorch transformer path
- Description analysis: [English/Tagalog/Bisaya condition phrases](ai_service/TEXT_CUE_POLICY.md) support the model; new report analysis is automatic, scores are uncalibrated, and original results remain visible beside the admin review.

## Projects

- `android_app/` contains the Android APK source and [build/release instructions](android_app/README.md); `tool/build_android_apk.ps1` builds and verifies it
- `mobile_app/` contains the legacy Flutter mobile starter kept for reference while the system shifts to PWA delivery
- `web_system/` contains the Laravel web system with working auth, dashboard, reports, profile, and settings
- `database/` contains the MySQL setup reference
- `ai_service/` contains the accident severity dataset structure, labeling guides, training code, and inference API scaffold
- `ai_service/datasets/external_tabular_sources/` contains imported CSV accident datasets used for analytics, research, and future structured-data modeling support
- `ai_service/datasets/external_image_sources/` contains downloaded public image datasets plus mapping notes before they are reviewed for local model use
- `ai_service/datasets/bontoc_southern_leyte/local_validation/` contains the Bontoc/Southern Leyte validation-only set, acquisition checklist, scenario coverage tracker, and review templates
- `ai_service/datasets/bontoc_southern_leyte/local_validation/manifests/wikimedia_commons_public_seed_manifest_2026_04_04.csv` tracks the starter public seed images currently placed in `local_validation/`
- `ai_service/V0_3_RETRAINING_PLAN.md` contains the next retraining plan focused on motorcycles, night/rain, rural roads, and multi-vehicle incidents
- `ai_service/V0_3_TRAINING_RUNNER_GUIDE.md` contains the step-by-step command flow for the future v0.3 training run
- `ai_service/AI_PROMOTION_CHECKLIST.md` contains the safety checklist before any live AI model switch
- `ai_service/active_config.txt` is retained only for archived image training scripts; the FastAPI service uses the text checkpoint selected by `AI_TEXT_MODEL_DIR`
- `ai_service/datasets/bontoc_southern_leyte/v0_3_candidate/` contains the reviewed-pool and train/val/test staging structure for the next retraining cycle
- `ai_service/datasets\bontoc_southern_leyte\v0_3_candidate\source_batches\wikimedia_commons_seed_batch_2026_04_04.csv` tracks the starter public seed images approved into the reviewed pool
- `ai_service/configs/bontoc_southern_leyte_v0_3_candidate_template.yaml` is the starter config for the future v0.3 training run
- `ai_service/tools/build_v0_3_candidate_split.py` builds the v0.3 train/val/test split from approved reviewed-pool images
- `ai_service/datasets/bontoc_southern_leyte/local_validation/LOCAL_VALIDATION_RUNNER_GUIDE.md` gives the exact command-by-command local validation evaluation flow
- `DEPLOYMENT_RENDER_RAILWAY.md` contains the recommended online deployment plan for the current stack
- `DOKPLOY_DEPLOYMENT.md` and `compose.dokploy.yaml` prepare the Laravel PWA and MariaDB stack for Dokploy Docker Compose hosting
- `render.yaml` contains the Render Blueprint scaffold for online deployment

## UI Reference Folders

- `01_onboarding/` contains `splash_screen/` and `welcome_screen/`
- `02_auth/` contains `login_screen/` and `register_screen/`
- `03_app_core/` contains `home_dashboard/`, `profile/`, and `settings/`
- `04_reporting/` contains `ai_severity_analysis/`, `report_details/`, `report_emergency/`, `report_history/`, `report_success/`, `sentinel_response/`, and `transmission_status/`

## Mockup Source Format

Each mockup folder now keeps only the source design assets used to build the app:

- `code.html` as the preserved design source
- `screen.png` as the visual reference
- `DESIGN.md` when available for design notes

## Recommended Flow

1. Use the mockup `code.html` files as the visual source of truth
2. Run the web system inside `web_system/`
3. Use the installable PWA from `web_system/` for mobile deployment
4. Use MySQL with the Laravel migrations in `web_system/`
5. Prepare and train the accident severity model inside `ai_service/` when labeled data is ready

## Local Run Notes

- Laravel web system runs on `http://127.0.0.1:8000`
- LAN web access is `http://192.168.1.5:8000` on the current Wi-Fi network
- The recommended mobile deployment is now the installable PWA from the Laravel web system
- Install the PWA from `/dashboard`, `/login`, or `/register` after the browser shows the app install prompt
- Reverb/WebSocket runs on `http://127.0.0.1:8080`
- AI service health endpoint is `http://127.0.0.1:8100/health`
- Road routing defaults to `https://router.project-osrm.org` for responder-side route distance, travel time, and turn-by-turn guidance
- Flutter notes remain only if you still need the legacy mobile reference app

## Progressive Web App

Use the Laravel web system as the mobile app replacement.

### Step by step

1. Start the backend services:

```bash
cd "C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch"
pwsh -File .\tool\start_stitch_services.ps1
```

2. On your phone, open one of these:

```text
http://192.168.1.5:8000/login
http://192.168.1.5:8000/register
http://192.168.1.5:8000/dashboard
```

3. Sign in or register.
4. Wait for the browser install prompt, or use the browser menu and choose `Install app` / `Add to Home screen`.
5. Launch `Bontoc Rescue` from the home screen for the app-like mobile experience.

### What the PWA now includes

- installable app manifest
- service worker registration
- offline fallback screen
- automatic website and installed-app update detection
- automatic reload when a new system version is available
- home-screen icon assets
- standalone app display mode
- mobile-friendly install prompt inside the Laravel UI
- install helper on `welcome`, `login`, and `register`
- quick app shortcuts for `Dashboard`, `Send Report`, and `Incident Feed`

### Automatic updates

- If the system is updated, the open website and installed PWA check for the new version automatically.
- The app checks again when:
  - the tab becomes visible
  - the app gets focus
  - the device comes back online
  - the periodic background check runs
- When a new version is found, the site or installed PWA reloads automatically to use the latest assets and workflow changes.
- If the page is already open during deployment, expect the refresh to happen automatically after the next update check.

### Compatibility behavior

- Android Chromium browsers get the direct install prompt when supported
- iPhone and iPad Safari get manual `Share > Add to Home Screen` guidance
- older browsers without service-worker support still fall back to normal browser mode instead of a broken install flow

## One-Click Startup

Use the root helper script if you want the `web system`, `Reverb`, and `AI service` to start together.

### Step by step

1. Open PowerShell.
2. Go to the project root:

```bash
cd "C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch"
```

3. Run the startup script:

```bash
pwsh -File .\tool\start_stitch_services.ps1
```

4. Wait for the health check summary.
5. Open the web system:

```bash
http://127.0.0.1:8000
```

6. If you want to stop all three services later, run:

```bash
pwsh -File .\tool\stop_stitch_services.ps1
```

7. If you want to check whether the services are still running, run:

```bash
pwsh -File .\tool\status_stitch_services.ps1
```

The status helper can detect existing live processes even if old PID files are stale.

### What the startup script starts

- Laravel web server on port `8000`
- Reverb/WebSocket server on port `8080`
- Python AI severity service on port `8100`
- Responder route guidance uses the configured routing service and falls back to a safe estimate if the router is unavailable

### Generated logs

The startup script writes logs here:

- `tool/runtime/laravel-serve.out.log`
- `tool/runtime/reverb.out.log`
- `tool/runtime/ai-service.out.log`

The matching `.err.log` files are also created in the same folder.

## One-Click Backend + Mobile App Startup

Use this if you want the backend services to start first and then immediately launch the mobile app from the project root.

### Step by step

1. Open PowerShell.
2. Go to the project root:

```bash
cd "C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch"
```

3. Choose the mobile target you want to use.
4. Run one of these commands:

```bash
pwsh -File .\tool\start_mobile_and_backend.ps1 -Target windows
pwsh -File .\tool\start_mobile_and_backend.ps1 -Target android-emulator -DeviceId emulator-5554
pwsh -File .\tool\start_mobile_and_backend.ps1 -Target android-phone-usb -DeviceId 143352556L110746
pwsh -File .\tool\start_mobile_and_backend.ps1 -Target android-phone-wifi -HostIp 192.168.1.5 -DeviceId 143352556L110746
```

### What this script does

1. Starts the Laravel web server
2. Starts Reverb
3. Starts the AI severity service
4. Runs the Flutter mobile app helper with the target you selected

### Important note

- The backend services stay in the background.
- The mobile run stays in the foreground while Flutter is active.
- When you are done, stop the backend services with:

```bash
pwsh -File .\tool\stop_stitch_services.ps1
```

## Backend Status Check

Use this helper if you want to quickly verify whether `web`, `Reverb`, and `AI service` are still running.

### Step by step

1. Open PowerShell.
2. Go to the project root:

```bash
cd "C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch"
```

3. Run:

```bash
pwsh -File .\tool\status_stitch_services.ps1
```

### What you will see

- running or stopped state for:
  - `laravel-serve`
  - `reverb`
  - `ai-service`
- health checks for:
  - `http://127.0.0.1:8000`
  - `http://127.0.0.1:8100/health`
  - port `8080`
- automatic detection of already-running live processes even if old PID files do not match anymore

## One-Click AI Workflow

Use this if you want one command to run the AI evaluation helper, the v0.3 split builder, or both.

### Step by step

1. Open PowerShell.
2. Go to the project root:

```bash
cd "C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch"
```

3. Run one of these:

```bash
pwsh -File .\tool\run_ai_workflow.ps1 -Mode local-validation
pwsh -File .\tool\run_ai_workflow.ps1 -Mode v0-3-split
pwsh -File .\tool\run_ai_workflow.ps1 -Mode v0-3-train
pwsh -File .\tool\run_ai_workflow.ps1 -Mode v0-3-full
pwsh -File .\tool\run_ai_workflow.ps1 -Mode both
```

### What this script does

- `local-validation` runs `ai_service/tools/evaluate_local_validation.py`
- `v0-3-split` runs `ai_service/tools/build_v0_3_candidate_split.py`
- `v0-3-train` runs `ai_service/train.py --config configs\bontoc_southern_leyte_v0_3_candidate_template.yaml`
- `v0-3-full` rebuilds the split, trains the v0.3 candidate, then runs post-train local validation
- `both` runs the evaluator first, then rebuilds the approved v0.3 candidate split

### Output folders

- local validation reports:
  - `ai_service/datasets/bontoc_southern_leyte/local_validation/reports/`
- v0.3 split reports:
  - `ai_service/datasets/bontoc_southern_leyte/v0_3_candidate/reports/`
- training reports:
  - `ai_service/artifacts/reports/`
- training checkpoints:
  - `ai_service/artifacts/checkpoints/`

## AI Model Switch Helper

Use this helper if you want to check or change the default config that the AI service loads on startup.

### Show current model config

```bash
pwsh -File .\tool\switch_ai_model.ps1 -ShowCurrent
```

### Change the active config pointer

```bash
pwsh -File .\tool\switch_ai_model.ps1 -ConfigPath configs\bontoc_southern_leyte_production_candidate_external.yaml
pwsh -File .\tool\switch_ai_model.ps1 -ConfigPath configs\bontoc_southern_leyte_v0_3_candidate_template.yaml
```

### Important note

- this updates `ai_service/active_config.txt`
- restart the AI service after switching
- do not switch to a new candidate unless it passes `ai_service/AI_PROMOTION_CHECKLIST.md`

## Manual Web Startup

Use this if you want to start each backend service one by one.

### Step by step

1. Open PowerShell.
2. Go to the Laravel folder:

```bash
cd "C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch\web_system"
```

3. Start the Laravel web server:

```bash
php artisan serve --host=0.0.0.0 --port=8000
```

4. Open a second PowerShell window in the same `web_system` folder.
5. Start Reverb:

```bash
php artisan reverb:start --host=0.0.0.0 --port=8080
```

6. Open a third PowerShell window.
7. Go to the AI service folder:

```bash
cd "C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch\ai_service"
```

8. Start the AI service:

```bash
C:\laragon\bin\python\python-3.13\python.exe serve_api.py
```

9. Open the web system in your browser:

```bash
http://127.0.0.1:8000
```

## Flutter Helper Script

Run the helper inside `mobile_app/` so the correct API host is used per target:

```bash
pwsh -File .\tool\run_stitch.ps1 -Target windows
pwsh -File .\tool\run_stitch.ps1 -Target android-emulator -DeviceId emulator-5554
pwsh -File .\tool\run_stitch.ps1 -Target android-phone-usb -DeviceId 143352556L110746
pwsh -File .\tool\run_stitch.ps1 -Target android-phone-wifi -HostIp 192.168.1.5 -DeviceId 143352556L110746
```

## Mobile App Run Guide

Start the backend first using either the `One-Click Startup` section or the `Manual Web Startup` section above.

### Windows desktop

1. Open PowerShell.
2. Go to the mobile app folder:

```bash
cd "C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch\mobile_app"
```

3. Run:

```bash
pwsh -File .\tool\run_stitch.ps1 -Target windows
```

### Android emulator

1. Start your Android emulator.
2. Go to the mobile app folder:

```bash
cd "C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch\mobile_app"
```

3. Run:

```bash
pwsh -File .\tool\run_stitch.ps1 -Target android-emulator -DeviceId emulator-5554
```

### Android phone over USB

1. Connect your phone by USB.
2. Enable `USB debugging`.
3. Accept the `Allow USB debugging` prompt on the phone.
4. Go to the mobile app folder:

```bash
cd "C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch\mobile_app"
```

5. Run:

```bash
pwsh -File .\tool\run_stitch.ps1 -Target android-phone-usb -DeviceId 143352556L110746
```

This mode automatically uses `adb reverse`, so the app can reach `http://127.0.0.1:8000/api/v1`.

### Android phone over Wi-Fi

1. Make sure your phone and PC are on the same Wi-Fi network.
2. Go to the mobile app folder:

```bash
cd "C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch\mobile_app"
```

3. Run:

```bash
pwsh -File .\tool\run_stitch.ps1 -Target android-phone-wifi -HostIp 192.168.1.5 -DeviceId 143352556L110746
```

## Quick Test Flow

1. Start the backend services.
2. Open the web system on `http://127.0.0.1:8000` or `http://192.168.1.5:8000`.
3. Run the mobile app.
4. Login or register as `Civilian`.
5. Send an emergency report with GPS, selfie verification, and photo evidence.
6. Login on the web side as `Responder` or `Admin Responder`.
7. Check the incident feed, AI summary, map tracking, selfie, and evidence preview.


## Description-only severity and supporting photos

- Intended workflow: description → text AI suggestion → Admin Receiver confirms or corrects → final severity. An admin can also record an assessment when text AI is unavailable. Confirmation is a human review decision, not a guarantee of 100% model accuracy.
- An unconfirmed text AI result is advisory: `ai_suggested_severity` carries the suggestion for admin clients, while operational severity stays null and confirmed-severity counts/dispatch wait for human review. The original model result remains in the review audit after confirmation or correction.
- Monitoring report modals, Online Report, and report details share a **Confirm or correct severity** form. It records Minor, Serious, Critical, or Needs review, with an internal review reason, reviewer ID, time, original AI suggestion, and description fingerprint. Saving this review keeps coordination status and the civilian response message unchanged.
- Overview keeps response summaries, the review queue, map and readiness; detailed latest reports and civilian lists are available in their dedicated tabs.
- Admin report audit history covers Online and LoRa/Node response/status changes, severity reviews, AI requests and deletions. The separate audit records retain the actor, source/node, recorded time, notes and available before/after values even after report deletion. Search, source/action filters and pagination cover all saved actions.
- The audit migration imports surviving admin coordination entries. Missing historical details remain unrecorded; earlier deleted reports cannot be reconstructed. Audit times display in Asia/Manila.
- CSV/Excel exports append the text review label, reviewer ID, time, note, description fingerprint, review method, and original AI label. An edited description or later conflicting assessment invalidates the prior text label in the export. These are review candidates; training and independent release evaluation remain separate steps.
- New web/API reports use only the submitted emergency description for AI severity suggestions. Photos and verification selfies remain available to responders as evidence.
- The FastAPI runtime exposes `POST /predict/text` only. Its image `/predict` endpoint and image checkpoint loading were removed. Archived ResNet18 experiments are not part of report inference or deployment dependencies.
- Dokploy and the local active profile select the experimental **DualPath Trilingual TF-IDF Logistic Regression v2** text pilot. The [retraining report](ai_service/artifacts/reports/text_retrain_20261016_v2/EVALUATION.md) records a re-fit using the original canonical data/split, including the counted 8,000-word subset without duplicate training rows. Numeric weights and historical diagnostics remain unchanged: 92.7% raw classifier accuracy and 85.9% combined routed accuracy (English 79.7%). No independent real-report accuracy or improvement is claimed. Admin confirmation remains mandatory. Disabled, unavailable, or uncertain inference requires review, with no fabricated confidence. Render's free demo remains manual-only.
- The current text classifier predicts severity (`Minor`, `Serious`, `Critical`, or `Needs review`). Accident **type** classification would require a separately reviewed label set, dataset, and model; it is not inferred today.
- Existing file validation and local photo checks remain; there is no remote photo relevance/severity gate. Old queued image-analysis jobs do nothing, and historical photo assessments and evidence are preserved.
- The initial text model scope is road/vehicle accidents in English, Tagalog, Cebuano, and mixed language. Hardware LoRa priority remains a separate input without photo inference. See [the text AI workflow](ai_service/TEXT_AI_WORKFLOW.md).
