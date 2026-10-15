# Localhost Run Guide

Step-by-step ni para paandaron ang Bontoc Rescue system sa laptop ug ma-access pud sa phone sa same Wi-Fi.

## 1. Open PowerShell

Open PowerShell, then adto sa project root:

```powershell
cd "C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch"
```

## 2. Start Laragon / MySQL

Before paandaron ang system, make sure:

- Laragon is running
- MySQL is started
- Database exists locally, usually `stitch_system`

If database connection error mo appear, open Laragon and start `MySQL`.

## 3. Start All Local Services

Run this one command:

```powershell
pwsh -File .\tool\start_stitch_services.ps1
```

This starts:

- Laravel web system on port `8000`
- Reverb realtime server on port `8080`
- AI service on port `8100`

## 4. Open On Laptop

Use this URL on the laptop:

```text
http://127.0.0.1:8000
```

Useful pages:

```text
http://127.0.0.1:8000/login
http://127.0.0.1:8000/register
http://127.0.0.1:8000/dashboard
http://127.0.0.1:8100/health
```

## 5. Open On Phone

After running the startup script, look for this line:

```text
LAN Web UI : http://<your-laptop-ip>:8000
```

Example:

```text
http://192.168.1.2:8000
```

Open that URL on the phone.

Important:

- Laptop and phone must be connected to the same Wi-Fi.
- Use the `LAN Web UI` printed by the script, not always `127.0.0.1`.
- `127.0.0.1` only works on the laptop itself.

## 6. Check Current Status

If unsure whether the system is already running:

```powershell
pwsh -File .\tool\status_stitch_services.ps1
```

Expected:

```text
laravel-serve running
reverb        running
ai-service    running
Web           HTTP 200
AI            HTTP 200
Reverb        ready
```

## 7. Stop The Local System

When done testing:

```powershell
pwsh -File .\tool\stop_stitch_services.ps1
```

## 8. If Phone Is Loading Too Long

### Check the IP address

Run status again:

```powershell
pwsh -File .\tool\status_stitch_services.ps1
```

Use the latest `LAN Web UI`.

If your laptop IP changed, update `web_system\.env`:

```text
APP_URL=http://<your-laptop-ip>:8000
REVERB_HOST=<your-laptop-ip>
```

Then rebuild and clear cache:

```powershell
cd "C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch\web_system"
npm run build
php artisan optimize:clear
```

After that, restart services:

```powershell
cd "C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch"
pwsh -File .\tool\stop_stitch_services.ps1
pwsh -File .\tool\start_stitch_services.ps1
```

### Clear phone browser cache

On Android Chrome:

1. Open Chrome settings
2. Site settings
3. All sites
4. Find the local IP, for example `192.168.1.2`
5. Clear and reset
6. Open again:

```text
http://<your-laptop-ip>:8000/?system_update=local
```

### Check Windows Firewall

If laptop works but phone cannot open:

- Allow PHP / Laragon through Windows Firewall
- Make sure the Wi-Fi network is set to Private
- Temporarily turn off phone mobile data while testing local Wi-Fi

## 9. After Every Local Code Update

Run these before testing:

```powershell
cd "C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch\web_system"
npm run build
php artisan optimize:clear
```

For bigger updates, also run tests:

```powershell
php artisan test
```

Then restart the services:

```powershell
cd "C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch"
pwsh -File .\tool\stop_stitch_services.ps1
pwsh -File .\tool\start_stitch_services.ps1
```

## 10. Logs

Local runtime logs are stored here:

```text
C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch\tool\runtime
```

Useful log files:

```text
laravel-serve.out.log
laravel-serve.err.log
reverb.out.log
reverb.err.log
ai-service.out.log
ai-service.err.log
```
