# Dokploy Docker deployment

## Category-specific multi-incident advisory — 2026-11-12

The owner's explicit activation request replaces the ordinary road-only
selector with `/app/releases/category-experts-20261112-v1` in
`compose.dokploy.yaml`. Each selected category routes to one of six separately
fitted classifiers; each uses only its own category's training examples. The
PWA/API preserves category selection instead of forcing Road Accident. Saved
drafts preserve that selection too. LoRa board firmware remains road-only.

Local ordinary and Lab selectors use the same release. The corpus has 11,906
rows, including 192 new authored descriptions plus correlated context variants.
Known synthetic test accuracy is 74.45%, Critical recall 74.76%, with weak
category slices documented in the
[release report](ai_service/releases/category-experts-20261112-v1/README.md).
This is an experimental advisory explicitly requested by the owner; no
independent validation or clinical approval has been granted. Admin assessment
is still mandatory for operational severity and no automatic dispatch was added.

Rebuild the existing web and `text-ai` services from the same source revision.
The existing baseline-dependency build flag remains required; Docker now checks
the router manifest and every child artifact. Keep `AI_TEXT_MODE=MODEL_ADVISORY`,
`AI_TEXT_ENABLED=true`, and experimental opt-in. The private AI service must
use the new model directory above. No database migration or account reset is
required. Training data and private feedback are excluded from the image.

After the usual push-to-main deployment, verify public `/`, `/login`,
`/register`, and `/system/ai-status` at `https://dualpath.bsit4bdevs.tech`.
The status must show `model_version=category-experts-20261112-v1`,
`category_routing=isolated_category_models`, six categories and
`admin_confirmation_required=true`. Confirm the host's deployed commit where
available; the existing `/system/version` fingerprint is not a Git SHA. Do not
claim an online rebuild from a successful push alone.

Rollback: restore `AI_TEXT_MODEL_DIR` to the retained road v2 directory and
recreate `text-ai`, or set web `AI_TEXT_MODE=MANUAL_ONLY` and
`AI_TEXT_ENABLED=false` and refresh its configuration. Other categories then
require human assessment. Historical road-only/Lab notes below remain for audit.

This repository is prepared for Dokploy's **Docker Compose** deployment mode.
The Compose stack builds the Laravel PWA from `web_system/`, runs MariaDB, and
keeps the app's uploaded evidence and database in named Docker volumes. Nginx
serves Laravel and proxies Reverb WebSockets through the same HTTPS domain.

## Admin deletion of LoRa reports (2026-11-12)

The LoRa report review includes an administrator-only **Delete report** action
with confirmation, using the existing authenticated, CSRF-protected delete route.
It removes the selected report and attachments while retaining admin audit
history. Deletion is separate from Cancel report and authenticity verification;
it does not generate an admin radio response or mark a report as false.

Run the normal web deployment and migrations, including
`2026_11_12_000001_create_lora_report_receipts_table`, before serving this code.
The new table stores only a hashed radio identity and optional deletion time.
Keep it with database backups: it prevents a later retry of a deleted LoRa
message from recreating the report, alarm, or AI job. New message IDs still work.
It does not recover identities of reports deleted before this update.

The gateway receives a normal HTTP transport receipt for a deleted duplicate;
this is not a reviewed radio acknowledgment. Previously queued radio traffic
already downloaded to hardware cannot be recalled by deleting its server row.
No Arduino firmware upload, AI model change, or APK rebuild is required.

## Targeted LoRa node restart (2026-11-10)

LoRa Mesh now offers Restart inside each expanded node for administrators.
The server queues a five-minute command, preserves one active request per node,
and applies a two-minute request cooldown. A fresh authenticated heartbeat from
both gateway and target must advertise `signed-restart-v1`; old firmware leaves
the button disabled with upload guidance. The UI distinguishes queued, sent,
accepted/restarting, signed post-boot confirmation, expiry, and failure.

Gateway commands use the existing private ingest token. Radio control commits
and receipts use target-specific HMAC, a fresh challenge, expected boot IDs,
and durable replay protection. Restarting retains stored pending reports and
their message IDs. It briefly interrupts the target's local portal/relay route;
NODE_1 restart interrupts the server bridge. A powered-off or fully unresponsive
device cannot receive a remote command. See [the operator and firmware guide](Arduino/REMOTE_RESTART.md).

Deploy web with the normal Vite build and migrations, including
`2026_11_10_120000_create_lora_node_restart_commands`, then clear Laravel caches.
Keep the existing gateway token, private mesh keys, database, and uploaded files.
The new command table and `lora_node_states.restart_protocol` column are required.
The automatic web deployment cannot flash boards: upload GatewayNode1 and all
matching field/relay sketches separately while preserving their Preferences/NVS.
No AI worker, severity model, or APK rebuild is needed for this update.

## Civilian report face check and delivery results (2026-11-10)

Civilian report submission now captures a single steady, visible face from the
camera and checks face presence on the private worker. The report check requires
no blink. Initial account verification still uses the existing face/blink
challenge. A report face check does not establish identity, liveness, or incident
truth; the account gate and administrative assessment remain separate.

Report challenges bind the signed worker result to the authenticated user,
submission UUID, and exact selfie hash. A completed report check is valid for
24 hours and can be reused only for that same pending submission. A failed send
keeps the check and selfie in the active form while the civilian edits their
description, scene photo, or location. Reopening a saved draft or closing/reloading
the page requires a new check; private face images and proof tokens are not saved
in browser draft storage. Each actual send still refreshes required GPS.

The Successful dialog appears only after an authenticated server receipt; OK
opens the existing report confirmation. Failed offers Retry, which returns to
the editable form. An interrupted connection means receipt is unconfirmed. The
same submission ID is reconciled before retry, so a lost response cannot create
a second report. Report proof consumption and incident persistence are atomic;
failed database/media writes leave the proof usable for another attempt.

Deploy web and the private face worker together, run the normal migrations
(including `2026_11_10_000001_bind_report_face_checks_to_submissions`), rebuild
Vite assets, clear Laravel caches, and restart the face worker. Its readiness
must advertise `report_face_check: face_presence_v1` and
`account_check: face_blink_challenge_v1`. Keep the existing private signing key,
database, model, and uploaded files. No severity-model change, Arduino upload,
or native APK rebuild is required. Previous per-report blink instructions below
describe the older workflow and are superseded by this section for report checks.

## Personal admin logs from Profile (2026-11-09)

Admin Profile now links to **My Admin Logs** at `/profile/admin-logs`, with a
personal print preview at `/profile/admin-logs/print`. Both queries enforce the
authenticated admin's ID on the server. An officer query parameter cannot select
another officer's history. The shared `/admin/system-log` still contains all
officers' recorded activity. Personal and shared views use the same saved rows;
this feature does not duplicate historical entries or invent missing activity.

Personal history includes recorded sign-ins/sign-outs, duty declarations, report
decisions, navigation and account/settings actions. It offers search, inclusive
Philippine dates, activity/source filters, pagination and Print / Save as PDF
across matching pages. Today is the default; All dates retrieves older activity.
The existing 2,000-entry print limit applies to the current officer's matches.
Personal Start duty / End duty controls update only the signed-in officer and
return to their personal history. Visible-page polling does not add log rows.

Deploy the updated controller, activity middleware, guest-redirect configuration,
routes, Profile/shared-log
Blade views and rebuilt Vite assets together, then run `php artisan optimize:clear`.
No new migration or environment variable is required on an installation that
already has `admin_activities` and `admin_duty_shifts`. Keep the existing migrations
in the normal deployment startup for fresh installations. This is a local update;
it has not yet been pushed or deployed to `https://dualpath.bsit4bdevs.tech`.
After deploying the same reviewed commit through the existing `main` pipeline,
verify entry routes, deployed version/build metadata, an authorized Profile and
personal filtered print view, and isolation between two different officer accounts.
Unauthenticated personal-log requests must lead to the admin login portal; civilian
and ordinary responder accounts must not receive personal admin history.

Local verification: 428 PHPUnit tests / 5,092 assertions passed using the Laragon
PHP 8.3 binary directly with the ZIP extension enabled; 27 admin JavaScript tests
and the Vite build passed. A pre-existing dashboard fixture was corrected to retain
its explicit timestamps, rather than depending on setup finishing in one second.
Local caches were cleared, public entry routes returned 200, and personal history
and print correctly redirected guests to `/admin/login`. The browser visual check
was stopped by the user and was not completed. On this check, the hosted login
and version endpoints returned 200, but hosted `/profile/admin-logs` returned 404.
Hosted fingerprint `4c96486e54b23e15` differs from local `a17baabbcad0af93`; these
are file fingerprints, not Git commits, and do not establish branch/commit parity.

## Admin alarm, shared review and System Log (2026-11-07)

Admin Report alarm retains each officer's Sound on/Mute preference across full
sidebar navigation. A new page silently prepares audio when sound is on; it
offers Test sound without resetting the saved choice to Enable. Browser media
activation is checked again rather than persisted as a false readiness flag.
If playback is blocked, the setting remains on with explicit Test guidance.
Only fresh report arrivals trigger the bounded ten-second alarm. Initial
history stays silent; Stop, Mute and page navigation cancel old pending audio.
The previous admin MP3 is replaced by the exact supplied WAV at
`/sounds/admin-report-alarm-993.wav`. Civilian notification audio is separate.
The Nginx template explicitly serves this WAV as `audio/wav`, with native byte
ranges and revalidation, including container images whose default MIME map omits WAV.

Monitoring, Online Reports and LoRa Reports share the same report review card,
with evidence, severity assessment, map, admin process/decision and officer
history. LoRa retains its node/radio timestamps, delivery state and independent
authenticity verification. Severity review, authenticity verification and
rescue status remain separate actions. No LoRa protocol or AI model changes.

Admin-only System Log is at `/admin/system-log` and in the sidebar. The new
`admin_activities` table keeps officer name/ID snapshots, report codes, saved
report action summaries, logins/logouts, navigation, settings/account actions,
print-preview requests and explicitly identified browser interface events.
Request bodies, credentials, media and internal report notes are excluded.
Saved report actions come from immutable report audit entries; browser events
cannot fabricate a saved decision. Deleted reports do not delete their logs.

Use Start duty/End duty to declare officer monitoring shifts. Logout closes the
officer's open declared shift. Open shifts show last server activity, including
an inactivity warning; a declaration alone is not proof of current presence.
Distinct admin accounts retain distinct actors across handovers. Duty does not
block urgent report processing or alter report assignment.

The default day is midnight-to-midnight Asia/Manila. Visible log pages refresh
every thirty seconds without recording the refresh as a visit. Editing filters
pauses refresh; explicit historical date filters stay fixed. At midnight the
default daily view returns to page one. The database history is retained.
Search, inclusive dates, officer, activity and source filters also apply to the
print preview across all matching pages. Narrow filters if more than 2,000
entries match. Print uses the existing A4 report styles with System Log page
labels; select Save as PDF in the browser's print dialog.

Deploy code, assets and the public WAV together. Startup's existing
`php artisan migrate --force` creates `admin_activities` and `admin_duty_shifts`
and imports surviving report audits once. Existing report audits retain their
original actor/time; missing historical sessions or duty are not invented.
`php artisan admin-activity:backfill` safely retries the idempotent import.
Clear Laravel caches and rebuild Vite assets. Verify public entry routes,
admin-protected log routes, the shared review/signature assets and WAV hash.

## Civilian tutorial incident selection and notification chime (2026-11-07)

Quick tutorial now includes a safe incident selection preview before scene photo:
Road Accident is available and other types are coming soon. The sequence has
seventeen steps, including six numbered reporting steps. Completed/skipped tours
remain dismissed; older active progress resumes no later than the new selector.

The civilian Updates panel uses the supplied positive-notification MP3 at
`/sounds/civilian-notification-416bef34427e.mp3`. Its bytes are unchanged from the
uploaded file. Enable sound silently prepares playback through a user gesture;
Test sound plays the chime once. Mute is saved per account in this browser.
Fresh report/admin updates and `app_update_available` events can chime while the
page is visible and connected. Report status alerts also gate report sounds.
The first successful feed is a silent baseline, so old unread notices do not
sound on page opening. Repeated polls, read receipts and connection checks do
not replay the same event. All underlying updates remain in the System Log.

Sound claims use per-account numeric storage cursors and Web Locks where
supported (best-effort storage rereads otherwise). No report content, playback
readiness or pending sound is persisted. A chime never loops or overlaps; media
loading and playback are bounded, and leaving/hiding the page or losing the
authenticated session stops playback. Autoplay rejection shows Enable sound
guidance without hiding notifications or queueing stale audio. Device silent
mode, volume and browser rules still apply. This is visible-page audio, not
closed-app Web Push or a delivery guarantee.

Deploy the normal Blade/JS/CSS/config build **and the public MP3** together,
then clear Laravel caches. No migration or new environment configuration is
required. Public `/sounds/` requests, including Range headers, pass through the
existing service worker without entering its build-asset cache. Verify the MP3
hash/content type, updated tutorial and sound module signatures, version and
real public entry routes after the automatic Dokploy rebuild.

## Civilian incident type selector clarity (2026-11-07)

The civilian Report incident selector has a bordered, tinted control, a larger
current value, tap guidance and a chevron that rotates when expanded. The native
details/radio behavior is retained, with a visible keyboard focus outline and
focus returning to the summary after selecting Road Accident. Selected and
unavailable options are visually distinct in light/dark mobile and desktop views.
Available report types and validation are unchanged. Deploy the normal Blade,
JS/CSS and release config build and clear caches; no migration is required.

## Civilian device permission setup and automatic GPS (2026-11-07)

Home links to Device permissions, where civilians can explicitly allow camera
and location and test a selected photo locally. The Report form has device-access
guidance and a camera permission button. A camera permission check closes every
track immediately, including a stream resolving after the page is hidden. The
camera never starts automatically merely because permission was previously given.
Photo access uses the native file picker for the selected image, not a broad or
persisted filesystem permission. The tutorial and Home do not trigger prompts.

Report queries the real browser geolocation grant. With a granted state it obtains
fresh coordinates on entry, foreground/BFCache return and draft restore. Prompt,
denied and unsupported permission-query states require an explicit Get GPS action.
No grant or coordinates are invented from local storage. The final submission
still checks current location with maximumAge zero, bounded coordinates and a
reading no older than thirty seconds. Revocation, backgrounding and restoration
invalidate in-flight fixes so old callbacks cannot repopulate the form.

Browser permission cannot turn on the device's Location switch or guarantee that
the browser remembers consent indefinitely. GPS failure clears the coordinates
and keeps sending blocked, with guidance to enable device Location/site access.
The readiness screen no longer suggests a written landmark can replace required
GPS. Existing verification, live-selfie checks and admin report flows remain.
Deploy the normal web assets/views/config and clear caches; no migration, native
APK rebuild, new environment setting or AI change is required.

## Civilian admin report notifications (2026-11-07)

The existing owner-scoped CivilianActivity record remains the single source for
notifications and the permanent System Log. Admin acknowledgement and response
notices distinguish an actual admin action from the original server receipt.
Status transitions, changed response messages and assignments notify; redundant
coordination saves remain in the log without generating another unread alert.
Internal notes, AI inputs and model details are excluded from the notice text.

The private updates feed returns the newest twenty unread events first and only
links a report that still belongs to the signed-in account. Deleted reports keep
their report code and log entries without a broken report link. Reading an event
only stamps read_at; it never deletes its history. Existing report-alert settings
are respected. Future civilian registrations enable report alerts by default;
previously saved preferences are preserved.

While AksyRes is open, the existing authenticated polling flow presents a visible,
nonblocking report notice alongside the unread Updates badge. Notices are
deduplicated per account across polls and page opens. Dismiss hides the notice;
it does not mark the server record read. Reconnect/reopen retrieves server-saved
updates. This release adds no server Web Push delivery while the app is closed.
Deploy the normal Blade/JS/CSS/config build and clear Laravel caches. No new
migration, notification service, email/SMS delivery or AI change is required.

## Civilian human-reviewed severity (2026-11-07)

Civilian Home, history, View All details, full reports, submission receipts and
the civilian API share a restricted severity projection. Until an explicit,
current human review exists, they show `Severity under review`, even when a
report is acknowledged, responding or resolved. The latest review must match
the current incident description and severity and include recorded reviewer
provenance. Older reviews without that provenance require a fresh assessment;
stale decisions are not published as current severity.

After review, `Human-reviewed Severity` is primary. Expandable assessment details
show the recorded original AI label when available, confirmation/correction,
reviewer and Philippine review time. Model scores, raw model metadata and
internal review reasons are excluded. The admin review form has a separate
optional explanation explicitly intended for the civilian; older internal
notes are never repurposed as public explanations. An assessment requesting
more information has no confirmed severity and exposes no AI comparison.

Both existing review paths retain an audit snapshot of the original suggestion
and bind new reviews to the submitted description. No model/configuration,
severity mapping, response-state or Reverb admin-feed behavior is changed.
Deploy the normal web build and clear Laravel caches. No migration is required.

## Civilian admin contact (2026-11-07)

Civilian Home and App Settings show a Contact Admin card with
`aksyres900@gmail.com` and an Email Admin link. Both open the device's email app
using `mailto:` for account concerns, app issues and feedback. Emergency reporting
continues through Report Emergency. The support address is defined once in
`web_system/config/support.php`; legal/privacy contact configuration is separate.
The floating Updates panel also lists this addition. Deploy the normal web build
and clear Laravel caches. No migration or mail server configuration is required.

## Civilian guided tutorial before verification (2026-11-07)

Civilian web registration and unverified password/Google sign-ins now land on
Home, including when a saved intended URL would otherwise force the report or
verification screen. Verified sign-ins retain their existing intended/default
destinations. Legal acceptance, admin portal checks, account blocking, live
verification and server-side report submission checks remain enforced.

On unverified Home, a first-visit spotlight tour introduces actual navigation,
the floating Updates badge and a read-only reporting preview. Seventeen short
steps explain Home, Report/Verify, incident selection, scene photo, GPS/written location, description,
fresh report selfie, server receipt, drafts, status/history, profile, settings,
System Log and Log Out. Next/Back/Skip/Done control the tour; Escape skips.
The last step offers Verify Account without forcing it. The preview has no
file inputs, submission form, permission requests or transmission actions.

Home's Quick tutorial button replays it. Settings and verification also link to
Home with `?tutorial=1`; the replay parameter is removed after being consumed.
Progress is stored locally under `aksyres-civilian-tutorial:v1:<account>`.
Skip/Done suppress automatic replay for that account in this browser. Active
progress resumes on reload; clearing browser storage can offer the tour again.
Verified users can replay manually. Storage denial never traps a user in the tour.

The tour uses the existing dialog focus/Escape handling and a blocking spotlight
so highlighted navigation cannot accidentally sign out or submit anything.
It points at visible bottom navigation or opens the drawer when needed, keeping
consecutive sidebar steps together. The drawer it opened closes on exit.
Callouts reposition on resize/scroll and use the same civilian mobile/desktop CSS.

Deploy auth controllers, Home/settings/verification views, tutorial partial and
JS/CSS, release config and matching Vite assets together. No migration, new
dependency, environment change, model or firmware update. Validate with the
Laravel/JavaScript suites, Vite build and `tool/check_civilian_tutorial.cjs`.
The browser fixture renders the real Blade Home with synthetic, unsaved users
and in-memory database config; it uses real sidebar/dialog/tutorial code on an
isolated server. It never calls live authentication, camera/GPS or report APIs.
Verify public entry routes and deployed tutorial asset signatures after push.
Validation passed: 357 Laravel tests / 3845 assertions, 60 JavaScript tests,
and all sixteen tutorial steps at 320/390/1366/1920px in the isolated browser
checks, including replay/resume, keyboard focus, resize, verification handoff,
zero report POSTs and zero permission requests. Physical devices and live
authenticated Google/verification flows remain separate pilot checks.

## Civilian report button readability (2026-11-07)

The report table, history preview and draft preview use explicit View report /
View draft labels. A shared civilian button style supplies 14px bold text,
contrasting light/dark colors, a minimum 44px tap height and enough width/padding
to prevent clipped labels. The isolated report-library browser checks verify
unclipped labels and at least 4.5:1 text contrast in both themes at 320, 390,
1366 and 1920px, after theme transitions settle. Release `2026.11.07.5` describes
the change in Updates. Deploy matching Blade, JS, CSS, release config and Vite
assets; no migration or environment changes.

## Readable civilian release notes (2026-11-07)

The floating Updates dialog starts with What's new / Mga giusab: the latest
release's title, release label, publication date and plain-language change list.
Earlier releases expand below it, including report tables, moving the badge,
draft recovery and System Log. Release labels are editorial identifiers, separate
from runtime build fingerprints in App version. Notes remain available after
notifications are marked read and after an update has been applied.

`web_system/config/civilian_releases.php` is the source of published civilian
release notes, newest first. For each future civilian feature release, add a
unique ID, accurate date, title and user-facing changes; retain earlier entries.
Do not infer descriptions from build hashes, client telemetry or private admin
audit notes. The current server release's change list also supplies the app-update
notification summary, so older clients can read a description in their existing
Unread updates list before they have the new notes section.

Blade escapes all notes on the initial signed-in page and in the private,
no-store system-updates feed. Check again/visible-app polling refresh the notes
from that same-origin feed. Unchanged responses preserve the displayed content;
changed responses retain expanded earlier-release IDs. A failed check leaves
the notes already included with the loaded page visible.

Deploy config, controller, Blade partials, civilian system JS/CSS and matching
Vite assets together. No migration or environment change. Tests cover initial
render, dates, persistence after marking read, server ownership/HTML escaping,
empty config, refresh without repeated replacement and existing update/draft
guards. The isolated phone/laptop/desktop browser fixture renders the real notes
through Laravel; set `QA_PHP_PATH` if its default `php` is unsuitable. No live
reports or account messages are sent by these checks.
Validation passed: 354 Laravel tests / 3803 assertions, 58 JavaScript tests,
Vite build and the phone/laptop/desktop update-dialog browser checks.

## Civilian View All report tables (2026-11-07)

Draft Reports, Report History and Report Status now open a shared View All modal
with an account-owned table, literal text search, status/date filters, sort,
ten rows per page, Previous/Next and numbered page buttons. Main pages keep
short previews (three drafts, five history records, one home status and two
recent status updates). The history page's search and pagination live in the
modal. Wide and compact screens use the same workflow; tables scroll sideways
on narrow screens and have a keyboard-focusable scroll region.

View opens a nested detail dialog. Back restores the list, filters, page and
focus. Submitted-report details are read fresh from the server and expose only
the authenticated civilian's records. The new `/reports/civilian-collection`
and `/reports/{id}/civilian-details` endpoints reject guests/other roles and
are private/no-store; the service worker treats them as network-only.
History sorts by submission time; Status sorts by latest recorded status time.
Server date filters use inclusive Philippine submission dates (UTC+8).

Drafts still belong to the current account on this device, including while
offline. Their date filters use the latest save date in Philippine time.
View can show saved text/photo and Continue/Delete actions. Continue preserves
the submission ID and uses the existing receipt reconciliation, fresh GPS and
live selfie checks; browsing never sends a report. Removing a draft refreshes
the preview/counts and clamps pagination if the last page disappears.

No migration, environment secret, model or firmware changes. Deploy the controller,
routes, shared layout/modal, draft/list modules, service worker and matching
Vite build together through the normal push-to-main automatic deployment.
Validated: 352 Laravel tests / 3778 assertions, 57 JavaScript tests, Vite build,
`tool/check_civilian_report_library.cjs` at 320/390/1366/1920px and the existing
`tool/check_civilian_drafts.cjs` browser recovery suite. Browser fixtures use real
partials/modules/assets and mocked feeds; no live reports are submitted.
Verify public entry/version routes, the new deployed asset signatures, and
unauthenticated 401 responses for the collection/detail endpoints after deployment.
Physical-device and authenticated live-account checks remain pilot verification.

## Draggable civilian Updates on phone, laptop and desktop (2026-11-07)

The shared civilian Updates badge supports touch, pen and primary-mouse dragging.
A drag does not open the modal; a tap/click still opens it. Position is remembered
per account in this browser and adapts proportionally to viewport/orientation
changes, with safe-area padding and room above visible bottom navigation.
Moving still works when local storage is denied, but that position lasts only
for the page. Keyboard users can focus the badge, move with arrow keys, press
Home to reset the position, or Enter to open updates.

Updates, unread counts, Check again, Update app, Draft Reports and System Log
use the same civilian layout/modules on desktop, laptop and phone. Wide screens
access Draft Reports and System Log from the sidebar; compact screens also have
the six-item bottom navigation. Logout stays in the sidebar.

Deploy the matching Vite build and Blade partial with the normal automatic
`main` deployment. No additional migration, environment setting or APK rebuild.
Validate with the Laravel suite, `node --test tests/js/*.test.mjs`, Vite build,
and `tool/check_civilian_updates.cjs` (Playwright via `PLAYWRIGHT_MODULE`, optional
installed Chrome via `UI_BROWSER_PATH`). The isolated browser fixture uses the
real widget partial/modules/built CSS and mocked feeds, never live reports.
Phone touch (390px), laptop mouse (1366px), desktop mouse (1920px), reload,
320px resize, modal/check feedback and keyboard reset passed. Physical device
and authenticated live flows remain separate pilot checks.

## Civilian floating Updates and System Log (2026-11-07)

The civilian connection panel is now in a floating Updates badge/modal across
the signed-in app. Its number counts unread app-release and report-admin events;
Mark shown updates as read acknowledges only the displayed, account-owned IDs.
The modal contains Check again, running/latest/previous device build IDs, and
Update app. Civilian version/worker changes notify instead of automatically
reloading. A requested update waits for unfinished report text/photo persistence
and refuses a reload during transmission or when the photo cannot be saved.
Admin automatic updates retain their existing behavior. Browser notifications
are not requested; this badge polls while the app is open/visible.

System Log replaces only the civilian bottom Logout item. Logout remains in
the sidebar. The log provides literal text/type searches, inclusive Philippine
date filters (UTC+8), pagination and Copy text for the current filtered page,
with a selectable-text fallback when clipboard access is denied. It has no print
or download action; its content is hidden in print styling.

**Migration required:** `2026_11_07_000001_create_civilian_activities_table`.
The existing `start-web.sh` runs `php artisan migrate --force` before starting
PHP-FPM. The additive table records successful web/Google/API sign-ins, explicit
sign-outs, report receipts and safe projections of recorded admin actions.
Deletion retains the report code/activity for its owner. Backfill is idempotent
and imports surviving report receipts and dated admin audits as already read.
It cannot reconstruct old sign-outs, unknown timestamps, or ownership of reports
deleted before this feature. Closing the app/shutdown is never inferred as logout.
Internal notes, AI payloads, credentials, IPs and other users' history are excluded.

App version/update/connectivity entries are explicitly device-reported. Current
and previous build values are build fingerprints, not semantic/APK versions.
An account-scoped local queue retries device events after reconnection; the server
deduplicates IDs and release notices. Old/invalid telemetry cannot block current
notifications. Failed telemetry sync does not hide report notices, and a durable
queue lets a user-requested update proceed even if telemetry writes are unavailable.
All log/feed/read/event routes enforce the authenticated civilian account.

Deploy the model/service/observers, auth hooks, routes/controller, migration,
layout/views, PWA/update/draft scripts, service worker and matching Vite build
together. No model, firmware, environment secret or LoRa setting changes.
Validation: full Laravel suite 348 tests / 3725 assertions and 51 JavaScript tests; new tests cover
ownership, historical import, real admin transitions/deletion, timezone filters,
literal search, login/logout, event forgery prevention and unread races. JS tests
cover polling, manual update gating, draft protection, retry queues, clipboard
fallback and unchanged admin updates. Run `node --test tests/js/*.test.mjs`,
`npm run build` and Laravel checks before release. Browser automation was
unavailable; physical-phone visual/accessibility and real live-account flows
still require pilot verification. No fake emergencies are sent to the live app.

Push to `main` for automatic deployment, then verify public entry routes and new
build signatures, plus unauthenticated 401s on `/account/system-log` and
`/account/system-updates`. Authenticated pilot checks must include a designated
civilian report's notifications/timestamps, date search/copy and the update modal.
Pre-release hosted fingerprint: `098664c148c3c4c1`.

## Civilian connection feedback and bottom Drafts tab (2026-11-07)

The phone's bottom navigation now includes Drafts between Report and Profile.
Its account-scoped count matches the collapsible sidebar and clears/hides at
zero. Both links expose their count to screen readers and mark the active draft
page. Civilian navigation uses six columns with compact spacing for 320px phones;
the other role menus retain their existing layout.

Check again performs the same read-only authenticated server probe, but now shows
Checking while pending and leaves a numbered, timestamped result with response
time on success. Offline/session/server failures also leave a visible result.
Repeated fast successful checks produce distinct feedback. The form explains
that checking server access does not submit a report. Automatic polling no longer
cancels a pending check; offline events still abort stale results.

Deploy the Blade layout/form, civilian navigation/connection CSS, draft UI and
connection JS with matching Vite assets. No migration, model, firmware or new
environment setting is needed. Check ReportConnectionTest, CivilianReportDraftTest,
the JavaScript connection/draft navigation tests, full Laravel suite and Vite build.
Validation passed: 340 Laravel tests (3636 assertions), 16 JavaScript tests and
the Vite build. Automated checks cover both count badges, role/active-page markup,
fast repeated successful checks, loading/results, offline/session errors and
polling during an active check. Physical-phone visual testing remains separate.
Push to `main`; this host automatically rebuilds pushed commits. Compare the
published build/signatures with the release and verify public app entry routes.
The pre-update online fingerprint was `28680c95e20a51f3`.

## Civilian Draft Reports navigation and connection status (2026-11-06)

The civilian collapsible sidebar now links to `/reports/drafts` and displays an
account-scoped count of local drafts. The list is on that dedicated page, with
Continue/Delete and an empty state; it no longer appears on Home, Report Status
or Send Report. Administrators/responders cannot open the civilian draft route.
The report form also checks the authenticated, read-only `/reports/connection`
endpoint and distinguishes server reachability, offline state and session issues.

Deploy the controller/routes, layout, draft view/partial, draft store/recovery/UI,
connection/delivery modules, CSS and rebuilt Vite assets together. Text backups
are saved synchronously on edits before waiting for photo storage. Background
and pagehide events also flush pending saves. A newer text backup retains a
matching committed photo without accepting an older queued text write. A photo
whose transaction was interrupted may need reattachment. Drafts stay scoped to
the same account, browser/device and origin; there is no cross-device sync or
automatic send on reconnection. Existing same-origin drafts are retained. Fresh
GPS and live selfie verification are still required to send.

No migration, firmware upload, AI model change or environment variable is needed.
The suite passed 339 Laravel tests (3623 assertions), 15 JavaScript
draft/navigation/connection tests and the Vite build. Local caches were cleared
and public entry routes/assets verified. Push this release to the connected
`main` branch for the existing automatic rebuild. At pre-push verification, the
public `/system/version` fingerprint was `8ec95bc335cd8b48` and `/login` returned
200. This fingerprint is not a Git SHA; record the pushed commit and compare
hosting release metadata plus the actual published bundle after deployment.

After rebuild, verify `/login`, `/register`, `/system/version`, matching asset
responses and the new draft/connection source signatures. Authenticated checks
should cover the Draft Reports menu/badge, account isolation, continue/delete,
close/reopen and cleanup after a confirmed receipt. Do not create fake emergency
reports on the operational system. Physical-phone/browser testing remains a
separate pilot check. See [questionnaire and pilot notes](CIVILIAN_PILOT_EVALUATION.md).

## LoRa description AI and admin severity review (2026-11-06)

Hardware LoRa alerts now use `AiTextSeverityClient::analyzeForReport`, the same
description-only pipeline as civilian online reports. Gateway-provided severity
is a firmware transport default and does not become a human assessment. No
model, threshold, phrase rule, dataset, or training artifact is changed.

Laravel saves and announces the report before scheduling `AnalyzeLoraDescription`
with `dispatchAfterResponse`. Production uses PHP-FPM, which finishes the gateway
HTTP response before the text service is called; no separate queue worker is
needed. While analysis runs, the report is unassessed and requires review. Blank
legacy descriptions are not replaced with generated transport metadata as AI
input. MANUAL_ONLY/disabled AI and AI outages keep the report available for admin
review, using the same configured fallback as online reports.

LoRa Reports > Review now includes the shared analysis, score display and admin
confirmation/correction controls. Older unreviewed LoRa reports can use Analyze
description with AI individually. Severity review records the original suggestion
and reviewer reasoning, independently of authenticity verification, rescue status,
and the manual radio ACK. An AI update broadcasts `ai_suggestion`, never a second
new-report alarm. Duplicate SOS retries neither re-analyze nor replace a human
review; concurrent admin decisions take precedence over delayed AI results.

After-response processing is not a durable queue: if the PHP process is killed
after receipt, a report can remain pending. The admin can still assess it or retry
with Analyze description with AI. Existing reports are not bulk reclassified.
Deploy backend classes and Blade views together; no migration, credential,
firmware re-upload or model retraining is required. Validate LoRaTextAnalysisTest,
LoRa ingestion/history/ACK tests, the web suite and Vite build. After deployment,
check the public routes and AI readiness plus the protected LoRa review modal;
do not inject fake emergency reports into the live system to test inference.

## Quiet alarm activation and fresh-arrival audio (2026-11-06)

Deploy `report-alarm.js`, `incoming-reports.js`, the layout copy, the initial feed
snapshot bound and rebuilt Vite assets together. No migration, new credential,
AI retraining or model selection change is involved.

Ordinary trusted clicks/keys prime the audio element while muted, then immediately
pause/reset it; they do not play the former 1.2-second activation sample. Explicit
Test sound still plays a short sample. A fresh Online/LoRa arrival plays the
existing MP3 for ten seconds, with Stop alarm and persistent Mute controls. The
arrival uses looping and a fixed timer; a source-file end cannot truncate the
alarm or extend its deadline. The supplied file is about 74.19 seconds long.

Every newly loaded page establishes a silent initial history snapshot, including
the already pending/unreviewed reports. Only subsequent live arrivals sound.
The feed bounds initial rows by its captured maximum ID so a concurrent new
arrival remains available to the next delta poll. Echo/poll duplicates, status
edits and report links do not create extra alarms. Only the enabled preference
is persisted; obsolete stored pending-audio booleans are ignored. Stop or page
navigation does not replay an old alarm. Visual unread notifications remain
separate from the incident's review/response status.

When browser autoplay blocks a real arrival, the visible Play report alarm
button retries it explicitly. An unrelated click only primes silently. Sound
cannot be guaranteed on a locked/suspended device or when browser/OS audio is
blocked; retain the visible alarm/unread UI. After receiver authorization
expires, both polling and further arrival audio stop.

Validate with `npm run test:report-alarm`, Laravel `IncomingReportTest`, a Vite
build and `tool/check_report_alarm.cjs` using an installed Playwright engine. Its
sixteen-report feed is isolated; playback-state checks include simulated media
and the real MP3 in an isolated browser with speaker output muted. It never
creates a real incident. Verify the deployed JS signature, public app
route, and real admin activation/arrival on the intended device separately.
References: [media autoplay](https://developer.mozilla.org/en-US/docs/Web/Media/Guides/Autoplay),
[media looping](https://developer.mozilla.org/en-US/docs/Web/API/HTMLMediaElement/loop).

## Civilian interrupted-upload drafts (2026-11-06)

Deploy the civilian draft UI/store/upload modules, report Blade views, scoped
submission receipt route, controller JSON responses, and rebuilt Vite assets
together. No new database migration or environment variable is needed. The
existing native form redirect and responder submission remain supported.

The dedicated civilian Draft Reports page shows local drafts with Continue/Delete,
the last saved time and interruption state. The sidebar count appears across the
civilian workspace. Text and the scene photo are saved
to account-scoped IndexedDB before upload. The same submission UUID is retained
when a draft resumes. If IndexedDB is blocked/full, text-only storage is explicitly
labelled and the photo must be reattached. If both stores fail, the UI says that
local saving failed; keep the form open rather than assuming a draft exists.
Local drafts stay on that browser/device; they are not server receipts and can
be lost if browser site data is cleared or removed by the device.

The upload stays in the page, handles network failure even when the browser says
online, notices slowness after 15 seconds and aborts an unconfirmed upload after
90 seconds. A user can stop the request and keep the draft. Reconnection does not
automatically submit a report. Continue/Send checks the user's own receipt when a
previous attempt may have reached the server; existing ingest-key idempotency
prevents a repeated submission UUID creating a second report. The read-only
`GET /reports/submissions/{submissionId}` route requires authentication and
returns no details for another user's UUID, with private/no-store responses.

Resuming restores the description, landmark and available scene photo. GPS fixes,
verification selfies, CSRF tokens and liveness proofs are not persisted in these
drafts. A fresh GPS lock and live face/blink check are still required to send.
Previously unowned legacy draft records are not automatically attributed to a
signed-in account. The administrator's existing draft tools remain separate.

Validation: `npm run test:report-drafts`, Laravel draft/persistence/verification
feature tests, `npm run build`, and `tool/check_civilian_drafts.cjs` using an
installed Playwright engine. Its local fixtures never submit a real report.
Before calling this release live, verify the new build asset identity, public
login, unauthenticated receipt route (401 JSON), and an authenticated application
page. Test a genuine phone connection interruption with a designated test report
separately; simulated upload failures do not establish cellular performance.

Browser references: [online status is only a connectivity hint](https://developer.mozilla.org/en-US/docs/Web/API/Navigator/onLine),
[IndexedDB completion confirms the transaction committed](https://developer.mozilla.org/en-US/docs/Web/API/IDBTransaction/complete_event).

## LoRa delivery and incoming-report alerts (2026-10-29)

The later `mesh-reconnect-20261029` firmware also needs the ACK `ackToken` /
optional `ack_token` callback support in `LoRaMeshGatewayController`. Deploy
the API before uploading the matching gateway/field sketches. This update uses
no new migration or credentials; it conditionally confirms the response revision
under a row lock so a delayed old radio ACK cannot clear newer admin notes.
Legacy callbacks without a token remain compatible. See
`Arduino/CONNECTION_STABILITY.md` for firmware rollout and field validation.

Deploy the authenticated `POST /api/v1/lora/heartbeats` endpoint together with
the receiver-only `GET /monitoring/reports/feed`, layout, notification module,
and rebuilt Vite assets. No database migration or new credentials are required.
The batch endpoint reuses the existing heartbeat rules in one transaction,
preserves captured packet age, rejects invalid/duplicate node rows atomically,
and reduces ten-node HTTPS traffic. Existing single heartbeat clients still work.

Incoming arrivals of every severity and both channels now populate the Alerts
badge, persistent in-page banner, and per-browser/per-user unread list. The
receiver can enable sound with a user gesture and mark arrivals seen without
changing rescue/report status. Reverb events and the 5-second polling fallback
share deduplication, so a report cannot double-notify or double-increment the
latest list. The fallback is session-authenticated, excludes civilians, and
returns no cached/private data to unauthenticated requests.

Release the server first, then flash `GatewayNode1.ino` and every matching
NODE_2–NODE_10 sketch from this Laragon repo. The Gateway's new dedicated HTTP
worker keeps radio/LED service active during HTTPS and queues incoming SOS in
per-origin NVS inboxes. A signed nonfinal server receipt now distinguishes radio
transmission from actual ingestion; final responder ACK semantics remain intact.
Existing matching mesh keys must be retained; do not reprovision keys merely
to update firmware. Old Gateway/API combinations use the legacy heartbeat
endpoint until restart, with reduced throughput compared with the batch API.

Verify the new routes, public login, release/build identity, and an authenticated
monitoring page. On hardware, send from NODE_4 then NODE_3; confirm both distinct
message IDs reach the server, each origin receives its server receipt, then
verify the final responder ACK and red LED independently. Observe peer expiry
after actual radio loss rather than treating successful TX as a received peer.
Simulations and compilation do not claim to verify physical RF conditions.

Local release checks passed: 310 Laravel tests (3,286 assertions), six notification
JavaScript tests, Vite build, Gateway/NODE_3 ESP32 builds, and source-extracted
LED/route/inbox simulations. No connected browser was available for visual QA.

## Google sign-in placement and LoRa print preview (2026-10-28)

The civilian login now displays the terms agreement and privacy acknowledgment
immediately above **Sign in with Google account**. The button is enabled only when
both are checked; browser-required fields and the existing server validation still
reject an incomplete/stale submission. The login form posts directly to the
existing Google authorization endpoint, without an extra agreement page. The
standalone GET `/auth/google` page remains available for old bookmarks. The triple
legal-link footer row is omitted on civilian login; the linked documents remain
available in the Google form and on the other public/settings pages.

Deploy `public/auth-legal.js`, its version-watch entry, the Blade views, and rebuilt
Vite assets together. No policy wording/version change, migration, or APK rebuild
is needed. The Google form is separate from ordinary password sign-in and is not
shown in the admin portal.

LoRa report review now has **Print report**, opening the same preview/options and
A4 print mechanism as Online Report at `/lora-reports/{incidentReport}/print`.
The route retains the existing admin-receiver authorization and rejects online
records. It prints one selected LoRa record, original node/message identity,
route/hops, available radio/battery values, recorded response and acknowledgment
timestamps. Registered location and contact details are opt-in; verification
selfies and scene media are excluded. Printing never acknowledges a report or
queues a radio response. Website display/print times use Asia/Manila while the
original node timestamp remains exactly as supplied. The shared print styles
provide LoRa-specific headings and page furniture without relabeling fixed node
positions as measured GPS fixes.

Verify the live login form, unchecked/checked gate behavior, rejected incomplete
POSTs, and an authorized LoRa preview after automatic deployment from `main`.
Browser visual/print-dialog QA still requires a connected browser or physical
device; HTTP and automated tests do not claim to verify paper output.

Local checks for this update: 297 tests passed (3,110 assertions), Vite build
passed, `auth-legal.js` passed the JavaScript syntax check, and Blade views
compiled. No report state or acknowledgment is changed by the print request.

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

## Admin audit printing (2026-10-30)

Overview keeps the latest six report audit actions. View all opens the existing
ten-per-page modal with report/admin/note search, source, action, inclusive
Philippines audit dates, and admin-name filters. Checkboxes retain selections
across pages; applying or resetting filters clears them. Print one action from
its row or detail dialog, print selected actions, or print every action matching
the applied filters, including pages not currently loaded.

`/admin/report-audit/print` uses the same admin access controls and historical
audit query as the list. Printing is read-only and retains deleted-report audit
snapshots. The A4 preview uses the existing report print assets, with optional
recorded notes and no raw context, IP/device metadata, model internals, or secrets.
The copy shows the selected scope, filters, admin, audit time, report reference,
source, and historical status/severity changes. Copies are private and no-store.

Deploy the normal web build and clear Laravel caches. No migration, firmware,
severity-model change, or APK rebuild is required. Validate with the Laravel
suite and Vite build; check multi-page selections, date boundaries, print
preview, deleted history, and short-screen modal scrolling.

## Reviewed feedback learning worker (2026-11-09)

The subsequent six-category common-vocabulary checkpoint is an **isolated local
AI Lab experiment**, not this deployment's live model. Its local test service
uses port 8101 and `test_model_directory`; the normal `text-ai` service and
`TEXT_AI_MODEL_DIR` stay on the road-only v2 release. Do not promote the Lab
checkpoint through an environment-variable change. Its final complete-policy
synthetic results were 72.92% on 144 family-test descriptions and 68.49% on a
separate 384-description diagnostic, so the requested 80% target failed.
All data remain unreviewed drafts and deployment approval is false. See
[the evaluation](ai_service/artifacts/reports/multiincident_common_20261109/EVALUATION.md)
and [local Lab setup](ai_service/LOCAL_TEXT_AI_LAB.md). These changes were not
deployed to Dokploy or Render.

The normal web startup applies the new `ai_training_feedback` migration and starts
`schedule:work` with `RUN_SCHEDULER=true`. Its hourly export contains only explicit
description-only training approvals with admin-reviewed language. The PHP image
keeps `AI_LEARNING_ENABLED=false`: it writes private feedback but does not launch
Python training. Never add the app signing key or LoRa ingest token to a training
worker.

The optional `learning` profile is independent of the `text-ai` inference service.
It installs the text baseline requirements without the face runtime, publishes no
ports, has no network, and is limited to one CPU and 768 MB. Web startup creates
and owns the learning directories as Alpine `www-data` before becoming healthy;
the scheduler runs through `su-exec` as that user and the learning worker uses
the matching UID/GID `82:82`. Directories remain private (`0700`) and feedback
exports remain `0600`; do not fix access errors by making the pool public. It reads
`/learning/private/ai-learning/feedback.jsonl` from the existing `app-storage` named
volume and writes candidate state below the same private directory. The current
release is loaded as an input from the worker image; candidates never replace it.
Keep this volume private and retain its backups under the same access controls as
the app's private storage. Do not mount it into public web paths or expose it as a
download route.

After reviewing the server's available resources, opt in using the Compose profile
in Dokploy or the equivalent local command:

```sh
docker compose --env-file .env -f compose.dokploy.yaml --profile learning up -d --build
```

The default thresholds are 50 newly approved examples, accuracy 0.80, Critical
recall 0.95, and at least five independently reviewed cases per evaluated cell.
`AI_LEARNING_MIN_NEW`, `AI_LEARNING_TARGET_ACCURACY`,
`AI_LEARNING_CRITICAL_RECALL`, and `AI_LEARNING_MIN_CELL` configure the worker.
`AI_LEARNING_BASE_MODEL_DIR` defaults to the packaged active road release; changing
this input does not activate a new inference model.

No independent holdout is bundled with real feedback. Without one, candidate
evaluation cannot pass its release gate. To supply a separately reviewed holdout,
create an untracked Compose override that adds a read-only directory bind mount,
for example `/private/review:/review:ro`, to the `learning` service. That directory
must contain both `holdout.csv` and its sibling `holdout.csv.manifest.json`. The
manifest must bind the exact CSV SHA-256 and record `schema_version: 1`,
`independent_review: true`, `ai_blinded: true`, and an accountable reviewer. Grant
UID/GID `82:82` read/traverse access to that private directory and its files.
Copy the service's existing command list and append `--holdout`,
`/review/holdout.csv`. Keep every existing candidate/watch flag in that override.
Store no raw names,
contacts, signing keys, or production reports in the tracked repository.

The worker checks hourly (`--watch --interval 3600`) and reconciles the full export
snapshot, so withdrawn/superseded approvals disappear from the current pool. Its
state and evaluation report must be inspected before a separate model-release
decision. No automatic activation occurs, and 80% has not been claimed from the
empty/new feedback queue. Existing category locks and the active release remain
unchanged. This update prepares the deployment path; enabling the profile or
verifying a deployment remains a separate operation.

## Local Compose check

After Docker Desktop is installed, create an untracked `.env` from
`dokploy.env.example`, replace its example values, then run from the repository
root:

```sh
docker compose --env-file .env -f compose.dokploy.yaml config
docker compose --env-file .env -f compose.dokploy.yaml up --build
```

Do not use the example values on a public deployment.
