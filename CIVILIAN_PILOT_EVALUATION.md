# Civilian online pilot evaluation

Review date: 2026-11-06. Working copy: `C:\laragon\www\TomasOppusRescue-main`.

The seven supplied statements mostly fit the Laravel civilian PWA. They evaluate
the online path, not the complete ESP32/LoRa/AI system. Scores must come from the
functions each participant actually observes; no implementation guarantees a 5.

## Fit and evidence

| Supplied item | Fit | Implementation and limits |
| --- | --- | --- |
| Functional completeness | Fits the stated online task | A verified civilian opens `/reports/create` and starts a Road Accident report. Login/account verification, scene photo, GPS, landmark, description and live selfie are prerequisites for sending. This one task does not establish completeness of the entire system. |
| Functional appropriateness | Fits | The form groups scene photo/location, short description, then selfie verification and sending. Device-readiness guidance and local drafts support recovery. |
| Functional correctness — Node/Location | Revise for this path, or mark N/O | Online civilian reports use device GPS and a typed landmark. Configured node coordinates belong to hardware LoRa reports. Do not add an artificial node association just to satisfy this wording. |
| Compatibility — Mobile Browser | Fits with a recorded device matrix | Test the actual Android Chrome/iPhone Safari versions and devices in the pilot. A responsive layout/build does not certify physical-device camera, GPS, blink detection or upload behavior. |
| Self-descriptiveness — Connection State | Supported by this update | A visible status checks the authenticated reporting server on load, reconnection, visible-page return and every 30 seconds while visible. It distinguishes checking, reachable, offline, unavailable and expired/unconfirmed session. “Check again” performs a read-only check. A successful probe does not guarantee a subsequent upload or AI/liveness availability. |
| Reliability — Duplicate Prevention | Fits one reporting action | The form/draft keeps a submission UUID; the backend deduplicates by reporter plus submission ID with a unique ingest key. An interrupted attempt can look up its receipt and retry without creating another report. A deliberately new action/new UUID is a separate report; this is not general duplicate-incident detection. |
| Confidentiality — Civilian Access | Fits with technical verification | History/detail/media access is scoped to the reporter; operational routes use role middleware and incident broadcasts use a private responder channel. Technical evaluators should verify direct URLs, files and channel authorization; merely hiding a button is insufficient evidence. |

These are system-specific operational indicators informed by ISO/IEC 25010:2023,
not verbatim ISO criteria or a certification. The official product-quality model
is at <https://www.iso.org/standard/78176.html>.

## Questionnaire corrections

Add `Civilian / Online Reporter` to respondent roles. Add `Online civilian
reporting (PWA/mobile browser)` to functions observed. A civilian using only the
online path should not be required to rate local ESP32, LoRa relay, AI accuracy,
or server internals they did not observe. Keep N/O available and outside the 1–5
score calculation; report its count separately.

Replace the online Node/Location statement with:

**Functional Correctness — GPS/Location:** During the online-path test, the
submitted report retains the GPS coordinates and barangay/road/landmark provided
by the civilian, and the authorized reviewer can view that same location.

*Sa pagsubok ng online path, napapanatili sa ipinadalang ulat ang GPS coordinates
at barangay/kalsada/palatandaang ibinigay ng mamamayan, at nakikita ng awtorisadong
tagasuri ang parehong lokasyon.*

Keep configured-node accuracy as a separate LoRa item, observed with a technical
facilitator and the actual sending node. A node's configured position identifies
the reporting node; it does not automatically prove the accident's exact position.

## Pilot tasks and evidence

Use an isolated pilot database/environment and simulated cases. Do not generate
fake emergency alerts on an operational public system. Record source revision,
URL, date, device/OS/browser versions, tested role, task outcome and any errors.

1. Sign in as a verified civilian; open the report form over the internet.
2. Observe the connection status before attempting submission. Turn connectivity
   off, then restore it and use Check again. Also test Wi-Fi with the reporting
   server unreachable; it must not claim the server is reachable.
3. Capture/upload a scene photo, lock GPS, enter the landmark and description,
   complete the live selfie check and send. Record the received report code.
4. Compare the saved GPS/landmark in the authorized review view with the submitted
   values. Distinguish stored-coordinate correctness from real-world GPS accuracy.
5. Simulate a dropped response/retry of the same submission. Confirm one database
   record, one report code and one new-report event. Confirm drafts are not
   presented as received and reconnecting never sends automatically. Open Draft
   Reports from the civilian collapsible sidebar: its badge must match the number
   of drafts for this account on this device. Close/reopen the app and confirm
   unfinished text remains. Switch accounts and confirm separation. Continue a
   draft, then confirm its removal/count reduction after a server receipt.
6. With two civilian test accounts, verify each sees only its own reports.
   A qualified evaluator also checks another report's detail, evidence, selfie,
   operational routes and responder broadcast authorization.
7. Repeat the complete flow on every phone/browser combination listed for the
   pilot, including permissions, camera/blink check, GPS and constrained bandwidth.

Give participants the normal task instructions and neutral scale. Let them
independently choose 1–5 or N/O after observing the result. Investigate low scores,
fix actual problems and document retests instead of directing participants to 5.

## Release and verification

Deploy the controller, route, Blade, JavaScript, CSS, service worker and matching
Vite assets together. No migration, AI model change or firmware upload is required.
Use the configured Laragon PHP, not the unrelated PHP on PATH. From
`web_system/`, run:

```powershell
& 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' artisan test --compact
node --test tests/js/report-connection.test.mjs tests/js/report-draft-delivery.test.mjs
npm.cmd run build
& 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' artisan optimize:clear
```

The export tests additionally need PHP ZIP enabled. To run those checks without
editing the machine's PHP configuration:

```powershell
& 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' -d extension=zip vendor/phpunit/phpunit/phpunit tests/Feature/IncidentReportExportTest.php --no-progress
```

The Render demo remains a separate release target. The current root `render.yaml`
uses AI disabled, local file storage and local SQLite; it is not production-safe
and data can be lost on redeploy. Older guidance mentions free Postgres, but that
is not the database configured by this root demo profile. Confirm the hosting
environment before assuming Postgres or persistent storage.
Compare its deployed source revision to the release commit; check `/system/version`,
`/login` and the authenticated `/reports/create` plus `/reports/connection` before
claiming parity. This repository's `/system/version` is a timestamp/size fingerprint,
not a Git commit ID; use hosting release metadata for the commit comparison.
Do not infer release parity from a health/version response alone.

### Observed validation outcomes

Follow-up draft navigation update: the full suite passed **339 tests / 3623
assertions** with Laragon PHP 8.3.30 and `-d extension=zip` passed directly to
PHPUnit. All **15** draft/recovery/navigation/connection JavaScript tests passed.
The earlier environment diagnostics below describe the preceding connection-only
update. Current navigation, persistence limits and online rollout notes are in
`DEPLOYMENT_RENDER_RAILWAY.md`.

- Baseline source: `main`, `65655c6d`; see the Git release commit for these changes.
- Laragon PHP suite: 337 passed, one export failure caused by missing `ZipArchive`.
  Rerunning the complete export test file with ZIP enabled passed all 8 tests
  (54 assertions). All 3 new connection feature tests passed in the full run;
  an independent connection run also passed (16 assertions). No remaining test
  assertion failure was observed after the environment correction. The entire
  suite was not rerun with ZIP enabled.
- JavaScript connection/draft/delivery tests: 9 passed. Vite production build
  passed, with a stale Browserslist-data warning. Laravel caches cleared.
- New PHP test file passes Pint. The controller's Pint check reports the same
  unrelated formatting rules on both HEAD and the changed controller; no broad
  controller reformat was included.
- Local MySQL and Laravel web processes started. `/`, `/login`, `/register` and
  `/system/version` returned 200. Final version fingerprint:
  `a2575d7e5e95b2af`. Guest JSON requests to `/reports/create` and
  `/reports/connection` returned the expected 401; authenticated rendering/probing
  was verified in isolated Laravel feature tests, not by impersonating a live user.
- Both Render `/system/version` and `/login` were unreachable from the checks.
  Deployed commit and local/public parity could not be established; no redeploy
  was performed. Release files/instructions are prepared above.
- Browser automation inventory reported its browser access host unavailable.
  No physical-phone, visual-browser, live camera/GPS/liveness, or complete online
  user evaluation is claimed. Execute the device matrix before rating browser
  compatibility as verified.
