# Stitch Rescue Mobile App

Flutter client for the Stitch rescue workflow.

## Stack

- Flutter (Dart)
- Laravel API backend in `../web_system`
- MySQL database for shared operational data

## Available Flows

- Onboarding: splash, welcome
- Authentication: login, register
- App Core: dashboard, profile, settings
- Reporting: emergency, details, history, success, transmission, AI severity, sentinel response

## Run Targets

```bash
pwsh -File .\tool\run_stitch.ps1 -Target windows
pwsh -File .\tool\run_stitch.ps1 -Target android-emulator -DeviceId emulator-5554
pwsh -File .\tool\run_stitch.ps1 -Target android-phone-usb -DeviceId 143352556L110746
pwsh -File .\tool\run_stitch.ps1 -Target android-phone-wifi -HostIp 192.168.1.4 -DeviceId 143352556L110746
```

## API Host Rules

- Windows desktop uses `http://127.0.0.1:8000/api/v1`
- Android emulator uses `http://10.0.2.2:8000/api/v1`
- Android phone over USB uses `adb reverse` and then `http://127.0.0.1:8000/api/v1`
- Android phone over Wi-Fi uses your PC LAN IP, for example `http://192.168.1.4:8000/api/v1`

## Structure

- `lib/app.dart` boots the app shell from the authenticated session state
- `lib/core/config/` holds API base URL selection
- `lib/core/network/` contains the Laravel API client and error handling
- `lib/core/session/` contains session restore, auth, and shared app state
- `lib/features/` contains auth, dashboard, reports, profile, and settings screens
- `tool/run_stitch.ps1` runs the correct target with the correct API host
