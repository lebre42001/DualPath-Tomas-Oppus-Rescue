# Retraining Prep Summary

- config: `C:/laragon/www/CAPSTONE EMERGENCY SYSTEM/stitch updated/stitch/ai_service/configs/bontoc_southern_leyte_severity.yaml`
- staging root: `C:/laragon/www/CAPSTONE EMERGENCY SYSTEM/stitch updated/stitch/ai_service/datasets/bontoc_southern_leyte/import_staging/accident_images_analysis_dataset`
- candidate split plan: `C:/laragon/www/CAPSTONE EMERGENCY SYSTEM/stitch updated/stitch/ai_service/datasets/bontoc_southern_leyte/metadata/retraining_candidate_split_plan_accident_images_analysis_dataset.csv`

## Existing curated counts

### train
- minor: `1`
- serious: `1`
- fatal: `0`

### val
- minor: `1`
- serious: `1`
- fatal: `0`

### test
- minor: `1`
- serious: `1`
- fatal: `0`

## Suggested staged counts

### train
- minor: `75`
- serious: `1129`
- fatal: `860`

### val
- minor: `13`
- serious: `233`
- fatal: `174`

### test
- minor: `30`
- serious: `241`
- fatal: `191`

## Projected counts after approved merge

### train
- minor: `76`
- serious: `1130`
- fatal: `860`

### val
- minor: `14`
- serious: `234`
- fatal: `174`

### test
- minor: `31`
- serious: `242`
- fatal: `191`

## Next steps

1. Run the staging review helper.
2. Fill the QA review CSV for approved and rejected samples.
3. Merge only approved images into curated train, val, and test folders.
4. Update the main train, val, and test manifests.
5. Retrain the model with the standard training config.