# Photo Relevance Gate Evaluation

Date: 2026-05-23 00:39:59
Config: `C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch\ai_service\configs\bontoc_southern_leyte_photo_relevance_phone_augmented_hardpos_v3.yaml`
Experiment: `bontoc_southern_leyte_photo_relevance_phone_augmented_hardpos_v3`
Checkpoint: `C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch\ai_service\artifacts\checkpoints\bontoc_photo_relevance_phone_augmented_hardpos_v3_best.pt`
Manifest: `C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch\ai_service\datasets\bontoc_southern_leyte\metadata\phone_upload_test_photo_relevance_holdout_2026_05_23.csv`

## Operational Meaning

- `false_accepts`: dummy or unrelated photos that still pass the gate
- `false_rejects`: real accident/emergency photos that get blocked
- both should be reviewed before promoting a checkpoint

## Summary

- Total evaluated images: `61`
- Missing image paths: `0`
- Label accuracy: `0.9672`
- False accepts: `0`
- False rejects: `0`
- Review-required predictions: `7`

## Confusion Matrix

| true \ predicted | related | unrelated |
| --- | ---: | ---: |
| related | 33 | 2 |
| unrelated | 0 | 26 |

## Source Domain Slices

- `public_non_incident_reference`
  - total: `26`
  - correct: `26`
  - accuracy: `1.0000`
- `road_accident`
  - total: `35`
  - correct: `33`
  - accuracy: `0.9429`

## Recommendation

- add more unrelated negatives if false accepts are present
- add more real accident scene examples if false rejects are present
- keep this gate stricter than the severity model because dummy photos should not reach responder dispatch as evidence
