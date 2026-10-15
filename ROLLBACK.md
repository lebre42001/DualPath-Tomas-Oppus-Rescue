# MANUAL_ONLY release rollback

**This is a prepared rollback procedure, not an executed rollback.** No persistent database or deployed service has been changed.

## Safety rule

Keep text AI explicitly off during every rollback attempt:

```text
AI_TEXT_MODE=MANUAL_ONLY
AI_TEXT_ENABLED=false
```

Keep the FastAPI service in `AI_OPERATION_MODE=MANUAL_ONLY` as well. That gate blocks both image and text model routes, readiness, warmup, startup model preloading, and checkpoint synchronization. Do not rely on `AI_TEXT_ENABLED=false` alone to disable image inference.

Do not roll back to application code that ignores `AI_TEXT_MODE` or restores photo-based severity. Do not convert Critical to Fatal, fill null severity with a guessed value, delete audit history, or restore an old database snapshot over reports received after that snapshot.

## If staging or release verification fails

1. Stop the rollout. Do not apply the migration to another database or promote the candidate image.
2. If the candidate is already serving staging traffic, place that isolated target in maintenance mode or stop intake using its approved operator procedure. Keep AI disabled.
3. Preserve logs and the staging database for diagnosis. Do not run `migrate:rollback` automatically.
4. Either forward-fix the candidate, or restore an application image known to handle nullable severity and MANUAL_ONLY safely. The repository's immediately previous application build has not been proven compatible with null severity and is **not** automatically a safe rollback target.
5. Re-run the focused Laravel tests and frontend build, then repeat isolated staging verification before resuming intake.

## Database migration handling

The new migration only makes `incident_reports.severity` nullable. It does not rewrite existing severity values. Its `down()` method refuses to restore `NOT NULL` while any row has a null severity.

After applying it to staging, keep the nullable schema during an application rollback unless all of the following are true:

- intake has been stopped;
- every null-severity report has been reviewed and assigned a responder-confirmed value through the application;
- the database has a verified restorable backup;
- the application currently running is compatible with the stricter schema;
- the database owner approves the explicit rollback migration.

The migration has **not** been applied to any persistent database in this task. Production execution requires a separate approved maintenance window and backup procedure.

## Recovery from a bad report

Use authenticated responder review to correct a severity and retain the audit entry. Never make a bulk SQL update assigning a severity to all null/pending reports. If a bad source build exposed an unreviewed AI prediction operationally, stop intake, preserve the affected rows and logs, correct through a reviewed compatible release, and conduct a responder audit before resuming.

## Restore verification

Before reopening a staging target, verify:

- AI remains disabled and MANUAL_ONLY is active in the effective runtime configuration;
- AI service `AI_OPERATION_MODE=MANUAL_ONLY` is active even if old image checkpoints or AI flags are present;
- new report intake succeeds with null severity and no inference request;
- a responder can assign severity and the actor/time/prior state are audited;
- Critical remains Critical and historical Fatal remains unchanged;
- unreviewed legacy AI suggestions remain hidden from operational screens, APIs, queue ordering, alerts, counts, and dispatch;
- evidence files and report history remain intact.

No localhost startup, production migration, public deployment, or rollback has been executed for this release.
