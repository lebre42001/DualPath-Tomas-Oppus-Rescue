# Dokploy Docker deployment

This repository is prepared for Dokploy's **Docker Compose** deployment mode.
The Compose stack builds the Laravel PWA from `web_system/`, runs MariaDB, and
keeps the app's uploaded evidence and database in named Docker volumes. Nginx
serves Laravel and proxies Reverb WebSockets through the same HTTPS domain.

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

## Local Compose check

After Docker Desktop is installed, create an untracked `.env` from
`dokploy.env.example`, replace its example values, then run from the repository
root:

```sh
docker compose --env-file .env -f compose.dokploy.yaml config
docker compose --env-file .env -f compose.dokploy.yaml up --build
```

Do not use the example values on a public deployment.
