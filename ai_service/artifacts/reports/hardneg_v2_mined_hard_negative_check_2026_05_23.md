# Photo Relevance Gate Evaluation

Date: 2026-05-23 00:11:01
Config: `C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch\ai_service\configs\bontoc_southern_leyte_photo_relevance_phone_augmented_hardneg_v2.yaml`
Experiment: `bontoc_southern_leyte_photo_relevance_phone_augmented_hardneg_v2`
Checkpoint: `C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch\ai_service\artifacts\checkpoints\bontoc_photo_relevance_phone_augmented_hardneg_v2_best.pt`
Manifest: `C:\laragon\www\CAPSTONE EMERGENCY SYSTEM\stitch updated\stitch\ai_service\datasets\bontoc_southern_leyte\metadata\hard_negative_mined_check_2026_05_22.csv`

## Operational Meaning

- `false_accepts`: dummy or unrelated photos that still pass the gate
- `false_rejects`: real accident/emergency photos that get blocked
- both should be reviewed before promoting a checkpoint

## Summary

- Total evaluated images: `12`
- Missing image paths: `0`
- Label accuracy: `1.0000`
- False accepts: `1`
- False rejects: `0`
- Review-required predictions: `1`

## Confusion Matrix

| true \ predicted | related | unrelated |
| --- | ---: | ---: |
| related | 0 | 0 |
| unrelated | 0 | 12 |

## Source Domain Slices

- `public_non_incident_reference`
  - total: `12`
  - correct: `12`
  - accuracy: `1.0000`

## Recommendation

- add more unrelated negatives if false accepts are present
- add more real accident scene examples if false rejects are present
- keep this gate stricter than the severity model because dummy photos should not reach responder dispatch as evidence
