# AGENTS.md

This file applies to the whole repository at:

- `C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch`

## Source Of Truth

- Treat this Laragon repo as the only working copy unless the user explicitly says otherwise.
- Do not read from, edit, or compare against the OneDrive copy by default.
- When giving commands, file paths, or deployment notes, use this Laragon repo only.

## Project Shape

- Primary mobile product: installable Laravel Progressive Web App in `web_system/`
- Legacy mobile reference only: Flutter project in `mobile_app/`
- Web backend and realtime: Laravel + Reverb in `web_system/`
- AI service: FastAPI + PyTorch in `ai_service/`
- Local orchestration scripts: `tool/`
- UI mockup references: `01_onboarding/`, `02_auth/`, `03_app_core/`, `04_reporting/`

## Default Assumptions

- If the user says `mobile app`, assume the PWA in `web_system/` unless they explicitly say `Flutter`.
- If the user asks for UI, auth, reporting, responder, civilian, dashboard, or PWA work, start in `web_system/`.
- If the user asks about models, datasets, inference, checkpoints, or training, work in `ai_service/`.
- If the user asks about startup, health, status, or service control, work in `tool/`.

## Preferred Working Areas

- Most product changes belong in `web_system/`.
- Use `mobile_app/` only for legacy Flutter-specific requests.
- Keep deployment scaffolding in sync with the actual repo behavior.
- Keep docs aligned with the live Laragon setup, not an abstract environment.

## Key Local Commands

Repo root:

```powershell
cd "C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch"
pwsh -File .\tool\start_stitch_services.ps1
pwsh -File .\tool\status_stitch_services.ps1
pwsh -File .\tool\stop_stitch_services.ps1
```

Laravel app:

```powershell
cd "C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch\web_system"
php artisan test
npm run build
php artisan optimize:clear
```

AI service:

```powershell
cd "C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch\ai_service"
C:\laragon\bin\python\python-3.13\python.exe serve_api.py
C:\laragon\bin\python\python-3.13\python.exe train.py
```

## Expected Local Ports

- Laravel web: `http://127.0.0.1:8000`
- Reverb: `http://127.0.0.1:8080`
- AI health: `http://127.0.0.1:8100/health`

## Working Rules

- Favor the PWA/Laravel path over Flutter for new work.
- Preserve the current Laravel + Reverb + FastAPI architecture unless the user explicitly asks for a migration.
- Keep responder and civilian flows consistent.
- Prefer minimal changes that match the existing structure over broad rewrites.
- Do not silently switch environments, hosts, or repo copies.
- After every system update, make localhost testing and the online deployment match the intended latest code and behavior.
- Before calling an update done, refresh local dependencies/caches/assets as needed, verify `http://127.0.0.1:8000`, then verify or prepare the online deployment path.

## Validation Rules

- For `web_system/` code changes, prefer:
  - `php artisan test`
  - `npm run build`
- For `tool/` script changes, verify the affected script syntax or a safe status command.
- For `ai_service/` changes, run only the smallest relevant validation unless the user explicitly asks for training or long runs.
- If a change is docs-only, say that no runtime validation was needed.

## Deployment Rules

- Main deployment plan lives in `DEPLOYMENT_RENDER_RAILWAY.md` and `render.yaml`.
- Free online testing currently uses the root `render.yaml` demo services unless a separate profile is added later.
- Treat localhost and the deployed Render app as a pair: if one is updated, confirm whether the other needs the same commit, rebuild, cache clear, or redeploy.
- Do not assume local and online are aligned just because one route loads; compare the active branch/commit and at least one real app route.
- Free-demo assumptions must remain explicit:
  - AI disabled
  - local file storage
  - free Postgres
  - not production-safe
- Keep deployment docs consistent with the actual Dockerfiles, env examples, and startup behavior.
- Do not mark a deployment as successful until the public URL and a real application route are both verified.

## Current Demo Deployment

- Public free-demo URL: `https://stitch-web-demo.onrender.com`
- Last verified status on `2026-04-23`:
  - `/system/version` = `200`
  - `/` = `500`
  - `/login` = `500`
  - `/register` = `500`
- Treat the current Render free-demo deployment as partially alive, not stable.
- Do not describe the Render demo as working until the public app routes stop returning `500`.

## web_system Rules

- Treat `web_system/` as the primary product surface for civilian, responder, admin, and PWA work.
- Preserve the Laravel Blade + Vite + Reverb structure unless the user explicitly asks for a framework change.
- Prefer mobile-first, installable PWA behavior over desktop-only layouts.
- Keep civilian flows simple, high-contrast, and fast to scan, especially for reporting and readiness checks.
- Keep responder views focused on triage speed: clear severity, location, media, route, and AI summary blocks.
- When changing auth, reporting, dashboard, settings, or PWA behavior, keep `login`, `register`, `dashboard`, and report flows visually consistent.
- Favor small Blade and JS changes that fit the current structure over introducing new frontend frameworks.
- After meaningful `web_system/` changes, prefer validating with:
  - `php artisan test`
  - `npm run build`
  - `php artisan optimize:clear`

## ai_service Rules

- Treat `ai_service/` as a separate service with its own configs, checkpoints, datasets, and docs.
- Do not switch the active model, config, or checkpoint silently.
- Keep `active_config.txt`, `README.md`, dataset notes, and training artifacts aligned when behavior changes.
- Prefer the smallest relevant validation first:
  - syntax check
  - health check
  - targeted inference test
- Do not start long training runs unless the task explicitly calls for training.
- Do not promote experimental or low-sample checkpoints to live use without documenting why.
- Keep free-demo deployment assumptions explicit: if AI is disabled online, say that clearly in docs and status notes.

## AI Rules

- Do not switch the active AI model unless the task explicitly requires it.
- Do not change dataset labels or severity mappings casually.
- Do not treat demo/sample uploads as production-grade training data unless the user explicitly says so.
- Keep `active_config.txt`, dataset notes, and training docs aligned when AI behavior changes.
- If AI is disabled in a deployment profile, document that clearly instead of implying full-stack parity.

## Secrets And Safety

- Never commit API keys, `.env` values, generated passwords, runtime credentials, or local logs.
- Treat files under `tool/runtime/` as sensitive/local unless the user explicitly asks to expose something.
- Do not assume the free-demo deployment profile is suitable for stable production use.

## High-Value Repo Files

- Root workspace guide: `README.md`
- Main deployment plan: `DEPLOYMENT_RENDER_RAILWAY.md`
- Demo deployment profile: `render.yaml`
- Web app: `web_system/`
- AI service: `ai_service/`
- Startup helpers: `tool/`

## Update Standard

- Keep this file practical, short, and specific to this Laragon repo.
- Prefer concrete paths, commands, and defaults over generic advice.
