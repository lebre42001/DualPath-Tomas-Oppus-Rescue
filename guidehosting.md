# Bontoc Rescue Online Hosting Guide

This guide explains how to host the system online from the start, using the
simplest testing setup first.

Recommended beginner path:

1. GitHub for source code
2. Render Web Service for the Laravel PWA
3. Render PostgreSQL for the online database
4. Render free/testing plan if available

Use this guide from this project folder:

```powershell
C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch
```

## Important Notes First

- Do not upload `.env` files to GitHub.
- Do not upload API keys, database passwords, or Render keys.
- The free/testing setup is for online demo and phone testing.
- Free hosting can sleep when unused, so the first load can take 30 to 90 seconds.
- Local file uploads on Render can disappear after redeploy/restart. For real production, use Cloudflare R2 or S3.
- The AI service can be disabled first for easier hosting, then enabled later.

## Current Working Demo URL

The current online demo is:

```text
https://stitch-web-demo.onrender.com/login
```

Health check:

```text
https://stitch-web-demo.onrender.com/system/version
```

## Part 1: Prepare The Project Locally

### 1. Open PowerShell

Go to the project root:

```powershell
cd "C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch"
```

### 2. Check That Git Is Available

```powershell
git --version
```

If Git is missing, install Git for Windows first.

### 3. Test The Laravel App Before Hosting

```powershell
cd "C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch\web_system"
php artisan test --filter=AuthAndReportFlowTest
npm run build
php artisan optimize:clear
```

Expected result:

```text
Tests passed
Build successful
```

If tests fail, fix them before hosting.

## Part 2: Push The Code To GitHub

### 1. Go Back To The Repo Root

```powershell
cd "C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch"
```

### 2. Initialize Git If Needed

If the folder is not yet a Git repository:

```powershell
git init
git branch -M main
```

If it already has Git, just check status:

```powershell
git status -sb
```

### 3. Connect To GitHub

Use your GitHub repository:

```powershell
git remote add origin https://github.com/Chuan2018-dev/BontocRescue.git
```

If `origin` already exists:

```powershell
git remote set-url origin https://github.com/Chuan2018-dev/BontocRescue.git
```

Check remote:

```powershell
git remote -v
```

### 4. Commit The Project

```powershell
git add .
git status
git commit -m "Prepare Bontoc Rescue for online hosting"
```

### 5. Push To GitHub

For a normal first setup:

```powershell
git push -u origin main
```

If you are using a separate branch:

```powershell
git push -u origin laragon-sync-2026-04-27
```

Important:

- Do not force-push unless you are sure.
- Render can deploy from `main` or from your selected branch.

## Part 3: Create The Online Database

For simple online testing, use PostgreSQL on Render.

### 1. Open Render Dashboard

Go to:

```text
https://dashboard.render.com
```

### 2. Create A PostgreSQL Database

In Render:

1. Click `New`
2. Choose `PostgreSQL`
3. Give it a name, for example:

```text
bontoc-rescue-db
```

4. Choose a region near the Philippines if available, for example:

```text
Singapore
```

5. Choose the free/testing plan if available.
6. Create the database.

### 3. Save These Database Values

From the Render database page, copy:

```text
Host
Port
Database
Username
Password
```

For Laravel env values, map them like this:

```text
DB_CONNECTION=pgsql
DB_HOST=<Render internal database host>
DB_PORT=5432
DB_DATABASE=<database name>
DB_USERNAME=<database username>
DB_PASSWORD=<database password>
DB_SSLMODE=prefer
```

Use the internal database host if the web service is also on Render.

## Part 4: Generate App Keys

### 1. Generate Laravel APP_KEY

From `web_system`:

```powershell
cd "C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch\web_system"
php artisan key:generate --show
```

Copy the full output:

```text
base64:xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx=
```

This becomes:

```text
APP_KEY=base64:...
```

### 2. Generate Reverb Keys

Use PowerShell:

```powershell
php -r "echo bin2hex(random_bytes(16));"
```

Run it twice:

```text
REVERB_APP_KEY=<first random value>
REVERB_APP_SECRET=<second random value>
```

## Part 5: Create The Render Web Service

### 1. Create A Web Service

In Render:

1. Click `New`
2. Choose `Web Service`
3. Connect your GitHub repository:

```text
Chuan2018-dev/BontocRescue
```

4. Choose the branch you want to deploy.

Recommended:

```text
main
```

If your latest code is on another branch, choose that branch instead.

### 2. Render Web Service Settings

Use these values:

```text
Name: bontoc-rescue-web
Environment: Docker
Region: Singapore
Root Directory: leave blank
Dockerfile Path: ./web_system/deploy/render/Dockerfile
Docker Context Directory: ./web_system
Health Check Path: /system/version
Auto Deploy: Yes
Plan: Free/testing if available
```

Do not use `render.yaml` if you only want the free web demo first.

The root `render.yaml` is more production-oriented and can create extra paid/private services.

## Part 6: Add Render Environment Variables

In the Render web service:

1. Open `Environment`
2. Add these variables
3. Save changes

### Required Laravel Values

```text
APP_NAME=Bontoc Rescue
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<your-render-service>.onrender.com
APP_KEY=<your generated base64 key>
LOG_CHANNEL=stderr
LOG_LEVEL=debug
```

After the app is stable, you can change:

```text
LOG_LEVEL=info
```

### Database Values

```text
DB_CONNECTION=pgsql
DB_HOST=<Render internal database host>
DB_PORT=5432
DB_DATABASE=<database name>
DB_USERNAME=<database username>
DB_PASSWORD=<database password>
DB_SSLMODE=prefer
```

### Session, Cache, Queue

```text
SESSION_DRIVER=file
SESSION_SECURE_COOKIE=true
CACHE_STORE=file
QUEUE_CONNECTION=sync
```

### Upload Storage For Free Testing

```text
FILESYSTEM_DISK=local
```

Important:

This is okay for testing, but uploaded files may disappear after redeploy or restart.

For production, use R2 or S3.

### AI Setting For First Online Test

Disable AI first so the web system can go online faster:

```text
AI_SEVERITY_ENABLED=false
```

Enable AI only after the web app is stable online.

### Realtime/Reverb Values

```text
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=stitch-reverb-app
REVERB_APP_KEY=<random key>
REVERB_APP_SECRET=<random secret>
REVERB_HOST=<your-render-service>.onrender.com
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080
```

Example:

```text
REVERB_HOST=stitch-web-demo.onrender.com
```

Do not include `https://` in `REVERB_HOST`.

### Routing Values

```text
ROUTING_SERVICE_ENABLED=true
ROUTING_SERVICE_URL=https://router.project-osrm.org
ROUTING_SERVICE_PROFILE=driving
ROUTING_SERVICE_TIMEOUT=8
ROUTING_SERVICE_CACHE_TTL_MINUTES=10
ROUTING_SERVICE_PROVIDER=OSRM Public Routing
```

## Part 7: Deploy The App

### 1. Trigger Deploy

In Render:

1. Open the web service
2. Click `Manual Deploy`
3. Choose `Deploy latest commit`

If auto deploy is enabled, pushing to GitHub can trigger deploy automatically.

### 2. Wait For Build

Render will:

1. Install PHP dependencies
2. Install Node dependencies
3. Build frontend assets
4. Build Docker image
5. Start Laravel, Reverb, PHP-FPM, and Nginx
6. Run migrations

This can take several minutes.

### 3. Check Deploy Logs

Look for:

```text
Configuration cached successfully
fpm is running
ready to handle connections
Starting server on 127.0.0.1:8080
```

If deploy fails, read the first real error in the logs.

## Part 8: Verify The Online System

### 1. Check Health URL

Open:

```text
https://<your-render-service>.onrender.com/system/version
```

Expected:

```json
{
  "version": "...",
  "generated_at": "..."
}
```

### 2. Check Login Page

Open:

```text
https://<your-render-service>.onrender.com/login
```

Expected:

- Login page loads
- No browser warning about insecure form submit
- Password show/hide works
- Download App button appears

### 3. Check Register Page

Open:

```text
https://<your-render-service>.onrender.com/register
```

Try creating:

1. One civilian account
2. One responder account

### 4. Check Civilian Flow

Login as civilian.

Verify:

- Opens emergency report form
- Can capture or attach photo
- Can capture selfie
- Can lock GPS
- Requires short description
- Cannot submit if required fields are missing

### 5. Check Responder Flow

Login as responder.

Verify:

- Dashboard opens
- Monitoring opens
- Incident Feed opens
- Civilian Accounts opens
- Responder Profile opens
- System Settings opens

### 6. Check PWA Install

Android Chrome:

1. Open the site
2. Tap the Download App button or browser menu
3. Choose install/add to home screen

iPhone Safari:

1. Open the site in Safari
2. Tap Share
3. Tap Add to Home Screen

PWA install works best on HTTPS, so use the Render URL, not plain HTTP.

## Part 9: How To Update The Online Site

Every time you change code locally:

### 1. Test Locally

```powershell
cd "C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch\web_system"
php artisan test --filter=AuthAndReportFlowTest
npm run build
php artisan optimize:clear
```

### 2. Commit And Push

```powershell
cd "C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch"
git add .
git status
git commit -m "Describe your update"
git push
```

### 3. Wait For Render

If auto deploy is on, Render redeploys automatically.

If not:

1. Open Render web service
2. Click `Manual Deploy`
3. Choose `Deploy latest commit`

### 4. Verify Again

Open:

```text
https://<your-render-service>.onrender.com/system/version
https://<your-render-service>.onrender.com/login
```

If the browser still shows the old design:

- Press `Ctrl + F5` on laptop/PC
- Clear site cache on phone
- Reopen the PWA from the home screen

## Part 10: Common Problems And Fixes

### Problem: Browser says form submission is not secure

Cause:

Laravel generated `http://` form actions behind Render's HTTPS proxy.

Fix:

Make sure this exists in `web_system/bootstrap/app.php`:

```php
$middleware->trustProxies(at: '*');
```

Then redeploy.

Check the login HTML. It should show:

```html
action="https://<your-render-service>.onrender.com/login"
```

### Problem: 500 error on login or welcome page

Possible cause:

Blade view cache path is missing.

Fix:

Make sure this file exists:

```text
web_system/config/view.php
```

Also make sure the Render start script creates these folders:

```text
storage/framework/cache/data
storage/framework/sessions
storage/framework/views
bootstrap/cache
```

### Problem: Deploy stuck during health check

Check:

```text
/system/version
```

If `/system/version` returns 500, inspect Render logs.

Common causes:

- Missing `APP_KEY`
- Wrong database credentials
- Missing storage/cache folders
- Wrong `APP_URL`
- Database not reachable

### Problem: Login works but dashboard fails

Check:

- Database migrations ran successfully
- `DB_CONNECTION`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- `SESSION_DRIVER=file`
- `SESSION_SECURE_COOKIE=true`

### Problem: Uploaded photos disappear

Cause:

Free web service filesystem is not permanent.

Testing fix:

- Upload again after redeploy.

Production fix:

- Use Cloudflare R2 or AWS S3.
- Change `FILESYSTEM_DISK=s3`.
- Add AWS/R2 environment variables.

### Problem: AI does not analyze uploaded photos online

Cause:

First free demo can use:

```text
AI_SEVERITY_ENABLED=false
```

Production fix:

- Deploy the AI service separately.
- Set `AI_SEVERITY_ENABLED=true`.
- Add AI service host and port.

## Part 11: Production Upgrade Later

After the free online demo works, upgrade these:

### Persistent Uploads

Use Cloudflare R2 or AWS S3:

```text
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=<key>
AWS_SECRET_ACCESS_KEY=<secret>
AWS_DEFAULT_REGION=<region>
AWS_BUCKET=<bucket>
AWS_ENDPOINT=<endpoint if R2>
AWS_URL=<optional public URL>
AWS_USE_PATH_STYLE_ENDPOINT=false
```

### More Stable Database

Options:

- Render PostgreSQL
- Railway MySQL
- Aiven MySQL

If using Railway MySQL:

```text
DB_CONNECTION=mysql
DB_HOST=<Railway MYSQLHOST>
DB_PORT=<Railway MYSQLPORT>
DB_DATABASE=<Railway MYSQLDATABASE>
DB_USERNAME=<Railway MYSQLUSER>
DB_PASSWORD=<Railway MYSQLPASSWORD>
```

### Enable AI Online

Deploy the AI service with:

```text
ai_service/deploy/render/Dockerfile
```

Then set:

```text
AI_SEVERITY_ENABLED=true
AI_SEVERITY_SERVICE_HOST=<private AI service host>
AI_SEVERITY_SERVICE_PORT=<private AI service port>
AI_SEVERITY_TIMEOUT=20
AI_SEVERITY_DISPATCH=sync
AI_SEVERITY_MODEL_NAME=<model name>
AI_SEVERITY_MODEL_VERSION=<model version>
```

## Final Checklist

Before saying the online system is ready, confirm:

- GitHub has the latest code.
- Render deploy status is `Live`.
- `/system/version` returns JSON.
- `/login` loads over HTTPS.
- Login form action uses HTTPS.
- `/register` works.
- Civilian account can send a report.
- Responder account can open dashboard and incident feed.
- PWA manifest loads:

```text
https://<your-render-service>.onrender.com/manifest.webmanifest
```

- Service worker loads:

```text
https://<your-render-service>.onrender.com/sw.js
```

If all checks pass, the system is ready for online testing.
