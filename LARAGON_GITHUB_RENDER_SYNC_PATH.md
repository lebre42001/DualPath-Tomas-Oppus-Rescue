# Laragon -> GitHub -> Render Sync Path

This guide is for the exact repo at:

- `C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch`

Use this path when you want to push the Laragon source-of-truth copy online safely.

## Current Reality

- This Laragon folder is the source of truth for local development.
- This Laragon folder is currently **not** a Git repository.
- The public GitHub repo already exists:
  - `https://github.com/Chuan2018-dev/BontocRescue`
- The current Render free-demo flow is tied to the GitHub repo, not directly to this Laragon folder.

## Safe Goal

The safe goal is:

1. validate the Laragon copy locally
2. initialize Git here only if needed
3. connect this Laragon repo to GitHub without force-pushing blindly
4. push to a sync branch first
5. merge intentionally
6. let Render redeploy from GitHub

## Before You Push

Run these first from the Laragon repo:

```powershell
cd "C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch\web_system"
php artisan test --filter=AuthAndReportFlowTest
php artisan test --filter=ApiMobileFlowTest
npm run build
php artisan optimize:clear
```

And verify the live local services:

- `http://127.0.0.1:8000/login`
- `http://127.0.0.1:8100/health`

## Recommended Sync Flow

### 1. Back up the Laragon copy first

Create a zip or folder backup before the first Git initialization in this Laragon path.

### 2. Check sync readiness

Use:

```powershell
cd "C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch"
powershell -ExecutionPolicy Bypass -File .\tool\check_laragon_github_render_sync.ps1
```

### 3. Initialize Git in the Laragon repo if `.git` is missing

```powershell
cd "C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch"
git init
git branch -M main
```

### 4. Add the GitHub remote

```powershell
git remote add origin https://github.com/Chuan2018-dev/BontocRescue.git
git remote -v
```

If `origin` already exists, update it instead:

```powershell
git remote set-url origin https://github.com/Chuan2018-dev/BontocRescue.git
```

### 5. Fetch GitHub history without forcing anything

```powershell
git fetch origin
```

### 6. Create a dedicated Laragon sync branch

Use a dated branch name:

```powershell
git checkout -b laragon-sync-2026-04-27
```

### 7. Review what you are about to publish

At minimum review these areas:

- `web_system/`
- `ai_service/`
- `tool/`
- `render.yaml`
- `render.free-demo.yaml`
- deployment docs

### 8. Add and commit the Laragon state

```powershell
git add .
git status
git commit -m "Sync Laragon source-of-truth updates"
```

### 9. Push the sync branch first

```powershell
git push -u origin laragon-sync-2026-04-27
```

Do **not** force-push to `main` from this Laragon folder unless you have already reviewed the GitHub history and intentionally decided to overwrite it.

### 10. Open a PR to `main`

Suggested PR title:

- `Sync Laragon source-of-truth updates`

### 11. Merge only after checks are clean

Confirm:

- Laravel tests pass
- frontend build passes
- deployment files still match the actual app behavior
- no secrets or runtime files are included

### 12. Let Render redeploy from GitHub

After merge, verify:

- public URL responds
- `/login` works
- `/system/version` works
- civilian upload flow works
- AI expectations match the current deployment profile

## Render Note

The current Render free-demo profile is not the same as the full local stack:

- free-demo may disable AI or use reduced assumptions
- local Laragon stack is still the full reference behavior

So after GitHub sync, always verify whether:

- the deployment is `web-only free demo`
- or the deployment is expected to include AI and realtime behavior

## Do Not Publish

Do not publish these blindly:

- `.env`
- runtime logs
- `tool/runtime/*`
- local secrets
- downloaded datasets that are not meant for the repo
- large checkpoints unless intentionally tracked

## Best Practice

The safest long-term workflow is:

1. keep Laragon as source of truth
2. make small tested changes locally
3. push to a sync branch
4. review the PR
5. let Render build from GitHub
