# Provisional annotation guide: accident description urgency

**Status: draft for MDRRMO/responder approval.** This guide is a software dataset proposal, not a clinical triage protocol. All included CSV labels are suggestions and require actual human review before being treated as approved training examples.

## Decision scope

Assess a current road or vehicle accident from the description alone. The goal is to support the responder's initial prioritization based on what the reporter states. It is not to diagnose an injury, verify a fatality, predict a person's outcome, or decide that a person does not need help.

Read the complete message. Keep uncertainty, timing, negation, and who is affected intact. Do not infer injuries from vehicle type, the word "crash," property damage alone, a photo, or what usually happens in similar accidents. The absence of an injury statement is not evidence that there is no injury.

## Suggested label boundaries

| Label | Provisional interpretation | Important boundary |
| --- | --- | --- |
| `minor` | The text explicitly describes limited impact/damage and no reported injury or immediate danger. | Do not use as the default for short, vague, unfamiliar-language, or uncertain reports. It does not certify that everyone is medically safe. |
| `serious` | The text reports an injury or meaningful urgent assistance need after an accident, without a clear immediate life-threatening indicator in the available description. | Review the actual condition and context. The word "collision" or a large vehicle alone does not establish this label. Serious versus critical boundaries require local responder approval. |
| `critical` | The text reports an immediate grave danger, such as an unresponsive person, reported absent breathing, ongoing very heavy bleeding, or entrapment combined with an active threat. | This is reported urgency, not confirmed death. Do not infer death from unconsciousness, entrapment, or the label itself. Examples illustrate draft distinctions, not a complete clinical rule set. |
| `needs_review` | A supported urgency decision cannot be made from the text, or the message is outside the accident classifier's scope. | Includes insufficient detail, unresolved contradictory reports, and nonaccident messages. This is a review/abstention state, not a low-severity class or an automatic rejection. |

An out-of-scope fire, flood, or medical emergency can still be urgent. `needs_review` means this accident classifier must hand the case to an appropriate review flow; it does not mean "nonurgent." Preserve any stated dangerous conditions for responders.

## Text interpretation rules

1. **Negation:** distinguish "bleeding" from "not bleeding," "walay nasamad" from "naay nasamad," and "hindi nawalan ng malay" from "nawalan ng malay." A serious word inside a negative statement does not establish a positive symptom.
2. **Unknown information:** "wala ko kabalo kung naay nasamad" means injuries are unknown, not absent. Avoid turning "accident here" into a confident severity estimate.
3. **Timing:** distinguish a current emergency from a story about yesterday, a hypothetical example, a quotation, or a joke. A past incident may still need help if the message explicitly describes an ongoing problem.
4. **Multiple people:** keep each person's condition distinct. One conscious passenger does not cancel another person's reported unresponsiveness. Review the most urgent condition actually supported by the text.
5. **Contradictions:** determine whether statements refer to different people or different times. If the wording remains ambiguous, preserve it and route it for review; do not silently choose the convenient clause.
6. **Reported death:** "someone says the driver died" is an unverified report. Keep that attribution. Urgency for any living victims or ongoing hazard must be assessed separately. A fatality claim with unclear timing and scene status needs human review; it is not a diagnosis or automatic reason to downgrade assistance.
7. **Spelling and code switching:** retain plausible typos and natural language mixing. Do not replace local phrasing with artificial textbook translations simply to help the model.
8. **No invented detail:** do not add casualty counts, diagnoses, body parts, causes, or certainty absent from the text. An explanation must identify supporting text, not fabricate evidence.
9. **Photo independence:** do not consult images while deciding these text labels. If other evidence changes an operational decision, record that through the separate human review workflow rather than silently changing the text-only ground truth.

## Review procedure

Each reviewer first reads the description without relying on its draft label. They assess whether it is plausible, within scope, understandable in the stated language, and sufficiently detailed for an urgency judgment. They then compare the suggested label and record any correction and its reason.

Have a fluent speaker review Tagalog, Cebuano, and mixed-language examples. A designated responder reviewer must resolve the operational label definitions. For urgent examples and disagreements, seek a second review and document the resolution; a language check alone is not responder approval.

Use `notes` to preserve a brief audit trail. An entry should record the real review date, actual reviewer identity or traceable ID, rubric version, previous/new label if changed, and the reason. Do not invent sign-offs or add an expert's name without their review. An unresolved row remains `draft`; an excluded row becomes `rejected` with a reason.

Only set `review_status=approved` when the designated review has happened. Set `reviewer` to the actual responsible reviewer. A nonempty reviewer cell or a passing validator is a recordkeeping requirement, not proof that competent review occurred. Keep related translations semantically aligned and assign the same label within one group.

## Grouping and evaluation

`group_id` identifies the underlying incident/concept, not just a shared topic such as "motorcycle." Translations and close paraphrases of one incident must share a group ID. Distinct accidents can have separate IDs even when both involve motorcycles. If new wording creates a substantially different incident, give it a new group; do not disguise a translation as a new event.

The draft pack uses `shared_minor_01`, `shared_serious_01`, `shared_critical_01`, and `shared_review_01` for its four multilingual concepts. Other `shared_*` IDs conservatively group close scenario variants, even where a vehicle or body part differs; remaining rows have individual IDs. Reviewers must also look for unintended close paraphrases across groups because automatic normalized-text checks cannot establish semantic independence.

Split at group level before model fitting, threshold selection, or augmentation. Keep original and translated versions together. Keep the final evaluation data separate from training decisions. Analyze per-language and per-label mistakes, especially urgent reports assigned `minor` or `serious`, instead of relying on overall accuracy from this balanced artificial pack.

For the CSV schema, exact CLI commands, and approval status rules, see [README.md](README.md). No dataset review step authorizes changing the active model or deploying a candidate.
