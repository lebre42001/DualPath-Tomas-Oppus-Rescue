# ResQMesh Android APK

This Android launcher opens the existing Laravel PWA at
`https://dualpath.bsit4bdevs.tech/login?source=android-apk` using a Trusted Web
Activity. The same online accounts, reports, photo/selfie capture, location,
settings and admin backend are used. Web updates arrive from the server without
rebuilding the APK. The legacy Flutter project in `mobile_app/` is separate.

- Package: `tech.bsit4bdevs.dualpath.rescue`
- Version: `1.0.2`, version code `3`
- Minimum Android API: `24` (Android 7); a compatible, updated browser is required.
- Source generated with Google Bubblewrap `1.27.0` and Android Browser Helper
  `2.7.4`. Gradle and Android dependency versions are pinned in this project.
- The installed app uses the website's logo. The website must serve
  `/.well-known/assetlinks.json` with the release signing certificate fingerprint.
  When browser/domain verification is unavailable, Custom Tabs is the fallback.

## Build and sign on this Laragon workspace

The official ResQMesh artwork is preserved in `web_system/resources/branding/resqmesh-emblem.png`. Run `php tool/generate_brand_assets.php` from the repository root to reproduce the website, PWA, favicon, Android launcher, maskable, splash, and store images. Resizing preserves the complete artwork on white; maskable variants include safe padding. Both admin and civilian interfaces use the same logo. Android 1.0.2 uses ResQMesh as the application and launcher name, with the official icon/splash artwork and the existing signing identity. Install the newer APK to update the name on an existing installation.

Requirements: JDK 17, Android platform 36, Android Build Tools 36.0.0. The first
build also downloads the pinned Gradle and Maven dependencies.

```powershell
cd C:\laragon\www\TomasOppusRescue-main
powershell -ExecutionPolicy Bypass -File .\tool\build_android_apk.ps1
```

The script finds the local tools in `tool/runtime/android-tools/`, or accepts
`-JavaDirectory` and `-SdkDirectory`. It compiles, runs release lint, aligns,
signs, verifies and creates an APK and SHA-256 checksum in
`tool/runtime/android-release/`.

The persistent signing key is `tool/runtime/android-signing/release.jks`. Its
password is stored in a Windows-encrypted `release-credentials.clixml` readable
by the same Windows account. Alternatively supply `RESCUE_ANDROID_STORE_PASSWORD`
through the process environment. These files are ignored by Git. Back up the
key and password securely: future APK updates must use the same signing key.
Windows-encrypted credentials need a secure export before moving machines.

## Publish

1. Increase `appVersionName` and `appVersionCode` in `twa-manifest.json` and the
   matching values in `app/build.gradle` for a new Android binary.
2. Build and verify the APK, then upload the APK and its `.sha256` file to the
   GitHub release `android-v<version>` in this repository.
3. Update `web_system/config/android-app.php` to that release's download URL.
   Push the web changes and verify `/download/android` reaches the actual APK.
4. Keep the signing fingerprint in the public Digital Asset Links file aligned
   with the APK certificate. Verify the public HTTPS file after deployment.

Home, Login and Register show **Download App — Android APK**. iPhone/iPad users
receive the existing Safari installation instructions instead of an APK.
Android users open the downloaded file and confirm installation; Android may
ask them to permit installation from Chrome. This release is distributed
directly, and is not a Google Play listing.

The build/lint/signature and public delivery checks do not replace installation,
camera, GPS and Google sign-in checks on a physical Android phone.
