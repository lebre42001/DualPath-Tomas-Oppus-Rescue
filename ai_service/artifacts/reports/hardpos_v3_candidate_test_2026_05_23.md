# Photo Relevance Gate Evaluation

Date: 2026-05-23 00:39:23
Config: `C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch\ai_service\configs\bontoc_southern_leyte_photo_relevance_phone_augmented_hardpos_v3.yaml`
Experiment: `bontoc_southern_leyte_photo_relevance_phone_augmented_hardpos_v3`
Checkpoint: `C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch\ai_service\artifacts\checkpoints\bontoc_photo_relevance_phone_augmented_hardpos_v3_best.pt`
Manifest: `C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch\ai_service\datasets\bontoc_southern_leyte\metadata\test_photo_relevance_phone_augmented_hardpos_v3_2026_05_23.csv`

## Operational Meaning

- `false_accepts`: dummy or unrelated photos that still pass the gate
- `false_rejects`: real accident/emergency photos that get blocked
- both should be reviewed before promoting a checkpoint

## Summary

- Total evaluated images: `173`
- Missing image paths: `0`
- Label accuracy: `0.9884`
- False accepts: `1`
- False rejects: `2`
- Review-required predictions: `6`

## Confusion Matrix

| true \ predicted | related | unrelated |
| --- | ---: | ---: |
| related | 105 | 2 |
| unrelated | 0 | 66 |

## Source Domain Slices

- `public_non_incident_reference`
  - total: `61`
  - correct: `61`
  - accuracy: `1.0000`
- `road_accident`
  - total: `107`
  - correct: `105`
  - accuracy: `0.9813`
- `ui_reference`
  - total: `5`
  - correct: `5`
  - accuracy: `1.0000`

## Recommendation

- add more unrelated negatives if false accepts are present
- add more real accident scene examples if false rejects are present
- keep this gate stricter than the severity model because dummy photos should not reach responder dispatch as evidence
