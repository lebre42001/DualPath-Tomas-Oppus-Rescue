# Online Deployment Plan

The current user-confirmed online target is `https://dualpath.bsit4bdevs.tech`
on Dokploy Compose. The newer owner-authorized category-specific advisory
activation is documented in [DOKPLOY_DEPLOYMENT.md](DOKPLOY_DEPLOYMENT.md).
`render.yaml` remains the separate AI-disabled legacy demo; its settings do not
control the current Dokploy domain.

## Personal admin logs from Profile (2026-11-09)

The local Laravel update adds `/profile/admin-logs` and its personal print/duty
routes. History and printing always use the signed-in admin's ID; the existing
System Log remains the shared officer history. Deploy the controller, activity
middleware, guest-redirect configuration, routes, Profile/log Blade views and rebuilt assets together and clear
Laravel caches. No additional migration or environment setting is required on the
existing installation. See `DOKPLOY_DEPLOYMENT.md` for the active
`https://dualpath.bsit4bdevs.tech` deployment path and two-account verification.
This change has not been pushed or deployed. The free Render demo is separate and
retains its AI-disabled, local ephemeral storage/free Postgres assumptions; it is
not production-safe.

## Civilian System Log and floating Updates (2026-11-07)

This release adds the `civilian_activities` table through the new additive migration.
The existing container startup runs migrations before PHP-FPM. Deploy auth hooks,
report-audit projection/observers, the civilian log/feed routes, Blade/CSS/JS and
rebuilt assets together. Connection checks move into the floating Updates modal;
civilian app updates are now applied on request after saving drafts. System Log
replaces bottom Logout while preserving sidebar Logout. Historical import uses
only surviving reports and recorded timestamps; no old login/logout history is
invented. See `DOKPLOY_DEPLOYMENT.md` for the active hosted release and checks.

## Civilian navigation and connection feedback follow-up (2026-11-07)

The civilian bottom navigation now includes Drafts with the same account-local
count as the sidebar. Check again now shows progress and a persistent numbered,
timestamped result, including response time or the reason access is unavailable.
Deploy the layout/form, draft/connection scripts, CSS and matching Vite assets
together. No migration or environment changes are required. The active public
application uses the automatic `main` deployment at
`https://dualpath.bsit4bdevs.tech`; see `DOKPLOY_DEPLOYMENT.md`. The root Render
profile remains a separate disposable demo.

## Civilian Draft Reports navigation (2026-11-06)

Deploy the civilian layout, standalone `reports/drafts` view/route/controller,
draft store/recovery/UI modules and rebuilt CSS/JS together. The civilian
collapsible sidebar now has Draft Reports with an account-scoped local count;
draft lists no longer appear on Home, Report Status or Send Report. Empty lists
show a dedicated empty state. Administrators and responders cannot open the
civilian-only route.

Text is checkpointed synchronously while editing; photo saves use IndexedDB.
Background/pagehide events additionally flush pending saves. Newer text backups
retain matching committed photos and cannot be replaced by an older queued
write. Drafts remain on the same device/browser and origin across app restarts
and account sign-out/sign-in. They do not sync across devices or between local
and online URLs. A photo interrupted before its storage transaction commits may
need reattachment. Browser storage clearing/eviction can remove local drafts.
Existing drafts in the same origin/account storage are retained; no migration,
AI model switch, credential change or firmware upload is required.

Check `npm.cmd run test:report-drafts`, the Laravel suite and production build.
This update passed 339 Laravel tests (3623 assertions) using Laragon PHP with
ZIP enabled for exports, and 15 JavaScript draft/navigation/connection tests.
The final Vite build passed and Laravel caches were cleared. Local `/`, `/login`,
`/register`, `/system/version` and the built app JS returned 200; final fingerprint
was `fccfbc46636f2a38`. A guest JSON request to `/reports/drafts` returned 401;
authenticated rendering and civilian-only access passed in isolated feature tests.
The browser fixture helper was updated and syntax-checked; no new physical-phone
or visual-browser run is claimed. After release, sign in as a civilian, leave a
partially filled report, close/reopen, check the badge and continue from Draft
Reports. Check account switching, deletion and confirmed-receipt cleanup. Verify
the badge in both expanded and collapsed navigation on actual pilot phones.

The Render version/login endpoints were again unreachable during these checks.
No public redeploy or deployed-commit parity is claimed. The new civilian page
and matching built assets must be included in the next reviewed deployment.

## Civilian online pilot connection status (2026-11-06)

Deploy the reporting controller/route, civilian form, connection/delivery scripts,
CSS, service worker and rebuilt Vite assets together. The new authenticated,
read-only `/reports/connection` endpoint returns no incident or account details;
the form distinguishes a responding server from browser-only connectivity.
No migration, firmware upload or AI model change is required. See
[civilian questionnaire fit and pilot tasks](CIVILIAN_PILOT_EVALUATION.md).

Use the Laragon PHP executable with PDO MySQL/SQLite, GD, Fileinfo and ZIP enabled;
the unrelated `C:\php\php.exe` on PATH may lack these extensions. From
`C:\laragon\www\TomasOppusRescue-main\web_system`, run the Laravel tests,
`npm.cmd run build` and `php artisan optimize:clear` with the configured PHP.
Verify local `/login`, then the authenticated report form and connection endpoint.
Verify the deployed commit using hosting metadata and repeat the real app-route
checks online before claiming parity; `/system/version` is a file fingerprint,
not a Git revision. The public Render demo was unreachable during this update's
checks; no online rollout or mobile-browser certification is claimed.

The current root `render.yaml` demo uses local **SQLite**, file storage and AI
disabled. Older free-Postgres assumptions do not describe this profile. SQLite
and uploads on the free service are ephemeral; this remains a disposable demo,
not a production deployment.

## Docker PHP extension fix (2026-10-15)

The web Dockerfile shared by Render and Dokploy now installs GD with JPEG and
FreeType support for PhpSpreadsheet. Composer and PHP-FPM use the same PHP 8.3
base and extensions; the build runs `composer check-platform-reqs --no-dev`.
Rebuild the web image to apply this change. No dependency versions or AI flags
change. A successful public deployment still requires app-route verification.

## Text-only AI runtime (2026-10-15)

The FastAPI runtime now serves description-based severity inference only. Its
image `/predict` route, image checkpoint synchronization, image model dependencies,
and Render image settings are removed. Historical image research files remain in
the repository for audit but are excluded from the deployed AI image. Photos and
selfies remain report evidence. The free demo keeps `AI_OPERATION_MODE=MANUAL_ONLY`
and `AI_TEXT_ENABLED=false` until a reviewed text checkpoint is approved.

After deploying the matching commit to both Render services, compare the web
`/system/version` revision with the deployed source, verify `/login`, check AI
`/health` and `/health/text`, and confirm image `/predict` returns 404. Enable
text inference only with a reviewed model artifact, matching `AI_TEXT_MODEL_DIR`,
and `INSTALL_TEXT_AI=true` at build time. A 200 response on the web health route
alone does not establish text model readiness.

## Local dataset cleanup (2026-10-01)

Only `ai_service/datasets/emergency_text/` remains in the local dataset tree.
Legacy image and external tabular datasets were removed; uploaded application
photo evidence and model checkpoints were not part of this cleanup. The AI Docker
context already excludes `datasets`, so this removal does not require a public
redeploy or change runtime flags. Synchronize the documentation with the next
source release. No online deployment or public-route verification is claimed.

## Admin UI rollout (2026-09-19)

The responsive admin update is prepared locally; **the Render demo has not been updated or verified by this UI update**. See [validation and compatibility notes](ADMIN_UI_RESPONSIVE_NOTES.md). Record the exact source commit during release; the public demo's deployed revision has not been verified.

Admin seeding has no committed default credentials. If `RUN_DEMO_SEEDERS=true`, set unique `ADMIN_SEED_EMAIL` and `ADMIN_SEED_PASSWORD` environment variables (password minimum: 12 characters) before release. Without both values, the admin seeder safely skips creating an account.

Deploy the same reviewed Blade/CSS/JS source, `vite.config.js`, and package lockfile. From `web_system/`, install the locked dependencies with `npm ci`, run `npm run build`, then clear Laravel caches with `php artisan optimize:clear` through the normal container/release process. Deploy `public/build/manifest.json` and **all** matching generated assets together, including the separate `resources/css/operations.css` entry, modern polyfills, legacy polyfills, and `resources/js/app-legacy.js`. Keep `resources/views/partials/legacy-assets.blade.php` with that build; updating templates alone can leave stale styling or broken script loaders.

Before claiming localhost/public parity, confirm the release revision or source identity, check `/system/version` and `/login`, and authenticate to `/admin/dashboard` and `/reports` on the public URL. Verify the operations stylesheet and script assets return successfully, phone-width navigation and dialogs work, and the PWA update/reload serves the new assets. A successful version endpoint alone is not sufficient. JavaScript legacy targets do not certify all historical Android/iOS versions; the whole application's Tailwind styling still requires modern browsers as detailed in the linked notes.

The dark-mode follow-up also requires `resources/views/partials/theme-preference.blade.php`, the updated Settings view/script, and `resources/css/admin-dark.css` imported by the operations entry. Verify System/Light/Dark switching and readable tables/dialogs on the public app after rebuilding; local success does not confirm the deployed theme behavior.

## Description-only severity rollout (2026-09-20)

New web/API reports always use description-only assessment. Photos remain evidence;
Laravel image inference and the remote photo gate are retired, including old queued
image jobs. Historical results and photo files remain unchanged. Disabled or unavailable
text inference accepts reports as **Needs review**, with no estimated score.

Text model inference remains disabled in `render.yaml` for both
Laravel and FastAPI. Follow [the text AI workflow](ai_service/TEXT_AI_WORKFLOW.md)
before enabling it. A Docker build with `INSTALL_TEXT_AI=true`, a validated local
model artifact, and independent local/public route verification are required.
No trained text model is automatically downloaded or promoted by this update.

The [September 20 text dataset experiment](ai_service/artifacts/reports/text_synthetic_9000words_20260920/README.md)
adds separate severity/language review folders and local training artifacts. All
292 descriptions are synthetic drafts. Keep its checkpoint local and experimental;
deploying the dataset tooling does not enable text inference. The free-demo AI
flags remain disabled, and no public deployment is performed by this experiment.

The subsequent [review/training workflow update](ai_service/artifacts/reports/text_workflow_20260920/README.md)
moves historical datasets under `experiments/` and `archive/initial_80/`, and uses
`working/reports.csv` for current review. Synchronize the training/review helpers
and their tests/docs with the next source release; do not bake local datasets,
review worksheets or experimental checkpoints into the public demo. This update
does not change deployment flags, the text inference contract or the photo-evidence flow.
The AI Docker context now excludes all `artifacts/checkpoints/` and archived image
research code, so local training candidates cannot be shipped implicitly by `COPY . .`. A later reviewed release
must be supplied explicitly, for example in a separate `artifacts/releases/` path
or a mounted model directory, with its matching `AI_TEXT_MODEL_DIR` setting.

The checked-in blueprint specifies SQLite/local files and retains the text-only AI
service. The image model flags and hosted image checkpoints are removed. Text AI remains explicitly disabled. This demo profile is not
production-safe. Deploy the matching PHP/Blade/JS source and rebuilt assets together,
clear caches, and verify an authorized isolated report flow preserves photo evidence
while showing Needs review. This local change has not redeployed the public app;
check actual deployed configuration and revision before claiming parity.

Restart long-running queue workers with the new release (`php artisan queue:restart`
when using Laravel workers), so already-loaded legacy image handlers cannot continue
running old code. The new compatibility handler consumes old image jobs without
making requests or changing report/evidence records.

Local validation for this rollout: 119 Laravel tests / 1,283 assertions passed,
`npm run build` passed, and Chrome dark-mode checks covered 17 page/dialog states
without detected contrast failures or horizontal overflow. `/login` and
`/system/version` return HTTP 200 locally; `/health/text` confirms inference remains
disabled. Automated checks do not certify every historical phone/browser version.

WebKit 26.5 passed 12 desktop/phone layout checks and the evidence/dialog controls.
Its installed-display simulation passed at 1100, 912, and 390 CSS pixels after the
browser harness was corrected to wait for the visibility transition. No product
change was needed for that test timing issue.

This repository is best deployed as:

1. `Render web service` for Laravel + PWA + Reverb
2. `Render private service` for the FastAPI AI server
3. `Railway MySQL` for the production database
4. `S3-compatible object storage` for evidence uploads

This is the recommended layout for the current codebase because the project already depends on:

- Laravel web + auth + uploads
- Laravel Reverb for realtime updates
- FastAPI + PyTorch for AI inference
- MySQL schemas and migrations

## Recommended Architecture

### Public

- `stitch-web` on Render
  - serves the Laravel web app and installable PWA
  - terminates HTTP/HTTPS
  - proxies websocket traffic to the local Reverb process inside the same container

### Private

- `stitch-ai` on Render private networking
  - serves text inference through `POST /predict/text` when a reviewed model is enabled, with `/health/text` and `/ready/text`
  - has no image prediction endpoint or deployed image checkpoint
  - only Laravel needs to call this service

### External managed services

- `Railway MySQL`
  - stores application data
  - easiest fit for the existing Laravel MySQL schema

- `S3-compatible object storage`
  - stores incident evidence, videos, selfies, and profile photos
  - avoids losing files during container restarts or redeploys

## Why this is the recommended path

- Keeps the current Laravel + Reverb + FastAPI design
- Avoids moving the project to Postgres just for hosting convenience
- Avoids storing uploads on ephemeral container disks
- Keeps AI private instead of exposing it directly to the public internet
- Minimizes code changes versus the current local Laragon setup

## Files added for this plan

- Root Render blueprint: `render.yaml`
- Web service Docker image:
  - `web_system/deploy/render/Dockerfile`
  - `web_system/deploy/render/start-web.sh`
  - `web_system/deploy/render/php.ini`
  - `web_system/deploy/render/nginx.default.conf.template`
- AI service Docker image:
  - `ai_service/deploy/render/Dockerfile`
- Example env files:
  - `web_system/.env.render.example`
  - `ai_service/.env.render.example`

## Step-by-step

### 1. Push the repository to GitHub

Render and Railway are easiest to connect from a GitHub repo.

### 2. Create the MySQL database

Recommended first option: `Railway MySQL`

Create a MySQL database in Railway and collect:

- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`

### 3. Create object storage for uploads

Use an S3-compatible bucket.

Recommended options:

- `Cloudflare R2` for low-cost object storage
- `AWS S3` if you want the most standard setup

Collect:

- `AWS_ACCESS_KEY_ID`
- `AWS_SECRET_ACCESS_KEY`
- `AWS_DEFAULT_REGION`
- `AWS_BUCKET`
- `AWS_ENDPOINT` if using a non-AWS provider
- `AWS_URL` if you want a direct public asset base URL

### 4. Generate the Laravel app key

From `web_system/`:

```bash
php artisan key:generate --show
```

Save the generated `base64:...` value for `APP_KEY`.

### 5. Import the Render blueprint

At Render:

1. Create a new Blueprint
2. Point it to this repository
3. Render will detect the root `render.yaml`

This creates:

- `stitch-web`
- `stitch-ai`

### 6. Fill the required Render secrets

Render will prompt for all `sync: false` values from `render.yaml`.

Minimum required values:

- `APP_URL`
- `APP_KEY`
- `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` (for civilian Google sign-in)
- `GOOGLE_REDIRECT_URI` (the exact public callback URL)
- `DB_HOST`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`
- `AWS_ACCESS_KEY_ID`
- `AWS_SECRET_ACCESS_KEY`
- `AWS_DEFAULT_REGION`
- `AWS_BUCKET`
- `REVERB_APP_KEY`
- `REVERB_APP_SECRET`
- `REVERB_HOST`

### 7. Set the correct public domain values

If using the default Render domain first:

- `APP_URL=https://your-stitch-web-service.onrender.com`
- `REVERB_HOST=your-stitch-web-service.onrender.com`
- `GOOGLE_REDIRECT_URI=https://your-stitch-web-service.onrender.com/auth/google/callback`

If using a custom domain:

- `APP_URL=https://your-domain.com`
- `REVERB_HOST=your-domain.com`
- `GOOGLE_REDIRECT_URI=https://your-domain.com/auth/google/callback`

Register each `GOOGLE_REDIRECT_URI` as an authorized redirect URI on the Google OAuth web client. For local testing, use `http://127.0.0.1:8000/auth/google/callback` and set the credentials in `web_system/.env`; then run `php artisan optimize:clear`. The Google sign-in button on the civilian login page needs these credentials to complete the callback. Keep the client secret out of Git.

Use:

- `REVERB_PORT=443`
- `REVERB_SCHEME=https`

### 8. Deploy

The web container startup script will:

- render the nginx config with the assigned Render `PORT`
- run migrations
- ensure the storage link exists
- start `php-fpm`
- start `Laravel Reverb`
- start `nginx`

### 9. Verify production

Check:

- `GET /system/version`
- login
- registration
- civilian report creation
- evidence upload
- responder live feed
- AI health/readiness from Laravel to FastAPI

## Runtime choices in this setup

### Web service

- `SESSION_DRIVER=database`
- `QUEUE_CONNECTION=sync`
- `FILESYSTEM_DISK=s3`

These are deliberate:

- database sessions survive container restarts better than file sessions
- sync queue keeps the first deployment simpler
- S3 storage prevents evidence loss on redeploys

## Database recommendation

### Best default choice: Railway MySQL

Use this if you want:

- MySQL without changing the current Laravel schema
- simpler setup for the existing repo
- less migration work

### Alternative: Aiven MySQL

Use this if you want:

- a more managed standalone MySQL service
- backups and a stable hosted MySQL product

## Storage recommendation

### Best practical choice

- `Cloudflare R2` if you want cheaper S3-compatible storage
- `AWS S3` if you want the most standard Laravel-compatible path

Do not keep uploads on the app container filesystem in production.

## Notes about Vercel

Vercel is not the best host for the full current stack because this repo depends on:

- Laravel PHP runtime
- Reverb websocket server
- FastAPI AI service
- uploaded media files

If you later want to use Vercel, the cleaner split would be:

- Vercel for a separate frontend
- Render or Railway for Laravel API + Reverb + AI
- external DB + object storage

## Reviewed AI feedback and candidate learning (2026-11-09)

Apply `2026_11_09_000001_create_ai_training_feedback_table.php` with the normal
migrations. Dedicated description reviews now retain a private text/category/model
snapshot and an explicit admin training approval. Routine severity changes alone
are not training permission. The admin must check the anonymous, text-only label
and select its language. Existing reports are not bulk imported into training.

`php artisan ai-learning:run` exports only current approved examples to
`storage/app/private/ai-learning/feedback.jsonl`; its manifest identifies a candidate
pool, not an independent test set. Later corrections, withdrawn approval, edited
description/category, or report deletion remove the old example from the next
snapshot. Keep this directory private, backed up appropriately, and outside Git.

Laravel schedules that command hourly. Laragon's start/status/stop helpers now
manage a portless `laravel-scheduler` (`schedule:work`). Local candidate training
requires `AI_LEARNING_ENABLED=true`, a valid `AI_LEARNING_PYTHON`,
`AI_LEARNING_PROJECT_DIRECTORY`, and `AI_LEARNING_BASE_MODEL`. The start helper
supplies local path defaults from `active_text_config.json` but does not enable
training or change the active release. A separately reviewed holdout path belongs
in `AI_LEARNING_HOLDOUT`; never use the incoming feedback pool as its own test set.

The PHP web image has no Python runtime. Leave `AI_LEARNING_ENABLED=false` there.
`RUN_SCHEDULER=true` starts its export scheduler after migrations; it defaults to
`false` and stays off for the free Render demo, where AI remains disabled and
storage is ephemeral. Do not enable candidate training on the free web container.
For persistent Dokploy hosting, the separate optional `learning` Compose profile
uses a private shared volume and a CPU-limited Python worker. See
`DOKPLOY_DEPLOYMENT.md`; this profile is not deployed by this code update.

Candidate training starts only after enough newly approved examples (default 50).
The 80% target is a measured evaluation gate, alongside category/language coverage
and Critical recall; missing independent holdout data cannot pass. No scheduler,
environment switch, or candidate worker automatically promotes a model. Retain
the current release until its replacement has a separately reviewed evaluation
and release decision. Category preparation does not unlock civilian or LoRa
incident buttons and does not establish that the present road model supports the
other categories. Rebuild and verify both the version endpoint and a real public
application route before describing any online deployment as successful.

Verification on 2026-11-09: localhost `/`, `/login`, `/register`, and
`/system/version` returned 200, and the private text service `/ready/text`
returned 200 with the unchanged road-only v2 release and November cue policy.
The existing local Laravel reporting setting remains `MANUAL_ONLY`; the ordinary
text service stays on port 8100. The subsequent common-vocabulary experiment
configures the separate AI Lab on port 8101, as described below. Local candidate learning is configured and
the scheduler is running. Its first real export contained zero eligible examples,
so the worker reported `waiting_for_reviewed_examples` without fitting a model.

The public Render `/`, `/login`, and `/system/version` returned 200 during this
check after an initial version-request timeout. Its version fingerprint
`6d2336e9391ddf72` differs from localhost `0491606a50f9c143`. These fingerprints
are not Git commits; the endpoint cannot establish deployed branch/commit parity.
The local checkout was `main` at `5add4b9d` with this update still uncommitted.
The feedback/category update has not been deployed online. Dokploy configuration
and shell syntax were checked, but Docker was unavailable for a container run.

### Common-vocabulary experiment: isolated local Lab

The six-category `triage-multiincident-tfidf-20261109-v1` release is selected only
by `test_model_directory` in `ai_service/active_text_config.json`. The Laragon
helper starts it on CPU at `127.0.0.1:8101`; local Laravel's
`AI_TEXT_TEST_SERVICE_URL` points there. The ordinary `model_directory`, port 8100
road-only v2 checkpoint, manual reporting mode, and civilian/LoRa category locks
are unchanged. Do not change the deployed `TEXT_AI_MODEL_DIR` to this candidate.

The requested 80% complete-system target failed. The frozen 144-case synthetic
family test scored 72.92% complete-policy accuracy (73.61% raw classifier); the
separately authored 384-case diagnostic scored 68.49% (83.33% raw). Critical recall
was 69.23% and 50.00%, with five and ten urgent lower assignments respectively.
All labels are AI-authored drafts and deployment approval remains false. See
[the full evaluation](ai_service/artifacts/reports/multiincident_common_20261109/EVALUATION.md).

No deployment of this experiment was performed. The prepared online path keeps
the current live selector; the free Render profile continues with AI disabled,
local ephemeral file storage and free Postgres, and is not production-safe. A
future supervised Lab deployment needs a separately configured private service
and test URL; a validated live replacement still needs a reviewed independent
evaluation and release decision. Successful local readiness is not proof of
online branch/commit parity.

## First future improvement after production

After the first stable deployment, the next upgrade should be:

1. move AI dispatch to `QUEUE_CONNECTION=database`
2. add a Render worker for queued AI jobs
3. optionally replace self-hosted Reverb with a managed realtime provider if you want simpler operations
## Local advisory activation — 2026-11-12

Local `web_system/.env` now explicitly enables the existing road-only v2 model
on 8100 with `AI_TEXT_MODE=MODEL_ADVISORY`, `AI_TEXT_ENABLED=true`, and
`AI_TEXT_ALLOW_EXPERIMENTAL=true`. Admin assessment remains required. The new
multi-incident vocabulary checkpoint is selected only for the local admin Lab
on 8101; it has no operational deployment approval. See
[the evidence](ai_service/releases/triage-multiincident-tfidf-20261112-vocabulary-v1/README.md).

This is a local configuration change, not a public deployment. The checked-in
`render.yaml` continues to disable AI on both services. Its current database is
SQLite (`DB_CONNECTION=sqlite`) with local ephemeral storage; older references
to a free Postgres service describe a preceding plan. The free demo is not
production-safe and has not been declared healthy by this update.

The prepared online path for matching **road-only supervised advisory** remains
`compose.dokploy.yaml`, whose private text service already packages the v2
release and CPU dependencies. Synchronize the intended reviewed source commit,
retain `AI_OPERATION_MODE=AI_ENABLED`, `AI_TEXT_ENABLED=true`,
`AI_TEXT_ALLOW_EXPERIMENTAL=true`, and
`AI_TEXT_MODEL_DIR=/app/releases/triage-text-tfidf-20261016-v2` on the AI service.
The web service needs `AI_TEXT_MODE=MODEL_ADVISORY`, `AI_TEXT_ENABLED=true`,
`AI_TEXT_ALLOW_EXPERIMENTAL=true`, and `AI_TEXT_SERVICE_URL=http://text-ai:8100`.
Rebuild both services and clear/rebuild Laravel configuration. Do not point
ordinary reporting at the experimental multi-incident Lab candidate.

For rollback, set web `AI_TEXT_MODE=MANUAL_ONLY` and `AI_TEXT_ENABLED=false`,
refresh Laravel configuration and recreate the web service. Before describing
any online deployment as successful, compare the deployed branch/commit with
the intended source, verify public `/`, `/login`, `/register`, and
`/system/ai-status`, and exercise an authorized isolated report/admin-review
flow. Readiness alone does not prove localhost/public parity. No online
credentials were used and no public rollout was performed here.
