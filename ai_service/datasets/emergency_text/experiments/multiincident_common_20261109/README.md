# Multilingual common-report vocabulary draft, 2026-11-09

This is **fictional, synthetic, unreviewed vocabulary augmentation** for the six incident types already present in the application. It contains no copied private reports, real identities, phone numbers, exact addresses, human sign-offs, or deployment approval. It is not an independent clinical evaluation set and does not establish 80% accuracy on real reports.

`scenarios.json` contains **288 category scenarios and 1,152 descriptions**: 12 scenario records for every category/label cell, each with English (`en`), Tagalog (`tl`), Cebuano/Bisaya (`ceb`), and mixed-language (`mixed`) wording. The categories are `road_accident`, `fire_incident`, `flood_landslide`, `medical_emergency`, `security_incident`, and `other`. The proposed labels remain `minor`, `serious`, `critical`, and `needs_review`. Every scenario is `review_status: draft`, with an empty reviewer.

## Semantic grouping and correlated descriptions

There are **60 global semantic families**, not 288 independent incidents or 1,152 independent patient observations. Forty-eight curated condition/context seeds are reused in category contexts. Twelve medical low-impact complaint families replace the property-damage Minor seeds for medical requests. This produces these global family counts:

| Proposed label | Global families | Scenario records | Descriptions |
| --- | ---: | ---: | ---: |
| Minor | 24 | 72 | 288 |
| Serious | 12 | 72 | 288 |
| Critical | 12 | 72 | 288 |
| Needs review | 12 | 72 | 288 |
| Total | 60 | 288 | 1,152 |

Each scenario has `id`, `incident_type`, `label`, `family_id`, `rationale`, `descriptions`, `review_status`, `reviewer`, and `source`. All translations and all category variants sharing `family_id` **must stay in the same partition**. Category prefixes do not make otherwise equivalent conditions independent. A trainer needing category-homogeneous CSV groups may derive category-qualified `group_id` values, but must freeze the partition from the original global `family_id` first. Never use the ID, label, rationale, family, review status, or source as model features.

The same clinical condition may plausibly occur during different incident responses. The category context does not claim that the incident caused it. Category prefixes are the same across labels to avoid teaching that the category itself determines severity. The shared wording deliberately limits corpus independence; use this pack to broaden common language recognition and expose engineering failures, not to estimate real-world performance.

## Proposed boundaries and vocabulary

Minor preserves the existing road-report meaning: explicitly limited property damage, no reported injury, and no remaining immediate hazard. Minor does not mean that a real injury can be ignored. Medical Minor examples instead describe explicitly resolved, low-impact complaints or false alarms with present normal alertness and breathing and no reported pain or injury; this is an **unreviewed category expansion**, not medical clearance. Earlier unresponsiveness followed by awakening is kept in Needs review, not automatically downgraded.

Serious examples contain actual injuries with the described patient awake and breathing normally: abrasions, bruises, cuts, painful ankle injury, suspected arm injury, localized blister burns, eye injury, shoulder injury, foot puncture, dental/lip injury, and back pain. Even a small abrasion remains Serious under the existing dataset's injury boundary. These are proposed text-only labels rather than certified clinical classifications.

Critical examples describe current grave warning signs: unresponsiveness, absent normal breathing, uncontrolled blood loss, gasping and blue lips, acute chest pressure with cold sweat, new face/arm/speech changes, repeated convulsions without recovery, throat/tongue swelling with breathing compromise, chest compression, smoke-related airway signs, amputation with active bleeding, and deteriorating responsiveness with cold clammy pallor. These examples do not diagnose stroke, shock, or death.

Needs review covers sparse reports, unknown injuries, unresolved contradictory awareness, historical accounts, incomplete assessment after recovery, unverified hearsay, dead devices rather than people, hypothetical questions, unclear pronouns, negated danger language without a positive assessment, vocabulary quotations, and unreadable fragments. It is an abstention state, never a declaration that the report is safe.

The translations use common reporting language, contractions, colloquial Cebuano, and code switching. They have not been reviewed by a fluent language reviewer or MDRRMO professional. A qualified reviewer must check operational label boundaries and language meaning before any row receives a real approval. No numerical confidence, diagnosis, or unobserved symptom is supplied as ground truth.

## Rebuilding and checks

`generate_scenarios.py` is a deterministic constructor with explicit authored multilingual seeds. It does not access reports, train a model, approve rows, or alter the active configuration. From this repository's `ai_service` directory:

```powershell
& '.\.venv-text\Scripts\python.exe' -B '.\datasets\emergency_text\experiments\multiincident_common_20261109\generate_scenarios.py'
```

Before fitting a diagnostic candidate, freeze the corpus bytes and global-family partition. After model selection or final scoring, do not rewrite the same test cases to improve the score. Training on drafts must remain an explicit diagnostic operation with human-review and activation gates left intact.

Structural checks confirm 12 records per category/label cell, all four languages present, 8–40 whitespace-delimited words per description, no repeated complete normalized descriptions, no literal target-label token in descriptions, no family crossing labels, and no approved rows. These checks do not establish semantic independence, clinical correctness, or translation quality.

## Source guardrails

The following primary guidance was opened on 2026-11-09 to check general urgency concepts and danger signs. The sources do not validate these synthetic descriptions or map the application's four labels into a certified dispatch protocol. The WHO IITT is intended for facility-based emergency-unit triage; this application is not presented as an implementation of that tool.

American Heart Association & American Red Cross. (2024). *2024 American Heart Association and American Red Cross guidelines for first aid*. https://cpr.heart.org/en/resuscitation-science/2024-first-aid-guidelines

World Health Organization. (n.d.). *Interagency Integrated Triage Tool*. https://www.who.int/tools/triage

The WHO source distinguishes urgency levels; the AHA/Red Cross guidance identifies warning signs involving breathing, bleeding, acute chest symptoms, stroke-like changes, convulsions, and smoke inhalation. All detailed example wording and proposed labels in this pack were authored for software diagnostics and remain drafts.
