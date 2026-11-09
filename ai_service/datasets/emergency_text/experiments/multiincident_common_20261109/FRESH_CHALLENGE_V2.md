# Fresh multilingual diagnostic v2

Frozen artifact: `fresh_challenge_v2.json`  
Frozen date: 2026-11-09  
SHA-256: `dccbfcb71f596fa8d07c99e666b3f61686c42cd9993677360562b1d04fd64b01`

This is a separately AI-authored, provisional synthetic diagnostic. It is not human-independent, clinically validated, responder-approved, or evidence of deployment readiness. Every scenario remains `draft`, every `reviewer` and `notes` field is blank, and the metadata records `human_reviewed=false`, `clinically_validated=false`, and `target_labels_provisional=true`.

## Contents

- 96 scenario records, each with aligned English (`en`), Tagalog (`tl`), Cebuano (`ceb`), and mixed-language (`mixed`) descriptions: 384 descriptions total.
- Six selected incident categories and four provisional output labels, with exactly four scenarios per category/label cell.
- 24 conservative synthetic scene families, each spanning four urgency/uncertainty variants. The 96 scenarios and 384 descriptions are not 96 or 384 independent real-world events.
- Descriptions contain 21-28 whitespace-delimited words. This tokenization rule treats hyphenated forms as one word.
- Scenario IDs and family IDs use only lowercase letters, digits, and underscores.

| Incident category | minor | serious | critical | needs_review | Total scenarios |
| --- | ---: | ---: | ---: | ---: | ---: |
| road_accident | 4 | 4 | 4 | 4 | 16 |
| fire_incident | 4 | 4 | 4 | 4 | 16 |
| flood_landslide | 4 | 4 | 4 | 4 | 16 |
| medical_emergency | 4 | 4 | 4 | 4 | 16 |
| security_incident | 4 | 4 | 4 | 4 | 16 |
| other | 4 | 4 | 4 | 4 | 16 |
| Total | 24 | 24 | 24 | 24 | 96 |

Each record contains `id`, `family_id`, `incident_type`, `label`, `descriptions`, `rationale`, `review_status`, `reviewer`, and `notes`. The category is supplied context; it is not inferred from the description. Rationales and target labels are reference metadata, not model input. All four descriptions of a scenario share its label and family.

## Proposed expanded rubric

The existing repository annotation guide covers road accidents only. This pack applies the task-provided proposed expansion to all six categories; designated local responders must approve the boundaries before operational use.

- `minor`: explicit absence of injury with a resolved hazard, or an explicitly low-impact nondangerous medical complaint.
- `serious`: meaningful injury without an explicit immediate grave sign, or an ongoing uncontained hazard requiring urgent response even when people are currently uninjured or evacuated.
- `critical`: explicit current unresponsiveness, major breathing compromise, uncontrolled blood loss, or a direct imminent human life threat.
- `needs_review`: insufficient, uncertain, historical, unresolved contradictory, quoted or hypothetical information, or unclear subject binding.

The descriptions include ordinary collisions, small resolved fires, continuing fire/flood/security hazards after evacuation, cuts, fever with vomiting, dizziness, assaults, electrical/structural hazards, shore rescue, and workshop spills. They also include a limited set of negation, recovery, multiple-person binding, and noncurrent-report cases. No diagnosis or death certification is asserted. Exact output-label tokens are absent from description text, and no personally identifying information is included.

## Provenance and freeze procedure

During authorship, the author read only the task-provided instructions and these permitted repository sources:

- `src/ai_service/incident_types.py`, for the canonical category/input schema.
- `datasets/emergency_text/annotation_guide.md`, for the existing road-only rubric, interpretation rules, and review procedure.

No training corpus, earlier challenge, metrics, predictions, weights, or other agents' files were read. No model was trained, evaluated, edited, or promoted by the author. Wording and provisional labels were completed and frozen before the author saw any model outputs for this diagnostic. No empirical score target was used to tune the pack.

The JSON bytes were frozen before readiness was reported. The checksum above identifies those exact UTF-8 bytes, including their final newline. A correction after model inspection must create a new version with a new checksum and a transparent correction note; it must not overwrite this artifact or silently alter a scored denominator. Evaluate the complete frozen pack, preserve family groupings, and report per-category, per-label, and per-language results rather than relying only on one pooled score. Do not train, augment, choose thresholds, or claim an independent final test from these descriptions after inspecting their results.

## Validation and limitations

Structural checks confirmed 96 unique scenario IDs, all 24 category/label cells with four scenarios, 24 family IDs, all four languages present, the word-count bounds, no exact normalized description duplicates, no exact output-label tokens in descriptions, and all draft/reviewer-blank fields. These checks do not validate clinical correctness or translation quality.

This balanced artificial set does not estimate real incident prevalence, dispatch outcomes, or field performance. Matched scene variants and parallel translations are deliberately dependent. Language is relatively clear, descriptions are short, and there is no coverage claim for dialect variation, transcription errors, image evidence, local geography, all hazards, or adversarial reports. Negated and recovered symptoms need fluent-speaker checking, and medical/security/active-hazard boundaries need local responder review. Labels remain provisional until that review actually occurs. Any overall result applies only to this pack and its proposed rubric; it does not establish an operational safety guarantee or justify changing the active model.
