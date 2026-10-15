# Online Wake-Up And Deploy Guide

Step-by-step ni para pa-wake up, paandar, ug redeploy sa online Bontoc Rescue system.

Current Render demo URLs used by this repo:

```text
Web system  : https://stitch-web-demo.onrender.com
AI health   : https://stitch-ai-demo.onrender.com/health
AI readiness: https://stitch-ai-demo.onrender.com/ready
```

Render free services can sleep when unused. Normal ra nga ang first open kay mo take ug 30 to 120 seconds.

## 1. Wake Up The Online Web System

Open this first:

```text
https://stitch-web-demo.onrender.com/system/version
```

Wait until it returns JSON or loads successfully.

Then open:

```text
https://stitch-web-demo.onrender.com/login
```

If loading gihapon, refresh after 30 seconds.

## 2. Wake Up The Online AI Service

Open:

```text
https://stitch-ai-demo.onrender.com/health
```

Expected result:

```json
{
  "status": "ok"
}
```

If AI service was sleeping, the first request can be slow. Wait and refresh.

Then confirm the model is ready for predictions:

```text
https://stitch-ai-demo.onrender.com/ready
```

Expected result:

```json
{
  "status": "ready"
}
```

If `/health` is OK but `/ready` is not ready, the AI service is awake but the model checkpoint is missing or still loading.

## 3. Verify Online Pages

Check these one by one:

```text
https://stitch-web-demo.onrender.com/
https://stitch-web-demo.onrender.com/login
https://stitch-web-demo.onrender.com/register
https://stitch-web-demo.onrender.com/system/version
https://stitch-ai-demo.onrender.com/health
https://stitch-ai-demo.onrender.com/ready
```

After login, verify:

- Civilian dashboard loads
- Send report page loads
- Responder dashboard loads
- Incident feed loads
- Photo upload works
- AI result or AI warning behaves as expected

## 4. If Online Shows Old Design

Use a cache-buster URL:

```text
https://stitch-web-demo.onrender.com/?system_update=latest
```

On laptop:

- Press `Ctrl + F5`
- Or clear browser cache for `stitch-web-demo.onrender.com`

On phone:

1. Open browser settings
2. Site settings
3. Find `stitch-web-demo.onrender.com`
4. Clear site data
5. Open the site again

If installed as PWA:

- Close the app fully
- Reopen it
- If still old, uninstall the PWA and install it again

## 5. Before Deploying Updates

Always test locally first:

```powershell
cd "C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch\web_system"
php artisan test
npm run build
php artisan optimize:clear
```

Then verify localhost:

```text
http://127.0.0.1:8000/login
http://127.0.0.1:8000/register
http://127.0.0.1:8000/dashboard
http://127.0.0.1:8100/health
```

## 6. Check GitHub Connection

Go to repo root:

```powershell
cd "C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch"
```

Check current branch and remote:

```powershell
git branch --show-current
git remote -v
git status --short
```

This repo is normally connected to:

```text
https://github.com/Chuan2018-dev/BontocRescue.git
```

Do not push `.env`, API keys, passwords, `tool/runtime`, or local logs.

## 7. Commit Local Updates

Only commit the files that are part of the update:

```powershell
git status --short
git add <file-or-folder>
git commit -m "Describe the update"
```

Example:

```powershell
git add web_system\resources web_system\public\build
git commit -m "Update civilian and responder UI"
```

## 8. Push To GitHub

Push to the branch connected to Render:

```powershell
git push origin HEAD
```

If Render deploys from a specific branch, push to that branch:

```powershell
git push origin HEAD:laragon-sync-2026-04-27
```

If permission denied:

```powershell
gh auth status
gh auth login
```

Login using the GitHub account that has access to:

```text
Chuan2018-dev/BontocRescue
```

## 9. Redeploy On Render

If Auto Deploy is enabled:

- Render redeploys after GitHub push

If manual deploy is needed:

1. Open Render dashboard
2. Open `stitch-web-demo`
3. Click `Manual Deploy`
4. Choose `Deploy latest commit`
5. Wait until status is `Live`

For AI updates:

1. Open `stitch-ai-demo`
2. Click `Manual Deploy`
3. Choose `Deploy latest commit`
4. Wait until status is `Live`

## 10. Verify After Deploy

Open:

```text
https://stitch-web-demo.onrender.com/system/version
https://stitch-web-demo.onrender.com/login
https://stitch-web-demo.onrender.com/register
https://stitch-ai-demo.onrender.com/health
https://stitch-ai-demo.onrender.com/ready
```

Then test real app flow:

1. Login as civilian
2. Send a report
3. Login as responder
4. Check dashboard and incident feed
5. Confirm the updated design/functionality appears online

## 11. If Render Deployment Fails

Check Render logs first.

Common causes:

- Missing `APP_KEY`
- Wrong database credentials
- Wrong `APP_URL`
- Wrong `REVERB_HOST`
- Build failed during `npm run build`
- Laravel migration failed
- AI checkpoint failed to download

Check health routes:

```text
https://stitch-web-demo.onrender.com/system/version
https://stitch-ai-demo.onrender.com/health
https://stitch-ai-demo.onrender.com/ready
```

## 12. Localhost And Online Must Match

Recommended workflow every update:

1. Update code locally
2. Run local build/tests
3. Run localhost and test on laptop/phone
4. Commit only the intended files
5. Push to GitHub
6. Let Render redeploy
7. Verify online routes
8. Clear browser/PWA cache if old design appears

Do not assume localhost and online are the same until both are checked.
