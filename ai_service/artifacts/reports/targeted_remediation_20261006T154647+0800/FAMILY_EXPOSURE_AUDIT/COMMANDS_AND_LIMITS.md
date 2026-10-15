# Family and prior-exposure audit

Read-only audit. Exact Python command:

```powershell
& "C:\laragon\bin\python\python-3.13\python.exe" "ai_service\artifacts\reports\targeted_remediation_20261006T154647+0800\FAMILY_EXPOSURE_AUDIT\audit_family_exposure.py"
```

The script reads the source report/provenance/family files, old and current split manifests, frozen reconciliation and historical access reports, and readable CSVs under `ai_service/datasets/emergency_text`. It writes outputs only to this directory. It does not elevate, query ACLs with PowerShell `Get-Acl`, alter ACL/owner/security, or run model/GPU code. CSVs not readable are not bypassed. It lists `ai_service` archive central directories and matching member names only; nothing is extracted or read from members. Three read-only `icacls` probes returned exit 5 / Access is denied without ACL entries.

The historical 1,732 and 2,708 figures cannot be reconciled from retained evidence because the 2,708 scan lacks a saved per-file path/hash/parsed-row crosswalk and exact scan code. The current scan is explicitly a later-tree reproduction and cannot replace that historical missing manifest.
