# Online Deployment Plan

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
The AI Docker context now excludes `artifacts/checkpoints/text_*` so local training
candidates cannot be shipped implicitly by `COPY . .`. A later reviewed release
must be supplied explicitly, for example in a separate `artifacts/releases/` path
or a mounted model directory, with its matching `AI_TEXT_MODEL_DIR` setting.

The checked-in blueprint specifies SQLite/local files and retains the existing AI
service, but Laravel's `AI_SEVERITY_ENABLED` and `AI_SEVERITY_REQUIRE_CIVILIAN_PHOTO_GATE`
are now false. Text AI remains explicitly disabled. This demo profile is not
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
  - retains legacy image endpoints for archived experiments; normal reporting does not call them
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

If using a custom domain:

- `APP_URL=https://your-domain.com`
- `REVERB_HOST=your-domain.com`

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

## First future improvement after production

After the first stable deployment, the next upgrade should be:

1. move AI dispatch to `QUEUE_CONNECTION=database`
2. add a Render worker for queued AI jobs
3. optionally replace self-hosted Reverb with a managed realtime provider if you want simpler operations
