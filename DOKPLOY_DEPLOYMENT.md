# Dokploy Docker deployment

This repository is prepared for Dokploy's **Docker Compose** deployment mode.
The Compose stack builds the Laravel PWA from `web_system/`, runs MariaDB, and
keeps the app's uploaded evidence and database in named Docker volumes. Nginx
serves Laravel and proxies Reverb WebSockets through the same HTTPS domain.

## Privacy and app terms release (2026-10-28)

Deploy the public `/privacy-policy`, `/terms-and-conditions`, and `/app-license`
routes, their Blade views, the registration controllers, and rebuilt Vite assets
together. The same documents are linked from public Home/Login/Register, Settings,
account verification, and the report form. Reading them never requires login.
Existing password-based logins are not blocked by a new agreement modal.

Apply migration `2026_10_28_000001_add_legal_acknowledgment_to_users_table` before
accepting new registrations. The existing container startup runs migrations before
serving requests. New web/API registrations require `terms_accepted=true`,
`privacy_acknowledged=true`, and the current `legal_version` from
`web_system/config/legal.php` (`2026-10-28` for this release). Direct API clients
must display the documents and collect these actions; do not silently insert true
in a client. The PWA/Android TWA uses the updated website without an APK rebuild.

Google sign-in first shows the document acknowledgment page, then a CSRF-protected
POST starts OAuth. New Google accounts require a current acknowledgment recorded
in the same session within 30 minutes. Existing accounts are not retroactively
assigned acceptance merely by migration or ordinary sign-in. The database stores
the acknowledged document version and timestamps, not blanket data-use consent.

Set `LEGAL_OPERATOR_NAME`, `LEGAL_CONTACT_EMAIL`, and optionally
`LEGAL_CONTACT_ADDRESS` in Dokploy's Compose environment using the actual operator
and monitored contact. Compose passes these to Laravel. The default operator label
is **AksyRes project administrators**; no contact email/address is invented. If an
email is absent, the published page explicitly says a contact is not yet provided.
The operator still needs to confirm its identity/contact, processing basis for
sensitive information, retention schedule, and any separately required consent;
publishing pages/recording acknowledgment does not certify legal compliance.

After redeploying the same commit, verify `/login` and all three public documents
return HTTP 200 over HTTPS, inspect their footer/form links, and test rejected and
accepted registrations with designated test accounts. Verify Google acknowledgment
and the existing-account login path separately. Confirm the deployed commit/build;
`/system/version` fingerprints include modification times and are not Git SHAs.
Do not claim online/local parity from a health response alone.

The hosted `/privacy-policy` returned HTTP 404 before this release was uploaded;
the updated routes were first verified on localhost. The existing main-branch
pipeline deploys pushed commits automatically; wait for its rebuild and check the
public routes/flows before calling a release live. A browser
connection was unavailable for local visual QA; HTTP and automated checks do not
replace a final mobile/browser review. No LoRa firmware change or hardware reflash
is included in this web release.

The Nginx Reverb proxies must match `/app/` and `/apps/` with a trailing slash.
A broad `/app` prefix also intercepts Laravel's `/app-license`, causing an
online-only 404 even when the page works under `artisan serve`. Deploy the path
boundary fix with this release; verify all three legal documents, plus Reverb's
normal `/app/{key}` WebSocket and `/apps/{id}/events` API paths.

Local validation uses the isolated SQLite test configuration in
`web_system/phpunit.xml`. On this workstation, the PATH-selected PHP has no MySQL
driver and the Laragon CLI does not enable Zip by default. Use the installed
Laragon runtime for migrations and enable its existing Zip extension for the full
test suite (including XLSX export), without changing global PHP configuration:

```powershell
cd C:\laragon\www\TomasOppusRescue-main\web_system
& 'C:\laragon\bin\php\php\php.exe' -d extension=zip vendor/phpunit/phpunit/phpunit --colors=never
npm.cmd run build
```

Local verification on 2026-10-28: 292 tests passed (3,053 assertions), Vite build
passed, Blade views compiled, and Home/Login/Register plus all three public legal
routes returned HTTP 200. These checks do not include an interactive Google
provider sign-in, a physical-phone visual review, or a successful online redeploy.

## Current civilian interface release (2026-10-27)

The Android APK source is in `android_app/`; it opens this same deployed PWA in a
Trusted Web Activity. Publish the signed `android-v1.0.3` GitHub release asset
before deploying the **Download App** links. This release applies the
AksyRes app name and official logo. `/download/android` redirects to
the fixed versioned APK URL in `web_system/config/android-app.php`. Verify that
the APK downloads, its checksum/signature match, and
`/.well-known/assetlinks.json` is publicly served as JSON with the APK's signing
certificate fingerprint. Keep this file when deploying: it establishes trust
between this domain and Android package `tech.bsit4bdevs.dualpath.rescue`.
No signing key or password belongs in Docker or Git. Build instructions and
device-testing limits are in `android_app/README.md`. iPhone uses the existing
Safari/PWA installation path.

Deploy the compact civilian Home, Report, Profile, Settings and Device Check
views together with the rebuilt Vite assets. The public Home/Login/Register
pages share the updated branding; Home places Help, About, Developer and Privacy
in a footer at the bottom. The installed PWA uses the new logo and service worker
cache version v11, while keeping its existing manifest identity and start URL.

Apply the included station-brand migration during the normal startup. Keep the
existing Dokploy service, credentials and named volumes. Report photo/location,
description and verification-selfie requirements, profile persistence and admin
access remain in place. Only Road Accident is available; other incident types
remain locked. SMS backup remains a saved preference without a configured SMS
delivery service.

Push the release commit to the connected `main` branch and deploy that commit in
the existing Compose service if automatic deployment is not enabled. Verify the
running revision in Dokploy, the changed `/system/version`, successful public
routes, the new logo and compiled assets, then check signed-in civilian and admin
pages. A successful Git push alone does not confirm a live deployment.

The shared `pwa-install.js` script must deploy with `pwa-helper.js`, the Blade
head partial and rebuilt assets. It captures Chrome's single-use install prompt
before page scripts load and starts service worker registration early. A pending
install request switches to the native prompt when Chrome makes it available
within the user's active gesture, or exposes a fresh Install button when another
tap is required. Browser-specific instructions remain available when installation
is unavailable, dismissed or blocked. Safari uses Share > Add to Home Screen;
browser permissions and install confirmation cannot be bypassed by website code.
When Chrome has not provided a native prompt, show browser menu instructions
immediately. Do not display a retry or preparation loop, and do not claim that
the user's device has installed the app based on simulated prompt tests.

## Live Monitoring feed

Live Monitoring shows only the three newest reports across Online and LoRa,
ordered by submission time and ID. Cards show source/node, a brief description,
location, severity/model score and Review. Detailed AI explanations and admin
review remain in the report modal; report histories and LoRa Mesh keep their
dedicated pages. Realtime cards use the same concise layout. Deploy the matching
Blade and Vite assets together; no database migration or firmware change is needed.

LoRa Mesh shows node health and telemetry only, including its five-second refresh
fragment. Report IDs, messages, acknowledgment state and report actions are in
LoRa Reports, which defaults to newest first.

The compact mesh monitor shows all ten nodes as rows on desktop and compact
cards on phones. Status, last seen, measured battery voltage, fresh RTT and last
RSSI stay visible; expand a node for route, gateway, peers, coordinates, packet,
SNR and sample times. Search and expanded rows survive the five-second refresh.
Status/telemetry explanations are in a single expandable guide. RTT expiry,
ingest and acknowledgment behavior are unchanged. Deploy
the matching Blade templates and rebuilt Vite assets together; no migration or
firmware update is required for this interface change.

Mesh connection status uses the same 30-second heartbeat window as the gateway's
NET active bitmask: **Reachable** means a heartbeat/SOS arrived within 30 seconds.
After 30 seconds without one the node is **Unreachable**, then **Unavailable**
after 120 seconds (also the default for nodes never heard). **Waiting** requires
a recent connected peer's heartbeat discovery list naming that node after its
last heartbeat; it is not a timed animation or proof of a completed connection.
Peer discoveries do not update the target's last-seen time or active bitmask.
Old peer lists retained by an SOS are excluded, and discoveries expire with the
observer's 30-second heartbeat window. The UI explains that silence cannot prove
the hardware is powered off. Existing HB/HELLO discovery firmware is compatible;
no new migration or firmware upload is required. The heartbeat API now returns
`REACHABLE` as its status string; NET packet fields and ACK behavior are unchanged.

## Civilian account presence

Civilian Accounts now shows authenticated app presence, independent of saved
login sessions. A visible civilian browser/PWA/Android TWA sends a heartbeat
every 20 seconds. A pagehide, hidden tab, network-offline event or logout ends
that page's connection where delivery is possible. Missing heartbeats expire
after 60 seconds; abrupt device/network loss can take up to 65 seconds to appear
offline with the admin's five-second polling interval. Backgrounded/suspended
apps are treated as inactive. This detects contact with this server, not whether
the phone has general internet access.

Connections are separated by authenticated session/token and random page ID;
one closing tab/device cannot mark another connected device offline. Sequence
numbers reject delayed beacons; logout revokes that context and blocking revokes
all presence for the account. Browser callbacks and the open account details
update without replacing forms. A failed admin refresh shows a stale-data warning.
The API also supports authenticated `POST /api/v1/account/presence` using
`client_id`, increasing `sequence`, and `state` (`online` or `offline`). Existing
API clients need to send this heartbeat to participate; holding a token alone
does not mark an account online.

Deploy migration `2026_10_23_000002_create_civilian_presences_table.php` with the
routes/controllers and rebuilt Vite assets. The existing web startup applies it
before serving requests. No Android APK rebuild or new environment values are
required. Presence stores hashed connection identifiers; obsolete rows for an
account are pruned after 24 hours on its next heartbeat.

## Admin node locations and Command map

In **Overview > Command map > Node locations**, open NODE_1 through NODE_10,
enter a location name, latitude and longitude, then choose **Save location**.
**View Command map** returns to the map. The same editor is linked from LoRa Mesh.
Use actual installation coordinates in decimal format; latitude comes first.
Both coordinates must be supplied together, or both cleared to remove a pin.
Only admin receiver accounts can read or update this editor and its map endpoint.

Deploy migration `2026_10_23_000001_create_lora_node_locations_table.php` together
with the controllers, registry resolver, Blade views and rebuilt assets. The web
startup runs `php artisan migrate --force`. Saved locations live in the existing
database volume and survive redeployments; do not seed invented coordinates.
The file `web_system/config/lora_nodes.php` supplies defaults; admin-saved values
override them for mesh details, installed node pins and **new** LoRa reports.
Existing reports retain their recorded coordinates, including duplicate retries.
Radio payload coordinates remain ignored. No firmware upload is required.

Square map pins show Node ID, location, connection status, coordinates and last
seen in their popup, including offline nodes. Nodes with no coordinate pair stay
unmapped. Node status and saved positions refresh every five seconds, pausing in
hidden tabs, while preserving the current map viewport. The Command map shows
installed node pins only; incident pins are excluded from initial data and live
report updates. Incident records and their report maps remain available. A
refresh failure retains previous pins and displays an update warning. Node pins
represent installation points, not measured radio coverage or GPS tracking.

The admin Online Report tab lists online submissions only. Its counts, search,
export preview/download and bulk delete use that same scope; LoRa reports stay
in their dedicated history. Live Monitoring still combines both sources.

## Overview and report audit history

Overview keeps response summaries, status/severity/source counts, the review queue,
map and readiness panel. Latest-report and civilian-account lists stay in their
dedicated tabs. The review queue links to the correct Online Report or LoRa modal.

Deploy with migration `2026_10_17_000001_create_incident_report_audits_table`.
The normal container startup applies it and imports surviving admin entries from
report coordination logs. It does not recreate earlier deleted reports or invent
missing historical actors, times or severity values.

`incident_report_audits` retains admin response/status changes, description
severity reviews, AI analysis requests and report deletions. Source, node, admin,
recorded time, notes and available before/after values are saved separately from
the report. Entries remain after report deletion, are read-only in the application,
and are searchable/filterable by source, action and report/admin/node. Pagination
queries all recorded actions, not a sample of the latest reports. Display times
use Asia/Manila. Reading a report and gateway packets do not fabricate admin actions.

## Before deploying

1. Push this repository to the Git provider connected to Dokploy.
2. In Dokploy, create a project and a **Docker Compose** service. Set the Compose
   file to `compose.dokploy.yaml` at the repository root. Do not use Docker
   Stack/Swarm mode because this file builds the web image from source.
3. Add the variables from [`dokploy.env.example`](dokploy.env.example) in the
   service's Environment tab. Compose explicitly passes the needed values into
   the containers. Replace every example value; keep secrets only in Dokploy.
4. Generate a Laravel key locally from `web_system/` with
   `php artisan key:generate --show` and store the output as `APP_KEY`. Set
   `APP_URL` to the exact HTTPS origin you will publish, and `REVERB_HOST` to
   that hostname without a scheme or path. For Google sign-in on
   `dualpath.bsit4bdevs.tech`, set `GOOGLE_CLIENT_ID` and
   `GOOGLE_CLIENT_SECRET` from the Google OAuth web client in Dokploy. The
   callback is built from `APP_URL`; add
   `https://dualpath.bsit4bdevs.tech/auth/google/callback` to that web client's
   authorized redirect URIs in Google Cloud. Local `web_system/.env` values
   are not available inside Dokploy.
5. Deploy. The web container waits for MariaDB, applies Laravel migrations, and
   then starts Nginx, PHP-FPM, and Reverb. First boot can take longer while the
   image is built and migrations run.
6. In the Compose service's **Domains** tab, attach the public hostname to
   service `web`, container port `10000`, and enable HTTPS/Let's Encrypt. Keep
   MariaDB private; it has no published port. Dokploy's domain routing should
   target the internal container port, not expose it directly.
7. After deployment, confirm `/system/version`, `/`, `/login`, and `/register`
   return successful responses. While signed out, confirm `/auth/google`
   redirects to `accounts.google.com` with the public callback URI; complete a
   sign-in with a Google test user. Check the `web` and `db` health states and
   verify a report upload survives a container redeploy.

## Saved Google sign-in setup (2026-10-15)

- Google sign-in code and Compose credential forwarding are saved in GitHub
  `main`, starting at commit `1b66650`.
- Keep `APP_URL=https://dualpath.bsit4bdevs.tech` and the actual
  `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` values in this service's Dokploy
  Environment settings. Enable **Create Environment File** and save; Compose
  needs the generated file to interpolate the saved values on each deploy.
- Keep `https://dualpath.bsit4bdevs.tech/auth/google/callback` in the Google
  web client's authorized redirect URIs. The app derives its callback from
  `APP_URL`, including the `https://` scheme.
- Verification: public `/login` returns 200; `/auth/google` redirects to
  `accounts.google.com` with that exact callback; Google serves its sign-in
  page without a client or redirect-URI error. These HTTP checks do not sign
  in to a user's Google account.
- Credentials stay outside Git. This Laragon workspace also has a Windows
  encrypted recovery copy at `tool/runtime/google_oauth_backup.clixml`,
  readable only under the same Windows account on the same machine. It is
  ignored by Git. Keep the Dokploy environment settings when redeploying;
  recreating the service or revoking a Google secret requires restoring or
  replacing those settings.

## Runtime configuration

- The shared Dockerfile installs GD with JPEG/FreeType support before Composer
  runs. Dependency installation and PHP-FPM use the same PHP 8.3 base and
  extensions, and the build checks the locked packages' platform requirements.
- The production container uses `APP_DEBUG=false`, secure HTTPS cookies, MySQL
  sessions, stderr logging, and database migrations at startup.
- MariaDB data persists in `mariadb-data`; uploaded files persist in
  `app-storage`. Configure Dokploy volume backups for both volumes and test a
  restore before using the service for real emergency operations.
- Laravel Reverb listens privately on `127.0.0.1:8080`; Nginx proxies its
  `/app` and `/apps` WebSocket endpoints. The browser uses the public host,
  `wss`, and port 443 from the runtime environment.
- Image inference remains disabled. The `text-ai` service runs the explicit
  experimental TF-IDF/Logistic Regression pilot selected in
  `ai_service/releases/triage-text-tfidf-20261016-v2`. It is private on port 8100;
  Laravel connects through `http://text-ai:8100`. The optional PyTorch/transformer
  path remains available. Every suggestion requires admin confirmation.
  This synthetic-trained model is not an independently validated triage release.
- Email defaults to Laravel's `log` mailer. Configure real SMTP variables in
  Dokploy before depending on password reset or account verification emails.
- The public OSRM routing service is the default route provider. For sustained
  production traffic, review its usage terms and configure a suitable provider.
- Run a single `web` replica with this local-filesystem upload setup. If you
  scale horizontally, move uploads to shared S3-compatible storage and add a
  shared cache/queue backend first.

## Operations

- Unified report category and filters (2026-10-16): deploy Laravel and rebuilt
  assets together. Reports use one current category: the admin assessment after
  review, or the AI's highest-scoring category while pending. Filters, severity
  sorting, cards, realtime projections and exports use that same category;
  original AI scores remain review history. Critical includes legacy Fatal rows
  and links. Needs review and Unassessed are explicit options. Search requires
  every word, treats `%`, `_` and `!` literally, and preserves civilian ownership.
  Invalid filters are rejected instead of becoming Minor/Online. SQL filtering
  happens before pagination; exports reuse it across all pages. No migration or
  model change. Dispatch still requires admin confirmation. Verification covers
  SQLite feature tests and MySQL temporary fixtures without changing real reports.

- Highest model category and in-place analysis (2026-10-16): deploy the web app
  and rebuilt Vite assets together. All report cards and admin review views show
  the actual highest-scoring model category/percentage, including below-cutoff
  results, separately from final admin severity and phrase-rule suggestions.
  Analyze again uses an authenticated JSON POST and replaces the visible result,
  scores and review form inside the open modal; errors preserve the prior result
  and pending review notes. Normal form submissions also reopen the report on
  the reports page. No model, cutoff, severity mapping or migration changes.
  Verify the changed `/system/version`, `/login` and ready AI status after deploy.

- Counted-pack retraining v2 (2026-10-16): Compose and local active config select
  `dualpath-tfidf-20261016-v2`. This re-fit uses the unchanged canonical corpus
  and frozen train/validation/test split; the 8,000-word pack is already a subset
  and is not duplicated. Weights and diagnostic results match v1. Deploy the
  updated Compose profile and numeric release, then check `/system/ai-status`
  for `ready`, model version v2, phrase rules enabled and admin confirmation
  required; verify `/login` returns 200. No new environment values or migration
  are required. No independent real-report accuracy or improvement is claimed.
  The previous release directory is retained for rollback. See
  `ai_service/artifacts/reports/text_retrain_20261016_v2/EVALUATION.md`.

- Multilingual phrase suggestions and retained scores (2026-10-16): deploy the
  AI cue module/inference contract and Laravel client, source projections,
  review snapshot and shared views together. Compose enables
  `AI_TEXT_RULES_ENABLED=true` on the private AI service. This recognizes explicit
  condition phrases in English/Tagalog/Bisaya; new reports are analyzed on intake.
  Rules have no fabricated confidence, and the actual classifier's four scores
  remain visible with the original suggestion after admin confirmation/correction.
  No migration is required. Verify `/system/ai-status` includes
  `text_rules_enabled=true` and `text_rules_version=text-cues-20261016-v1` along
  with ready model status. Existing reports need reanalysis to obtain newly
  retained scores; never rewrite a human-confirmed report with automation.
  Disable only the new phrase layer by setting `AI_TEXT_RULES_ENABLED=false` and
  redeploying. See `ai_service/TEXT_CUE_POLICY.md` for constraints and the separate
  already-exposed synthetic diagnostic; no real-report accuracy is certified.

- Text AI pilot (2026-10-16): deploy the root Compose file, AI Dockerfile,
  `requirements-advisory.txt`, numeric release artifact, inference loader and
  Laravel changes together. The AI image includes only the small scikit-learn
  runtime for this model, with a 512 MB container memory limit. No model download
  or training runs during deployment. Health checks load and checksum the model.
  Do not replace `APP_KEY`, Google settings, database credentials or named volumes.
  Compose defaults to `AI_TEXT_MODE=MODEL_ADVISORY` and `AI_TEXT_ENABLED=true`;
  remove any old Dokploy overrides keeping them manual/false, or explicitly set
  those two values and save. Both services explicitly permit this experimental
  pilot; metadata does not pretend it has human release approval.
  Verify `/system/ai-status` returns ready, the expected model/version,
  experimental status, and `admin_confirmation_required=true`; check `/login`
  and the actual admin report UI. Existing unreviewed reports can use **Analyze
  description with AI**; new descriptions are analyzed on submission. Human
  severity reviews cannot be overwritten. Use **Text AI Lab** for a nonpersisted
  trial. Uncertain cases remain Needs review. No fake live reports are needed.
  For rollback, set `AI_TEXT_MODE=MANUAL_ONLY` and `AI_TEXT_ENABLED=false` in
  Dokploy, save and redeploy. The separate free Render demo remains disabled.
  The [evaluation](ai_service/artifacts/reports/text_tfidf_20261016/EVALUATION.md)
  records 92.7% raw synthetic test accuracy and 60.5% suggestion coverage; these
  are not independently validated real-report results.

- Severity confirmation (2026-10-16): deploy `IncidentSeverityReviewController`,
  the protected `reports.severity-review` route, shared review form, model review
  accessors, updated report views/export controller, and rebuilt assets together.
  Include the dashboard ordering, API projections, and incident broadcast changes;
  unconfirmed model suggestions must remain advisory until admin confirmation.
  No schema migration is required; review provenance is stored in the existing
  coordination log. Verify admin confirmation/correction, Needs review, an
  unchanged coordination status and civilian response message, and appended CSV
  review fields on the public app. This UI feature alone does not activate
  inference; the separately documented text pilot supplies suggestions.

- LoRa report history (2026-10-15): deploy the new `/lora-reports` controller,
  protected route, views, navigation, and rebuilt CSS/JS with the monitoring
  query update. Live Monitoring now selects only the latest three records;
  older reports remain in the database and are paginated in the history page.
  Verify node/status filters, Previous/Next and page selection, modal review,
  acknowledgment, and validation feedback after admin login. No schema or ESP32
  firmware change is needed. Keep the same source release on localhost and the
  hosted domain.
- LoRa report visibility and acknowledgment (2026-10-15): deploy the updated
  Laravel controllers, node directory, Blade views, and rebuilt assets together.
  No schema migration or ESP32 reupload is required for this change. Node cards
  link actual reports and allow an admin to acknowledge them; pending SOS retries
  requeue a lost review ACK. Verify the protected mesh/report routes after admin
  login, then manually acknowledge an existing report and check the originating
  node dashboard. Do not use a successful gateway broadcast as proof of receipt
  by the field node.
- Battery/link telemetry adds nullable columns to `lora_node_states`; the web
  startup migration applies them on redeploy. Deploy the backend before
  uploading the matching NODE_1 and NODE_2–NODE_10 firmware. Verify the authenticated
  `/api/v1/lora/probe` route echoes a packet without creating an incident, and
  check `/monitoring/lora-mesh` for real voltage/round-trip samples. Battery
  sensing stays disabled until divider wiring is installed on the hardware.
- Admin access starts at `/admin`, which sends guests to `/admin/login` and
  signed-in accounts to the protected `/admin/dashboard`. Only admin accounts
  can access that dashboard. After pulling an update that changes routes or
  middleware, redeploy the `web` service from the latest `main` commit; verify
  `/admin` redirects to `/admin/login` while signed out and that admin login
  opens `/admin/dashboard`. A GitHub push alone does not confirm deployment.
- Keep a copy of the `APP_KEY`; changing it invalidates encrypted cookies and
  other values encrypted by Laravel.
- Back up both named volumes. Database migrations run automatically on startup;
  take a database backup before deploying schema changes.
- Set unique strong database passwords, Reverb credentials, and
  `LORA_INGEST_TOKEN`. Do not use the examples in production.
- Use Dokploy's container logs to inspect Laravel output. Nginx and PHP startup
  failures also appear in the `web` service logs.

## Civilian directory modal (2026-10-23)

Deploy the Civilian Accounts controller, protected `/civilian-accounts/all`
route, Blade view, and rebuilt Vite assets together. No schema migration is
needed. **View all** opens an independent list of all civilian accounts, with
15 accounts per page and Previous/Next controls. Presence polling includes the
currently loaded modal page. Verify pagination with more than 15 accounts,
Online/Offline updates, account detail links, and mobile/dark layouts after
admin login. The main directory filters and three-account preview remain
available. Rebuild and verify the hosted release after pushing to `main`.

## Civilian report history and printing (2026-10-23)

Deploy the new CivilianReportController, protected account report/print routes,
Blade views, and Vite configuration/assets together. The print page uses its
own small JS/CSS entry; run `npm run build` during deployment. No schema or APK
update is needed. The Reports count opens a paginated modal from the main
directory, View all, or account details. Print all includes every report for
that civilian across pages; individual Print links select one owned report.
Printing opens a separate page and the browser print dialog, with Save as PDF
available through the browser. Verify these actions after admin login; signed
out users must not be able to view report lists or printable records. Existing
incident history is read without updates or deletion.

Print alignment update (2026-10-24): rebuild the dedicated print assets. Modern
Chrome uses CSS page margin boxes for a left-aligned organization name,
right-aligned report heading, and page numbering on every sheet, replacing the
browser's automatic title/date/URL. Verify single and multiple-page output with
the browser's Headers and footers option both enabled and disabled. Older
browsers without page margin box support retain their native print headers.

## Online Report history (2026-10-24)

Online Report history update (2026-10-24): deploy the report controllers, routes,
Blade views and rebuilt assets together. The admin main feed always shows the
latest three matching online reports; old `page` links no longer change it.
View all reports opens a compact list with 15 rows per page and search. Report
details, maps and media use nested dialogs; each report has a protected Print
page. Verify older and unlinked-account reports, AI/admin review, response
validation, printing, and mobile/dark layouts after admin login. LoRa reports
remain in their own tab; civilian history retains its existing pagination.
No schema migration or APK rebuild is required.

## Report filters, map preview and print privacy (2026-10-24)

Rebuild and deploy the updated controllers, Blade views, support helper and Vite
assets together. The report list supports search, severity and inclusive date
ranges in Asia/Manila. Pagination retains the applied filters; Reset clears them.
Report details include a pinned map preview and the full View map dialog.

The Online Report print page opens a preview with optional exact location/GPS,
contact number and scene photo. These options are off by default. Update preview
reloads the selected copy; Print selected details opens the browser print dialog
after images load. A failed photo load requires retrying or clearing that option.
Verification selfies are never included. Report-facing summaries use controlled
copy rather than raw service diagnostics or internal model identifiers. Stored AI
results and the restricted severity audit remain available for internal review.
User-entered descriptions and response notes remain unchanged, so review them
before distributing a printed copy. Keep access to reports and media restricted
to their existing authorized roles. No schema migration or APK rebuild is needed.

## Shared account and online report views (2026-10-24)

Deploy the CivilianReportController, CivilianAccountController, account-scoped
details route, shared report/print Blade views and rebuilt assets together.
Civilian Accounts report rows now open the same review dialog as Online Report:
map, attachments, AI assessment and admin actions. The new detail endpoint checks
the selected account owns the report. Report lists remain 15 per page.

Single-report printing and Print all use the same preview/options/record template
as Online Report. Exact GPS, contact and scene photos remain off by default;
verification selfies and internal model diagnostics remain excluded. The selected
report ID is preserved when updating or printing a preview. Print all covers all
pages of the selected account. There is no schema migration or APK rebuild.

Account, directory, report list and review dialogs share the existing dialog
stack so Escape closes the top dialog and preserves focus and unsaved drafts.
After a severity/response form redirect, the account page reopens that report,
including validation errors and entered notes. Verify these paths after admin
login; protected endpoints must reject guest/civilian/non-admin responder access.

## Overview audit history modal (2026-10-24)

Deploy AdminDashboardController, the protected `/admin/report-audit` route,
shared audit-row/list views and rebuilt Vite assets together. Overview shows the
six latest actions without page controls. View all loads 10 actions per page
with search, source, action and inclusive Asia/Manila date filters; pagination
uses applied filters. Reset clears every modal filter. Existing `audit_*` links
open the filtered modal while Overview retains the six latest actions.

The original action-details dialog and Online/LoRa report links are reused from
one row template, including retained deleted-report history. The list and detail
dialogs share the application dialog stack for Escape, focus and scroll handling.
Verify admin authorization, filters, empty/error/retry states, older/deleted
actions and both report links after login. No audit records are changed by
viewing these screens. No schema migration or APK rebuild is required.

## Admin sidebar styling (2026-10-24)

Rebuild operations assets to include `admin-sidebar.css`. The admin sidebar uses
the civilian navigation typography, rounded links, soft active state and pill
controls. Labels, badge values, routes and actions are unchanged. Admin desktop
navigation uses 248px expanded / 88px collapsed; its existing 1024px drawer
breakpoint remains. Styling is scoped to admin sidebars. Verify light/dark,
collapse/expand, mobile drawer closing and access to Log Out. No schema migration
or APK rebuild is required.

## Dismissible civilian install offer (2026-10-24)

Deploy the civilian shell, `pwa.js`, and rebuilt operations assets together.
Later is now in the install card header and remains visible while scrolling.
The card sits above the measured bottom navigation with a viewport height limit.
Verification and report forms never open it automatically, including when Chrome
offers installation after the camera has started. A small App button at the side
opens the choices when needed. Later retains the existing dismissal preference;
the side button remains usable afterward. A session fallback also respects Later
when browser storage is restricted.

Android users can choose the existing fixed APK download route or the browser
install flow; iPhone users retain Safari guidance.
Installed/standalone sessions hide the offer. No install dialog or APK download is
started by the side indicator alone. Verify camera capture, selfie/form retention,
Later on short screens, mobile navigation clearance, cross-page dismissal and
manual reopening. The native browser/OS still completes actual installation.
There is no schema migration or APK rebuild for this web interface change.

## Required civilian GPS (2026-10-24)

- Civilian web and API intake now require both latitude and longitude within valid ranges. The retired `gps_fallback_used` flag cannot bypass this requirement. Validation happens before AI calls, file storage, database writes, or broadcasts.
- The PWA requires a current device location from **Lock GPS** and checks it again after selfie verification immediately before final submission (`maximumAge: 0`, 15 second timeout). Denied permission, unavailable location, timeout, and stale readings clear the GPS lock and block sending. A landmark is supplementary only.
- Restored civilian drafts and browser back/forward navigation require a new GPS lock; old coordinates are not reused. Draft photos and descriptions stay available for retry.
- Web browsers expose location readings and permission state, not independent proof of the phone's GPS switch. The server validates submitted coordinates; it cannot attest device settings or prevent a modified client from forging coordinates.
- Deploy the Laravel validation, Blade form, and rebuilt Vite JavaScript together through the existing Dokploy pipeline. No migration or environment change is needed. Historical reports without coordinates remain unchanged; the LoRa gateway intake retains its registered-node location behavior.
- Verify locally with the location validation feature tests and mobile browser checks: denied/unavailable/timeout, no GPS with a typed landmark, stale draft coordinates, permission revoked after lock, location lost before final send, successful fresh fix, and retry with retained photos/selfie. Repeat the device permission checks on the deployed PWA after refreshing to the latest bundle.

## Owner admin access recovery (2026-10-24)

The owner's existing online admin account was recovered through an explicitly authorized, one-time CLI deployment task. The server generated the replacement password and returned only an encrypted receipt for the owner's locally held private key. The private key and plaintext credentials were never committed. Real online admin login and protected Dashboard, Reports, and Profile access were verified, then the temporary task, deployment hook, and ciphertext export configuration were removed. The normal admin authentication flow remains in place; Git pushes do not provision or reset admin accounts.

## Official ResQMesh logo (2026-10-24)

The owner's supplied ResQMesh emblem is the official system logo. The original PNG is preserved in `web_system/resources/branding/resqmesh-emblem.png`; `php tool/generate_brand_assets.php` reproduces the resized assets without redesigning or cropping the artwork. Public Home/Login/Register, the admin and civilian sidebars, offline screen, favicon, and PWA/Apple icons share this source. Maskable icons have separate padded assets; the manifest identity and existing application/account names remain unchanged. Service worker cache v9 and versioned image URLs refresh the web assets.

Android 1.0.1 (version code 2) includes the same launcher, adaptive, store, and splash artwork, signed with the existing release certificate. Publish the APK and checksum under `android-v1.0.1` before the updated Download App link is used. Existing APK installations need the new APK for their launcher/splash images; their accounts and server-side reports remain available. Check public logo/icon bytes, the manifest, a real admin route, and the APK download/signature after deployment.

## ResQMesh application name (2026-10-24)

ResQMesh is now the visible application name throughout the admin/civilian UI, public pages, browser titles, install guidance, PWA manifest, offline screen, and shared report print headers/margins. Dokploy fixes `APP_NAME` and the mail sender display name to ResQMesh so stale external environment labels cannot restore the previous name. Local and example configurations use the same display name. Migration `2026_10_24_160000_rename_command_center_brand_to_resqmesh` renames the former exact default station label to ResQMesh Command Center; it does not rewrite incident geography or historical report text.

Android 1.0.2 (version code 3) changes the launcher/application label to ResQMesh and is distributed as `resqmesh-1.0.2.apk`. The package, signing identity, verified domain, and PWA identity remain compatible with existing installations. The Compose session cookie is explicitly pinned to the existing deployed cookie name so changing `APP_NAME` does not invalidate active web logins. Existing APK installations need the new APK for the new label; refresh the PWA for its manifest/name update. Service worker v10 refreshes the renamed web content. Publish `android-v1.0.2` and verify the updated Download App route before describing the release as live.

## Compact civilian account verification (2026-10-24)

The verification page now shows a single heading, a bounded camera preview, and the current action: Open Front Camera, Capture Face, then Verify Account. Retake and the native phone camera fallback remain available. Rules and account details are in expandable help. Page-specific styles remove the repeated workspace header and reserve space for mobile navigation. Camera readiness, retry, file-type/size checks, and submission state are handled in the existing verification module. The server upload route, private selfie storage, verified-account gate, and admin image access are unchanged.

Deploy the normal web build and clear Laravel caches. Refresh the PWA to load the new assets; no APK rebuild or database migration is needed. Validate a mobile preview/capture/retake/verify flow and admin verification-image access. Browser automation uses synthetic camera frames and unsaved fixture accounts; real phone camera permissions still need a device check.

## Required civilian location description (2026-10-25)

Civilian emergency reports require both a current GPS lock and a barangay, road, or nearby landmark. The location description is marked required, is included in the Send Report readiness gate, and is no longer filled with raw coordinates by civilian GPS capture. The web and API validators require the description and return a rescue-specific error when it is missing; missing/blank text is rejected even with valid coordinates. Admin manual entry keeps its existing GPS helper. Deploy the normal web build, clear Laravel caches, and refresh the PWA. Verify missing GPS, missing description with valid GPS, and the complete photo/location/description/selfie flow.

## Official AksyRes name and artwork (2026-10-27)

The owner's uploaded AksyRes image is the current official logo for the public website, admin/civilian portals, printed reports, PWA, and Android app. Its unchanged original is stored in `web_system/resources/branding/aksyres-emblem.png`. Run `php tool/generate_brand_assets.php` to generate the resized web/favicon/PWA/Android assets. The whole image is preserved on white, with safe padding for maskable icons. The previous public logo URL serves the same new artwork for cached pages during the update.

Android 1.0.3 (version code 4) displays AksyRes and includes the new launcher/splash artwork. Its APK and checksum are distributed under `android-v1.0.3` as `aksyres-1.0.3.apk`. Existing APK installations need that update to change their native name/icon; the PWA uses service worker v11 and versioned icons to refresh the web assets. Keep the package ID, signing key, manifest identity, and existing session cookie stable. Dokploy pins `APP_NAME` and `MAIL_FROM_NAME` to AksyRes. Migration `2026_10_27_000001_rename_command_center_brand_to_aksyres` updates only the previous exact default station label. Deploy the web assets/migration and verify the public pages, manifest/icons, download, and APK signature/checksum.

## Homepage connection flow and civilian navigation (2026-10-27)

The public homepage includes separate online and node-dashboard/LoRa flows. Online reports use account verification, scene photo, GPS/landmark, description and selfie, then server storage, admin severity review, and account status updates. LoRa reporting starts on the node's local Wi-Fi dashboard; NODE_1 forwards to Laravel when its server route is available, and the response returns to the originating node. Pending node reports remain queued when that route is unavailable. This diagram does not imply automatic PWA-to-LoRa switching.

Civilian and admin desktop collapse preferences now use separate storage keys. Civilian desktop navigation above 1380 CSS pixels keeps the account header out of the collapsed rail and resizes the logo to fit. At 1380 pixels and below it uses a full drawer, including installed tablet mode, with full navigation labels, a menu button, and bottom navigation where applicable. Closed drawers are hidden from keyboard focus. Deploy the Blade/CSS/JS build together and clear Laravel caches. Verify desktop expand/collapse, breakpoint/orientation changes, installed tablet mode, and the camera/capture/retake/verify flow. The existing Android APK loads these web changes; no new binary or migration is needed.

## Required live face and blink check (2026-10-27)

Civilian account verification and each new web/API report require a signed, server-verified live camera sequence. The browser requests a short-lived challenge, prompts one or two slow blinks, and captures six to eight seconds from the active camera. The private CPU worker independently checks one visible face, open/closed/open eye transitions, continuity, and the final open-eyed selfie. A detector failure, unavailable service, missing face, multiple faces, or missing blink cannot issue a proof. Gallery/native still-image selection does not satisfy this check.

The proof is bound to the user, account/report purpose, and SHA-256 of the exact saved selfie. It expires after two minutes and is consumed atomically once. Validation and the proof gate run on both web and bearer-token report intake. The `face_liveness_challenges` migration stores hashes and timestamps; expired metadata is cleaned up. Raw frame sequences are not saved as application records, flashed into sessions, or used for training. Only the final verified selfie uses the existing private storage/admin access path. Draft restoration and camera cancellation require a new live check.

The report camera overlay is attached directly to the document body so panel transforms cannot shift it off-screen after scrolling. Its preview shrinks on short/landscape screens while the check/help/cancel controls remain accessible.

Dokploy installs `requirements-liveness.txt` in the existing private FastAPI service with the pinned Apache-2.0 Face Landmarker asset and a build-time CPU/checksum check. `FACE_LIVENESS_KEY` uses the existing private `APP_KEY`; Laravel signs the exact worker request body. Keep this service private. The worker memory budget is 768 MB with one concurrent face analysis. The severity model, active configs, and severity mappings are unchanged. Apply migrations and deploy web/worker together. The existing APK loads the web update; no native rebuild is needed. The free Render manual-only demo needs a separately configured face-enabled worker before civilian reporting can pass this gate.

The Linux wheel links EGL/GLES libraries even with the CPU delegate; the image explicitly installs `libegl1` and `libgles2` alongside OpenCV's system libraries. Do not remove these based on the absence of a GPU. Docker loads the face task during the build, and Compose waits for worker readiness before starting web.

For Laragon, create `ai_service/.venv-liveness` using the existing Python 3.13 and install `ai_service/requirements-liveness.txt`. `tool/start_stitch_services.ps1` starts `serve_liveness.py` on port 8102 and passes the local app key without printing it. Set `FACE_LIVENESS_SERVICE_URL=http://127.0.0.1:8102` locally; Dokploy uses `http://text-ai:8100`. Status/stop helpers include this worker. Check `/ready/liveness`, real camera permission/capture, and retry behavior on the user's phone.

This is a basic face/blink gesture check, not identity matching or certified presentation-attack detection. A replayed video, virtual camera, or generated face can defeat it. Tests cover gate/proof security, absent/static/multiple faces, blink sequences, browser cancellation and failure paths. Licensed research/example clips are local QA fixtures only and are not shipped or used to train/promote a model. Physical-phone lighting/camera tests remain necessary.

## Live green face guide and automatic capture (2026-10-27)

Account verification and the report camera now load a small browser face detector
when the camera opens. The gray/amber guide turns green only after one sufficiently
large, centered, upright face stays steady. The capture button is enabled at that
point; after about two seconds of stable positioning the blink capture starts
automatically. Each camera opening makes at most one automatic attempt. A failed
server check requires an explicit retry, and Cancel/Retake stops detection and
clears the pending verification. Losing the face removes green readiness.

This is positioning feedback, not face identity matching or liveness approval.
The existing signed server blink proof remains mandatory for both account and
report submission. Preview detection runs locally, using the pinned Apache-2.0
BlazeFace asset in `web_system/resources/face-guide/` and reviewed browser SDK
`@mediapipe/tasks-vision@0.10.32`. Vite serves the lazy JS/model/WASM assets from
the same origin; no preview images go to a CDN or face-preview endpoint. The
preview adopts the camera's actual aspect ratio within the available space,
removing letterbox bars without zooming away the face. The oval follows the
detected face; visible eyes, nose and mouth are checked instead of requiring an
exact fixed-oval center. Account verification saves automatically after the
server approves the blink sequence; duplicate submissions remain blocked.
Unsupported or failed detector loading leaves capture disabled and exposes
camera retry guidance.

The web container compresses the hashed `/build/assets/` JS/CSS/WASM/model files
and caches them immutably. This reduces the face runtime's first download and
avoids repeated downloads. The manifest, application pages and verification
requests do not receive that immutable cache policy.

Deploy the normal web build and clear Laravel caches. No migration, severity
model change, worker rebuild, or APK rebuild is needed. Verify the deployed
manifest, detector JS, model and both WASM variants as well as a real app route.
Run `npm run test:face-guide`, `npm run build`, and `php artisan test`. Browser QA
uses the actual detector with licensed local research frames for positioning and
mocks the independent server verdict; it does not certify physical-camera
liveness or replay resistance. Verify blink capture on the user's phone too.

## Local Compose check

After Docker Desktop is installed, create an untracked `.env` from
`dokploy.env.example`, replace its example values, then run from the repository
root:

```sh
docker compose --env-file .env -f compose.dokploy.yaml config
docker compose --env-file .env -f compose.dokploy.yaml up --build
```

Do not use the example values on a public deployment.
