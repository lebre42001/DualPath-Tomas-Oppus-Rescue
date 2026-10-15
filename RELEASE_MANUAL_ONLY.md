# MANUAL_ONLY application release

**Release state: prepared, not staged or deployed.** No persistent database migration, local server startup, or public deployment has been performed. The Render demo is known to return errors on application pages; do not treat it as a verification target until it is repaired and checked.

## Behavior in this release

- `AI_TEXT_MODE=MANUAL_ONLY` takes precedence over `AI_TEXT_ENABLED` in Laravel. The FastAPI AI service separately defaults to and is explicitly configured with `AI_OPERATION_MODE=MANUAL_ONLY`, which gates both image and text inference, readiness, warmup, startup preload, and checkpoint synchronization. Both service-level settings fail closed; `AI_ENABLED` requires explicit opt-in.
- The AI service liveness check uses `/health` so the manual-only service stays healthy while `/ready` correctly returns 503 to indicate that inference is intentionally unavailable.
- New web and API reports are accepted without calling the text inference service. Severity stays null until an authenticated responder assigns it.
- Responder severity changes are audited with the actor and previous/new values. A prior unreviewed text-model suggestion is retained in a responder-only history area and labeled non-operational.
- Legacy unreviewed text-model values do not affect live severity projections, severity filters, queue priority, dashboard counts, alert lists, notifications, or dispatch recommendations.
- `Critical` and legacy `Fatal` remain separate stored labels. There is no new Critical-to-Fatal conversion.
- Photos remain evidence. The retired image-severity path is not enabled by this release.

## Files changed for this release

Application/configuration:

- `web_system/.dockerignore`
- `web_system/.env.example`
- `web_system/.env.render.example`
- `web_system/config/services.php`
- `render.yaml`
- `ai_service/src/ai_service/operation_mode.py`
- `ai_service/src/ai_service/api.py`
- `ai_service/src/ai_service/text_api.py`
- `ai_service/src/ai_service/text_inference.py`
- `ai_service/serve_api.py`
- `ai_service/tests/test_operation_mode.py`
- `ai_service/README.md`
- `web_system/app/Events/IncidentFeedUpdated.php`
- `web_system/app/Http/Controllers/Api/DashboardApiController.php`
- `web_system/app/Http/Controllers/Api/IncidentReportApiController.php`
- `web_system/app/Http/Controllers/Web/AdminDashboardController.php`
- `web_system/app/Http/Controllers/Web/CivilianAccountController.php`
- `web_system/app/Http/Controllers/Web/DashboardController.php`
- `web_system/app/Http/Controllers/Web/IncidentReportController.php`
- `web_system/app/Http/Controllers/Web/MonitoringDashboardController.php`
- `web_system/app/Models/IncidentReport.php`
- `web_system/app/Services/AiTextSeverityClient.php`
- `web_system/app/Support/AiSeverityMapper.php`
- `web_system/database/migrations/2026_03_30_090100_expand_incident_reports_for_mobile_workflow.php`
- `web_system/database/migrations/2026_10_06_000000_make_incident_severity_nullable_for_manual_review.php`
- `web_system/resources/views/admin/dashboard.blade.php`
- `web_system/resources/views/dashboard.blade.php`
- `web_system/resources/views/reports/create.blade.php`
- `web_system/resources/views/reports/index.blade.php`
- `web_system/resources/views/reports/partials/admin-report-modal-card.blade.php`
- `web_system/resources/views/reports/severity.blade.php`
- `web_system/resources/views/reports/show.blade.php`
- `web_system/resources/views/reports/success.blade.php`

Focused tests:

- `web_system/tests/Feature/ApiMobileFlowTest.php`
- `web_system/tests/Feature/AuthAndReportFlowTest.php`
- `web_system/tests/Feature/DescriptionSeverityDashboardTest.php`
- `web_system/tests/Feature/TextSeverityIntegrationTest.php`

Release procedure and rollback notes:

- `RELEASE_MANUAL_ONLY.md`
- `ROLLBACK.md`

## Validation evidence

The following completed successfully after the final application edits. PHPUnit used `DB_CONNECTION=sqlite` with `DB_DATABASE=:memory:` from `web_system/phpunit.xml`.

```text
php artisan test --filter='TextSeverityIntegrationTest|DescriptionSeverityDashboardTest'
39 tests passed, 316 assertions

php artisan test --filter='ApiMobileFlowTest|AuthAndReportFlowTest'
76 tests passed, 951 assertions

npm run build
Vite production build passed
```

The Vite build reported that `caniuse-lite` data is six months old; this did not fail the build. PHP syntax checks also passed for changed PHP code, migrations, and tests. These checks do not verify persistent database compatibility, localhost behavior, or public deployment.

## Staging procedure

1. Establish a reviewed source identity first. This workspace has no Git metadata, so the exact commit/revision cannot be confirmed from this copy alone.
2. Prepare an isolated staging database and take a restorable backup/snapshot. Confirm its schema and migration state before applying anything.
3. Set Laravel `AI_TEXT_MODE=MANUAL_ONLY` and `AI_TEXT_ENABLED=false`; set AI-service `AI_OPERATION_MODE=MANUAL_ONLY` and `AI_TEXT_ENABLED=false`. Do not point staging at a production database, uploads bucket, or production AI endpoint.
4. Build from the reviewed source with locked dependencies. The existing release convention is the Render Docker build: it uses `web_system/deploy/render/Dockerfile`, `composer.lock`, and `package-lock.json`. That Docker build has **not** been run for this release.
5. Apply migration `2026_10_06_000000_make_incident_severity_nullable_for_manual_review` to the isolated staging database only. Verify the resulting severity column accepts null and verify no existing rows were rewritten.
6. Deploy to an isolated staging target, then verify intake with AI enabled at the configuration layer still sends no text request, leaves severity pending, and allows authenticated responder review with an audit record.
7. Verify legacy unreviewed text-model rows are suppressed from operational API/UI projections, queues, alerts, counts, and dispatch, while the restricted audit page remains responder-only.
8. Only after staging passes should an authorized operator schedule a separate production rollout with a production backup and migration window.

The MANUAL_ONLY source bundle is [manual_only_release_source_20261006T154647+0800.zip](ai_service/artifacts/packages/manual_only_release_source_20261006T154647+0800.zip), with its external integrity receipt beside it. The archive contains the Laravel and FastAPI source contexts, lockfiles, Render configuration, focused tests, and release docs. It excludes local `.env` secrets, user uploads, local databases, datasets, model checkpoints, virtual environments, and Hugging Face caches. Its internal file manifest and ZIP integrity check passed. This is a **source-only** release bundle: the Docker image build, persistent database migration, localhost smoke test, and isolated staging release were not run. Render's Dockerfile is still the deployment convention. `public/build/` is generated and ignored by Git; the Dockerfile creates a fresh frontend build, and `web_system/.dockerignore` now excludes local build output.

## Secret and personal-data handling

`web_system/.dockerignore` excludes `.env` and `.env.*`, `vendor/`, `node_modules/`, generated `public/build/`, local SQLite/database files, storage keys, private/public uploaded storage, test files, and local runtime directories from the Docker build context. `.env.example` and `.env.render.example` contain configuration names/defaults only; do not replace them with live values. No personal data, user uploads, credentials, checkpoints, or AI datasets are included in a release archive because no archive was made.

The current free-demo Render profile uses local SQLite/files and demo seeders; it remains unsuitable for production. The public demo has not been updated or verified by this release.
