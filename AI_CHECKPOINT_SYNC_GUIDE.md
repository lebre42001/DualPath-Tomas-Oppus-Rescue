# AI Checkpoint Sync Guide

Use this guide when deploying the AI service online and the `.pt` checkpoint files are too large or too sensitive to commit directly into Git.

## Current Local Checkpoints

The active AI service needs two checkpoint files:

| Purpose | Local file | SHA256 |
| --- | --- | --- |
| Severity classifier | `ai_service/artifacts/checkpoints/bontoc_severity_production_candidate_external_best.pt` | `c7acb7f39384c6f87a5e1dcfeb33f9d62e435059010d0ad3639dcdd46649abbb` |
| Photo relevance / dummy-photo gate | `ai_service/artifacts/checkpoints/bontoc_photo_relevance_phone_augmented_hardpos_v3_best.pt` | `bf0284f826e28d782e42918a73a8618b51144f9c34bdbd3b9dd5c5ae2561c000` |

## What The Startup Sync Does

When `ai_service/serve_api.py` starts, it now:

1. Reads the active severity config.
2. Reads the active photo relevance config.
3. Checks if each checkpoint file already exists and is a real checkpoint.
4. Downloads missing checkpoints from env-provided URLs when available.
5. Verifies SHA256 when a checksum env is provided.
6. Starts FastAPI only after the sync attempt is complete.

This lets Render, R2, S3, or GitHub Releases provide the `.pt` files without storing large binaries in the normal source code history.

## Render Environment Variables

Set these on the Render `stitch-ai` service:

```text
AI_CHECKPOINT_SYNC_ENABLED=true
AI_CHECKPOINT_SYNC_REQUIRED=false
AI_CHECKPOINT_FORCE_DOWNLOAD=false
AI_CHECKPOINT_DOWNLOAD_TIMEOUT=120

AI_SEVERITY_CHECKPOINT_URL=<public-or-presigned-url-to-bontoc_severity_production_candidate_external_best.pt>
AI_SEVERITY_CHECKPOINT_SHA256=c7acb7f39384c6f87a5e1dcfeb33f9d62e435059010d0ad3639dcdd46649abbb

AI_PHOTO_RELEVANCE_CONFIG=configs/bontoc_southern_leyte_photo_relevance_phone_augmented_hardpos_v3.yaml
AI_PHOTO_RELEVANCE_CHECKPOINT_URL=<public-or-presigned-url-to-bontoc_photo_relevance_phone_augmented_hardpos_v3_best.pt>
AI_PHOTO_RELEVANCE_CHECKPOINT_SHA256=bf0284f826e28d782e42918a73a8618b51144f9c34bdbd3b9dd5c5ae2561c000
```

If the checkpoint files are committed into Git for a temporary demo build, the URL envs can stay blank.

## Recommended Storage Options

Best for demo:

- GitHub Releases with public assets.
- Render can download from the release asset URL during startup.

Best for production:

- Cloudflare R2 or AWS S3 with presigned HTTPS URLs.
- Keep the bucket private.
- Rotate presigned URLs if exposed.

Fastest temporary route:

- Commit only the two selected `.pt` files for a capstone demo branch.
- This is simple but increases Git history size.
- Move them to R2/S3 later.

## Verify Online

After deployment, open:

```text
https://<ai-service-or-web-proxy>/health
```

The important fields should be:

```json
{
  "checkpoint_ready": true,
  "photo_relevance_checkpoint_ready": true
}
```

If either field is false, `/predict` will not fully use the trained AI models.

## Local Check

From the repo root:

```powershell
cd "C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch\ai_service"
C:\laragon\bin\python\python-3.13\python.exe serve_api.py
```

Expected startup logs include:

```text
[checkpoint-sync] severity
[checkpoint-sync] photo_relevance
```

Then check:

```powershell
Invoke-RestMethod http://127.0.0.1:8100/health
```
