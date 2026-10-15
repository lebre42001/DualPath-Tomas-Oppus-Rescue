# Recommended AI Training Workflow

This project should use the existing server-side PyTorch transfer-learning pipeline instead of Teachable Machine as the main production AI.

Teachable Machine is useful for quick classroom demos, but this system needs repeatable manifests, review logs, dummy-photo rejection metrics, and deployable checkpoints.

## Recommended Architecture

Use two models:

1. Photo relevance gate
   - labels: `related`, `unrelated`
   - purpose: block dummy photos, screenshots, selfies, food, room photos, and unrelated uploads
   - active config pointer: `active_photo_relevance_config.txt`

2. Severity classifier
   - labels: `minor`, `serious`, `fatal`
   - purpose: classify only accepted accident/emergency photos
   - active config pointer: `active_config.txt`

The FastAPI `/predict` endpoint already uses this order:

```text
photo relevance gate -> severity classifier -> responder review if confidence is low
```

## Model Options

Available architectures:

```text
resnet18
mobilenet_v3_small
efficientnet_b0
```

Recommended choices:

- `resnet18` for stable current training and compatibility with existing checkpoints
- `mobilenet_v3_small` for a lighter hosted model after retraining
- `efficientnet_b0` if you want stronger image features and can tolerate more memory

## One-Command Workflow

Stable current profile:

```powershell
pwsh -File .\tool\run_ai_recommended_training_workflow.ps1 -Profile stable-resnet
```

Lightweight MobileNet profile:

```powershell
pwsh -File .\tool\run_ai_recommended_training_workflow.ps1 -Profile lightweight-mobilenet
```

Only promote after manually reviewing the generated reports:

```powershell
pwsh -File .\tool\run_ai_recommended_training_workflow.ps1 -Profile stable-resnet -PromoteActiveConfigs
```

## Dummy-Photo Gate Evaluation

Run this after training the relevance model:

```powershell
cd .\ai_service
C:\laragon\bin\python\python-3.13\python.exe .\tools\evaluate_photo_relevance_gate.py
```

Main metric to watch:

- `false_accepts` should be `0` or very close to `0`

If false accepts happen, add more unrelated negatives such as:

- normal selfies
- room photos
- food
- pets
- screenshots
- app UI photos
- random vehicle photos with no accident

## Two-Stage Local Test

Run the same flow as the API on a folder of sample images:

```powershell
cd .\ai_service
C:\laragon\bin\python\python-3.13\python.exe .\tools\two_stage_prediction_test.py --input .\datasets\bontoc_southern_leyte\local_validation
```

This reports:

- accepted vs rejected
- relevance label/confidence
- severity label/confidence
- responder review flag

By default, the severity model is not allowed to override a rejected photo relevance gate result. This is safer for dummy-photo protection. If the relevance gate rejects real accident photos, retrain the relevance gate with reviewed examples instead of enabling override.

## Promotion Checklist

Before deploying a new checkpoint:

- photo relevance false accepts are acceptable, ideally `0`
- fatal recall is strong
- motorcycle/rural/night/rain slices are reviewed
- low-confidence cases still go to responder review
- checkpoint file is uploaded to Render-compatible storage
- Render env points to the same config and checkpoint pair

## Render Notes

For online deployment, these values must match:

```env
AI_SERVICE_CONFIG=configs/bontoc_southern_leyte_production_candidate_external.yaml
AI_PHOTO_RELEVANCE_CONFIG=configs/bontoc_southern_leyte_photo_relevance.yaml
AI_RELEVANCE_SEVERITY_OVERRIDE_ENABLED=false
AI_SEVERITY_CHECKPOINT_URL=https://...
AI_PHOTO_RELEVANCE_CHECKPOINT_URL=https://...
```

If you promote MobileNet configs, upload the matching MobileNet checkpoints too. Do not point Render to a MobileNet config while using an old ResNet checkpoint.
