# Laravel Integration Plan

This plan wires the Laravel report flow to the Python AI severity service so uploaded civilian evidence can be analyzed automatically.

## Goal

When a civilian submits an online report with a photo:

1. Laravel stores the report and evidence file
2. Laravel forwards the uploaded image to the AI service
3. The AI service returns `severity`, `confidence`, and `responder_review_required`
4. Laravel updates the report record
5. The web dashboard and responder flow display the AI result

## Recommended Integration Scope

Start with photo-based inference only.

- Supported in v1:
  - online civilian reports
  - image evidence only
- Deferred to v2:
  - video frame extraction
  - LoRa compact payload inference
  - text-plus-image multimodal fusion

## Laravel Files To Add

- `web_system/app/Services/AiSeverityClient.php`
- `web_system/app/Jobs/AnalyzeIncidentEvidence.php`
- `web_system/app/Support/AiSeverityMapper.php`

## Laravel Files To Update

- `web_system/app/Http/Controllers/Api/IncidentReportApiController.php`
- `web_system/app/Models/IncidentReport.php`
- `web_system/config/services.php`
- `web_system/.env.example`
- `web_system/resources/views/reports/show.blade.php`
- `web_system/resources/views/reports/index.blade.php`
- `web_system/resources/js/stitch-dashboard.js`

## Environment Variables

Add these values to Laravel:

```env
AI_SEVERITY_SERVICE_URL=http://127.0.0.1:8100
AI_SEVERITY_TIMEOUT=20
AI_SEVERITY_ENABLED=true
AI_SEVERITY_MODEL_NAME=bontoc_southern_leyte_severity_baseline
```

## Database Additions

Extend `incident_reports` with AI tracking fields:

- `ai_source`
  Example: `python_model`, `description_fallback`
- `ai_model_name`
- `ai_model_version`
- `ai_review_required`
- `ai_probabilities`
  Store raw class probabilities as JSON
- `ai_processed_at`
- `ai_error_message`

## Request Flow

### 1. Report submission

In `IncidentReportApiController`:

- keep current report creation flow
- detect if:
  - transmission is `online`
  - evidence exists
  - evidence type is `photo`
- dispatch `AnalyzeIncidentEvidence` after saving the report

### 2. AI job

In `AnalyzeIncidentEvidence`:

- load the report and stored photo path
- open the photo from Laravel storage
- send multipart request to `POST /predict`
- parse:
  - `severity`
  - `confidence`
  - `probabilities`
  - `responder_review_required`
- update the report

### 3. Severity mapping

Normalize AI output into Laravel-safe values:

- `minor` -> `Minor`
- `serious` -> `Serious`
- `fatal` -> `Fatal`

### 4. Fallback behavior

If AI service fails:

- keep report submission successful
- do not block the civilian flow
- preserve the current rule-based description summary
- mark:
  - `ai_source=description_fallback`
  - `ai_error_message=<failure details>`

## UI Updates

### Report details page

Show:

- AI severity
- confidence percentage
- model name
- `Needs responder review` badge if low confidence

### Report list and dashboards

Show:

- AI severity badge
- confidence tooltip or sublabel
- low-confidence review badge

### Realtime monitoring

Broadcast the final AI-updated severity after the job finishes so responder dashboards refresh automatically.

## Queue Strategy

Use a queued job so upload response time stays fast.

- report save: immediate
- AI analysis: async
- dashboard update: after AI result saved

If queue workers are offline:

- show the report immediately
- mark AI status as pending until job processing resumes

## Suggested API Contract

Python AI service request:

- method: `POST`
- path: `/predict`
- body: multipart form with file field

Python AI service response:

```json
{
  "filename": "sample.jpg",
  "severity": "serious",
  "confidence": 0.8421,
  "probabilities": {
    "minor": 0.12,
    "serious": 0.84,
    "fatal": 0.04
  },
  "responder_review_required": false,
  "responder_review_action": "needs_responder_review"
}
```

## Implementation Order

1. Add Laravel env config and AI service client
2. Add DB migration for AI metadata fields
3. Add queued job for photo inference
4. Update `IncidentReportApiController` to dispatch the job
5. Update responder web UI to show confidence and review badge
6. Broadcast report updates after AI analysis completes
7. Add tests for success, timeout, and fallback scenarios

## Testing Plan

### Laravel feature tests

- report submit with photo dispatches AI job
- successful AI response updates severity and confidence
- AI timeout falls back without breaking submission
- low-confidence response sets `ai_review_required=true`

### Manual integration test

1. Start Laravel
2. Start the AI FastAPI service
3. Submit a civilian report with a photo
4. Confirm the report initially appears
5. Confirm AI severity and confidence update after the job runs
6. Confirm responder dashboard receives the updated severity

## Important Note

With the current bootstrap model:

- training data is very small
- `fatal` has zero real samples
- current metrics are only a pipeline smoke test

So Laravel should treat the AI result as assistive only until the Bontoc dataset grows substantially.
