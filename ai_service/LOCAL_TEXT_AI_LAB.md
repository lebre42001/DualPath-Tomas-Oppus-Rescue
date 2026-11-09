# Isolated local text AI Lab

The admin text AI Lab uses a separate experimental checkpoint on
**`127.0.0.1:8101`**. The ordinary service on **8100** retains the road-only TF-IDF
v2 model; Laravel reporting remains **`MANUAL_ONLY`**. Lab requests do not create
incident reports, update operational severity records, or unlock reporting categories.

## Configured experiment — 2026-11-09

The configured `test_model_directory` in `active_text_config.json` is
`releases/triage-multiincident-tfidf-20261109-v1`. It is relative to this checkout's
`ai_service` directory and contains `metadata.json`. Existing ordinary `mode`
and `model_directory` settings remain unchanged.

This Lab release supports `road_accident`, `fire_incident`, `flood_landslide`,
`medical_emergency`, `security_incident`, and `other`. Training and inference use
the same `incident_type_description_v1` category-plus-description features. Its
opt-in cue profile is `multiincident_common_v1`, version
`multiincident-cues-20261109-v4`. The selected unaugmented fit uses 1,068 training,
308 validation and 144 family-separated test descriptions in English, Tagalog,
Cebuano/Bisaya and mixed language. Context augmentation was tried but not selected.

**The requested 80% target failed.** Actual final recorded results
(`final_diagnostic_v2`) are:

| Diagnostic set | Raw accuracy | Full-policy accuracy | Critical recall | Urgent cases assigned a lower category |
| --- | ---: | ---: | ---: | ---: |
| Family-separated test, 144 examples | 73.6111% | 72.9167% | 69.2308% | 5 |
| Separate `fresh_v2`, 384 examples | 83.3333% | 68.4896% | 50.0000% | 10 |

Both sets use synthetic draft labels without human approval. The raw
classifier's 83.3333% on the separate set is not the full system's accuracy.
These sets are now exposed diagnostic evidence, not new independent release
holdouts. Human-review and deployment-approval flags remain false. This candidate
is not approved for ordinary reporting or dispatch; no clinical-safety or
real-report accuracy claim follows from a running Lab. No further training is
part of this wrap-up. See
[the complete evaluation](artifacts/reports/multiincident_common_20261109/EVALUATION.md).

## Starting and checking the Lab

From `C:\laragon\www\TomasOppusRescue-main`, use the usual startup helper:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tool\start_stitch_services.ps1
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tool\status_stitch_services.ps1
```

A configured Lab uses `ai_service/.venv-text/Scripts/python.exe`, sets the Lab-only `AI_TEXT_TEST_MODEL_DIR`, and starts `serve_text_test.py` independently of ordinary reporting mode. The process defaults to CPU. `AI_TEXT_TEST_DEVICE` may explicitly select `cpu` or `cuda`; `AI_TEXT_TEST_RULES_ENABLED` defaults to `true` for the configured Lab. These settings are applied in the separate child process and do not change the ordinary model selector or Laravel's current `MANUAL_ONLY` reporting mode.

The startup helper defaults `AI_TEXT_TEST_SERVICE_URL` to `http://127.0.0.1:8101` when a Lab model is configured. If Laravel's local `.env` or a running PHP process already has a different test URL, set its `AI_TEXT_TEST_SERVICE_URL` to that address, clear Laravel's config cache, and restart the affected web process as needed. Leave `AI_TEXT_SERVICE_URL` for ordinary reporting at its intended live address. Do not infer that an already-running web process received new environment variables merely because the startup helper was rerun.

Verify the actual loaded Lab checkpoint, supported categories and rule profile/version through `http://127.0.0.1:8101/ready/text`; `/health/text` alone only checks configuration. Startup and status show a separate Lab readiness line when `test_model_directory` is configured. Open `http://127.0.0.1:8000/admin/text-ai-test` with an admin account to submit fictional descriptions and selected categories. A ready response confirms loading, not that the 80% target passed.

Explicit checkpoint paths must remain within this checkout's `ai_service` directory after resolving directory links. Missing directories or metadata produce a configuration error. Without `AI_TEXT_TEST_MODEL_DIR`, `serve_text_test.py` preserves its historical all-rows XLM-R checkpoint and CUDA setting; without `test_model_directory`, the startup helper preserves its existing legacy/runtime selection behavior.

Starting the isolated Lab is an experimental preview, not a reviewed model release. Actual category support, rule profile/version, and experimental status come from readiness and artifact metadata. Diagnostic accuracy must identify its dataset and limits; this setup does not grant human review or independently validated field accuracy.
