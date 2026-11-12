# Category-isolated draft training corpus — 2026-11-12

`new_scenarios.json` contains 48 fictional scene families with four authored
language descriptions each: **192 new training descriptions**, balanced across
six categories and four provisional severity labels. All are synthetic drafts,
not collected incidents or human-approved clinical labels. Related injury and
danger concepts can overlap with earlier synthetic corpora. Translations have
not received fluent-language or responder review.

The reproducible builder `tools/train_category_experts.py` retains the frozen
training/validation/test assignments from the historical 1,366-row road corpus
and 1,520-row multi-incident corpus. Only their training partitions plus the new
scenes enter fitting. It creates an immutable derived `source/`, then adds four
neutral location-context prefixes to each training row in `prepared/`. Full
source descriptions, negations, history and uncertainty remain intact. No
evaluation rows are augmented. Original sources are not rewritten; copied
historical approval fields are explicitly downgraded to derived drafts because
they are independently unverified.

Counts: **11,035 training / 507 validation / 364 known test = 11,906 rows**.
Of the training rows, 2,207 are unaugmented descriptions and 8,828 are correlated
context variants. These counts are not independent incident observations. All
language/category variants of a source group remain together in its partition.
Exact normalized-text and group overlap across partitions is rejected.

`by_category/<incident_type>/reports.csv` and its split manifest are the actual
inputs to that category's classifier. No other category's rows are used to fit
its vocabulary or severity coefficients. The road model also retains its
original 947 road training descriptions. Held-out rows are reserved for
diagnostics rather than being fitted simply to increase counts.

The builder performs one fixed CPU fit per category and never activates a model.
The model and serving-policy evidence are in
[the release report](../../../../releases/category-experts-20261112-v1/README.md).
No real-report accuracy or percentage guarantee follows from adding rows.

General warning-sign references consulted on 2026-11-12:
[WHO Interagency Integrated Triage Tool](https://www.who.int/tools/triage) and
[American Red Cross assessment guidance](https://www.redcross.org/take-a-class/resources/learn-first-aid/unresponsive-and-breathing-person).
They do not validate these authored descriptions or certify this application's
four labels. WHO IITT is intended for emergency-unit triage; this application
is not presented as an implementation of that clinical protocol.
