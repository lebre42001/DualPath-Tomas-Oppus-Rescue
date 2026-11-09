# Common vocabulary experiment and evaluation plan

The owner requested common multilingual vocabulary, actual training and evaluation
for all six existing incident categories, aiming for at least 80%. This is an
engineering experiment using authored fictional descriptions. It does not create
human approvals or establish accuracy on real emergency reports.

## Frozen data

The initial source contains 1,152 descriptions from 288 category scenarios and
60 global semantic families. Before fitting, all translations and category
contexts sharing a family were assigned together: 768 training, 176 validation,
and 208 test descriptions. The source, family assignments and hashes are saved
under `prepared/`. The separately authored challenge is never used for fitting
or selection. Correlated translations do not count as independent observations.

## Initial validation and justified revision

The first search compared C = 1, 4, 16, 64 with the existing road-only rule policy
and a fixed 0.65 score cutoff. These trials remain preserved. Raw validation
accuracy was about 83%, but raw Critical recall was only 50%. The conservative
C4 policy scored 56.25% across all validation cases, including review routing.
This failed the request; accepted-only accuracy is not substituted for accuracy.
No final test or challenge was scored during that decision.

The second, validation-only plan adds four neutral category-context variants to
each training row, keeping its full condition, label and family unchanged. The
original validation/test rows and assignments remain identical. It also tests an
explicit experimental multi-incident cue profile for current reported danger,
negation, uncertainty, recovery and person/equipment context. The legacy model's
road-only profile remains unchanged. These cues are software proposals informed
by published triage warning signs, not a reproduction or certification of a
clinical protocol. The profile and its version are explicit model metadata.

The revised search uses C = 1, 4, 16, 64 and provisional score cutoffs 0.50, 0.60,
0.65 and 0.70. Selection uses validation only: prefer zero urgent lower-category
assignments and Critical recall at least 95%; then fewer lower assignments,
higher Critical recall, full-policy macro-F1 and raw macro-F1. All attempts and
parameters are retained. No score cutoff is described as calibrated confidence.

## Final scoring and decision

Freeze the chosen weights, metadata, cue profile and cutoff before scoring the
208-case test and the separate 384-text synthetic challenge, once per candidate.
Report raw classifier and complete serving-policy results separately, with all
examples in the routed denominator, per-category/language results, review
coverage, Critical recall and urgent lower assignments. Review is not an omitted
case or a successful urgent prediction. Do not edit evaluation labels to improve
results. Later bug fixing makes an inspected test development evidence.

The engineering target is 80% accuracy and macro-F1 overall and by category and
language, alongside at least 95% Critical recall and zero urgent lower assignments.
These are provisional engineering acceptance settings, not clinical thresholds.
Some category/language/class cells contain only one or two underlying families,
so subgroup percentages have large uncertainty and cannot establish reliability.

Keep `all_data_reviewed=false`, `approved_for_deployment=false` and experimental
status regardless of synthetic scores. A successful experiment can be made
available for supervised AI Lab/advisory testing with mandatory admin review.
Formal validated release still needs appropriately reviewed real-report evidence.
Existing category locks are not unlocked by this experiment. An unsuccessful
candidate must not be described as a final 80% model or replace a better pilot.

## First final diagnostic failed; subsequent development

The first frozen candidate (C16, cutoff 0.50, expanded cue profile v2) scored
87.50% raw classifier accuracy but **76.92% complete-policy accuracy** on the
208-description source test. The separately authored 384-description challenge
scored 78.13% raw and **64.32% complete-policy accuracy**, with 65.63% Critical
recall and 33 urgent lower assignments. The engineering gate failed. These
unaltered results remain under `artifacts/reports/multiincident_common_20261109/final_diagnostic_v1/`.

After inspection, both sets became exposed development/regression evidence.
They must never be called fresh acceptance tests for subsequent revisions.
Observed gaps included active no-casualty hazards, absent-response wording,
nonstop bleeding, device-only safe scenes and unresolved person contradictions.

The next revision adds a separate draft scenario source with everyday incident
mechanisms and a broader proposed operational rubric: an ongoing uncontained
hazard needing urgent assistance can be Serious even before anyone is injured;
explicit direct immediate human danger can be Critical. This is a proposed
multi-incident extension, not a retrospective change to any old dataset label
or an official clinical standard. Existing source files and labels stay intact.

The supplementary source is family-partitioned before fitting. Its training
and validation groups join the corresponding prior partitions. The previous
208-case test is omitted entirely from the new fit/version; only supplementary
source test groups remain in that version's test partition. Clinical concepts
may still overlap between authored sources, which is a limitation rather than
proof of independent semantic coverage.

A new 384-description diagnostic is being authored separately without access to
training data, model weights or previous predictions. Freeze the next selected
candidate before loading/scoring it. It remains AI-authored synthetic evidence,
with no human/clinical approval or real-report accuracy guarantee. No candidate
is promoted merely because one of several reported metrics exceeds 80%.

## Second frozen diagnostic and stopping decision

The unaugmented combined C16/0.50 candidate with cue profile v4 was selected for the isolated Lab only, using the documented validation ranking in `lab_selection.json`. Its validation full-policy accuracy was 85.06%, Critical recall 91.67%, zero Critical lower assignments and one Serious lower assignment. This did not satisfy the conservative deployment gate.

The frozen 144-case supplementary-family test scored 73.61% raw classifier accuracy and **72.92% complete-policy accuracy**, with 69.23% Critical recall and five urgent lower assignments. The fresh-v2 384-case diagnostic scored 83.33% raw and **68.49% complete-policy accuracy**, with 50.00% Critical recall and ten urgent lower assignments. Both failed the engineering target. All exact predictions and the pre-scoring context are preserved in `final_diagnostic_v2`.

No further tuning on these now-exposed diagnostics was performed. The best validation-selected experiment is available in the isolated local 8101 AI Lab, explicitly unreviewed and unapproved. The ordinary 8100 model and manual reporting mode remain unchanged. More synthetic word lists alone do not establish the requested 80% real-report accuracy; representative reviewed examples and a fresh independent holdout are needed. See the full evaluation report for denominators, subgroup results and deployment limits.
