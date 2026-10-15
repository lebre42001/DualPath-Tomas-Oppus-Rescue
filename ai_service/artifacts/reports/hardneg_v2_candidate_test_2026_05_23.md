# Photo Relevance Gate Evaluation

Date: 2026-05-23 00:11:13
Config: `C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch\ai_service\configs\bontoc_southern_leyte_photo_relevance_phone_augmented_hardneg_v2.yaml`
Experiment: `bontoc_southern_leyte_photo_relevance_phone_augmented_hardneg_v2`
Checkpoint: `C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch\ai_service\artifacts\checkpoints\bontoc_photo_relevance_phone_augmented_hardneg_v2_best.pt`
Manifest: `C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch\ai_service\datasets\bontoc_southern_leyte\metadata\test_photo_relevance_phone_augmented_hardneg_v2_2026_05_22.csv`

## Operational Meaning

- `false_accepts`: dummy or unrelated photos that still pass the gate
- `false_rejects`: real accident/emergency photos that get blocked
- both should be reviewed before promoting a checkpoint

## Summary

- Total evaluated images: `167`
- Missing image paths: `0`
- Label accuracy: `0.9701`
- False accepts: `0`
- False rejects: `0`
- Review-required predictions: `11`

## Confusion Matrix

| true \ predicted | related | unrelated |
| --- | ---: | ---: |
| related | 96 | 5 |
| unrelated | 0 | 66 |

## Source Domain Slices

- `public_non_incident_reference`
  - total: `61`
  - correct: `61`
  - accuracy: `1.0000`
- `road_accident`
  - total: `101`
  - correct: `96`
  - accuracy: `0.9505`
- `ui_reference`
  - total: `5`
  - correct: `5`
  - accuracy: `1.0000`

## Recommendation

- add more unrelated negatives if false accepts are present
- add more real accident scene examples if false rejects are present
- keep this gate stricter than the severity model because dummy photos should not reach responder dispatch as evidence
