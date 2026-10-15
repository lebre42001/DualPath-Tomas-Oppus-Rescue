# Family and historical exposure audit

## Verified family structure

The source reports and provenance each contain 850 unique, matching case IDs and their text/language/label fields match row-by-row. Source family manifest membership matches the provenance family membership for all 833 declared family IDs. The 41 reconciliation candidates remain recommendations: 19 `SAME_SCENARIO_FAMILY`, 22 `RELATED_BUT_DISTINCT`; source IDs and content were not changed.

Independent reconstruction of all named grouping edges (source `group_id`, `scenario_family_id`, annotation group, parent-child relation, normalized exact text, and the 19 SAME recommendations) produces 820 effective groups, matching the frozen current split manifest. Current split: all 850 cases assigned once, 0 declared-family crossings, 0 effective-group crossings. Historical 2026-10-05 split: all 850 assigned once, 0 declared-family crossings, but 3 effective components cross partitions when the later 19 SAME recommendations are applied. Five SAME-recommendation pair edges cross old partitions. The current split manifest records 3 old-crossing components and 4 rows moved to component-majority assignments; its full effective groups are now contained. These AI-assisted semantic links are not proof of real incident identity. Of the 41 candidate pairs, the 10 crossing current partitions are all in the `RELATED_BUT_DISTINCT` set and are intentionally not merged.

All 850 rows have prior model-development assignment in the 2026-10-05 partition scheme: train 516, validation 124, calibration 83, fresh_test 84, challenge 43. The current test is disjoint for its own experiment but is not historically unseen across project model-development work.

## Historical source access and count reconciliation

The prior access audit records 60 restricted CSV paths: 20 in each of `working/by_severity`, `experiments/synthetic_9000words_20260920/by_severity`, and `archive/initial_80/by_severity`. All 60 paths currently exist and expose stat metadata (size and modification time), but all 60 content-open/hash/read attempts return `PermissionError`; no encoding or row count can be observed for them. Three representative read-only `icacls` probes also returned Access is denied without ACL entries. No ACL query through `Get-Acl`, elevation, ownership/ACL change, or bypass was attempted.

The two historical accessible-source counts remain unreconciled. Retained artifacts say the builder scan counted 1,732 nonblank text records, while a separate red-team scan counted 2,708 rows in 17 readable files. The 2,708 scan has no saved per-path inventory, hashes, parsed row counts, or exact scan code/config, so a same-file-identity comparison is impossible. Current reproduction of the builder's recorded rule against the later repository tree finds 76 CSV paths (16 readable, 60 denied), 14 distinct readable SHA-256 file identities, 6,724 parsed data rows across paths (6,244 after collapsing identical-file hashes), 4,974 nonblank text records, and 1,410 punctuation-normalized text keys. This later-tree result is not a reconstruction of either historical snapshot: reviewed/research exports have since been added.

Five ZIP/TAR-family containers were listed under `ai_service`; their central-directory names had zero members matching `by_severity` paths or `examples.csv`. No `.7z` or `.rar` paths were found under `ai_service`. None were extracted. This archive search does not prove that no external backup or inaccessible copy exists elsewhere.

## Files

- `FAMILY_EXPOSURE_AUDIT.json`: machine-readable findings, source hashes, methods and limitations.
- `FAMILY_PAIR_SPLIT_CROSSWALK.csv`: each of 41 candidate pairs in both historical and current partitions.
- `ACCESSIBLE_CSV_INVENTORY.csv`: current scan-scope CSV metadata, hash, encoding/read result and row counts; no text contents.
- `RECORDED_60_FILE_ACCESS_PROBES.csv`: exact previously restricted paths, current metadata and read failures.
- `COMMAND_LOG.txt` / `COMMANDS_AND_LIMITS.md`: commands and outcomes.
- `audit_family_exposure.py`: reproducible standard-library audit.
- `OUTPUTS_SHA256.json`: hashes of the other audit deliverables.

No source dataset, provenance, family IDs, splits, checkpoint, active model/config, access controls, or deployment state was modified. No model training/GPU process was run.
