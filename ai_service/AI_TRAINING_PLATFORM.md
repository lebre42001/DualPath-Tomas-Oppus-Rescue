# AI Training Platform Used

## What Platform Is Used?

This system uses a **PyTorch-based transfer learning pipeline** for AI training.

Main technologies:

- **PyTorch**: main deep learning framework
- **TorchVision**: image model library and image transforms
- **ResNet18**: current stable image classification model
- **MobileNetV3 Small**: lightweight candidate model for future hosted/mobile-friendly AI
- **EfficientNet-B0**: optional stronger image model candidate
- **FastAPI**: serves the trained AI model through an API
- **Laravel**: sends civilian uploaded photos to the AI service during report submission

## What The AI Trains

The AI is trained in two stages:

1. **Photo relevance screening**
   - labels: `related`, `unrelated`
   - purpose: reject dummy photos, screenshots, selfies-only, room photos, food photos, and unrelated uploads

2. **Severity classification**
   - labels: `minor`, `serious`, `fatal`
   - purpose: classify accepted emergency photos based on visual severity

## Why PyTorch Is Suitable For This System

PyTorch is suitable because the system needs more control than simple no-code tools.

It supports:

- reviewed dataset manifests
- custom labels for emergency severity
- dummy-photo rejection
- confidence thresholds
- responder review fallback
- local validation testing
- model checkpoints for online deployment
- integration with the existing Laravel and Render setup

This is important because the system is for emergency reporting, where wrong AI behavior can affect triage decisions.

## Why Not Use Teachable Machine As The Main AI?

Teachable Machine is good for quick demos and prototypes, but it is not the best main training platform for this system.

Limitations:

- limited control over training settings
- limited dataset auditing
- harder to track reviewed vs unreviewed images
- weaker workflow for local validation
- less suitable for strict dummy-photo rejection
- harder to integrate with production server-side AI checkpoints

Teachable Machine can still be used for a classroom demo, but not as the main AI pipeline.

## Why Not Switch To TensorFlow?

TensorFlow can also train image models, but switching is not necessary right now because the current PyTorch pipeline is already integrated with the system.

The current PyTorch setup already supports:

- training
- prediction API
- Render deployment
- Laravel photo analysis
- dummy photo gate
- severity classification
- local validation reports

Switching to TensorFlow would add extra migration work without a clear advantage for the current capstone stage.

## Best Suitable Choice

For this system, the best suitable AI training approach is:

```text
PyTorch + TorchVision transfer learning
```

Recommended model path:

```text
Photo relevance gate: ResNet18 or MobileNetV3 Small
Severity classifier: ResNet18 stable first, MobileNetV3 Small later if hosting memory needs are tighter
```

## Simple Explanation For Presentation

The system uses a PyTorch-based transfer learning pipeline to train image classification models. The first model checks whether the uploaded photo is a real accident or emergency scene, while the second model classifies the severity as minor, serious, or fatal. PyTorch was chosen because it gives more control over datasets, validation, confidence thresholds, and server deployment than no-code tools such as Teachable Machine.

## Current Status

Current stable online AI:

- framework: PyTorch
- model family: TorchVision ResNet18
- API service: FastAPI
- app integration: Laravel emergency report submission
- hosted demo: Render

Additional supported candidates:

- MobileNetV3 Small
- EfficientNet-B0

These candidates can be trained later and promoted only if validation results improve.
