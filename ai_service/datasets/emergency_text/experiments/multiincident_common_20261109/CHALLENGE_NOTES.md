# Multicategory synthetic challenge set

Created on 2026-11-09 for developer diagnostics of common-language descriptions in the existing six incident categories. This file is separate from model training and must remain excluded from training, vocabulary fitting, threshold tuning, and automatic feedback exports.

`challenge_scenarios.json` contains 96 semantic scenarios: four proposed outcomes for each of four labels in each of six categories. Every scenario has English (`en`), Tagalog (`tl`), Cebuano (`ceb`), and mixed-language (`mixed`) text, making 384 descriptions. Each translation is 8-40 whitespace-separated words. The canonical incident categories are `road_accident`, `fire_incident`, `flood_landslide`, `medical_emergency`, `security_incident`, and `other`. The labels are `minor`, `serious`, `critical`, and `needs_review`.

## Provenance and limitations

- All examples, translations, rationales, and proposed labels were written by a coding agent. They contain no collected user reports or personal information. No generated training corpus or private real report was read to create this file.
- These are developer-synthetic proposed labels, not qualified human annotations, MDRRMO approval, clinical validation, or evidence of real-world accuracy. Some operational judgments, especially ongoing non-casualty hazards and resolved medical symptoms, require local responder review before any training or operational standard is based on them.
- Separate authoring does not make a holdout human-independent. Common emergency situations and words can overlap semantically with independently generated training material even when texts differ. Exact-text comparison cannot detect all such overlap.
- The 384 descriptions represent 96 scenarios, not 384 independent observations. The four translations of a scenario share its meaning. The 96 scenarios are further grouped into 24 related `family_id` groups: four scene families per category, with severity-changing variants in each family. Keep every translation and every member of a family together in any future split.
- Family IDs describe related variants within this challenge only. A developer must compare scene-level meaning with training family definitions before claiming family separation across corpora. Merely having distinct ID prefixes does not establish separation.
- Four scenarios per category/label cell is deliberately a small diagnostic sample. A high score on this file cannot substantiate an 80% deployment accuracy guarantee, coverage of all dialects, or safety of a release.
- Once results are inspected and influence development, the set becomes an exposed regression benchmark. Do not relabel it as a fresh acceptance holdout for the revised model. Use a new independently reviewed, AI-blinded real-report evaluation set for acceptance.

## Provisional operational policy

- `critical`: explicit current unresponsiveness, substantially compromised breathing, uncontrolled heavy bleeding, or an immediate direct threat to life. One endangered casualty outweighs reassuring details about others.
- `serious`: a meaningful ongoing injury or urgent active hazard without an explicitly described immediate life-threatening sign. A resolved acute sign does not make a persisting injury harmless.
- `minor`: no current injury and a resolved low-exposure event, with explicit reassuring current context and no described ongoing human threat. The project rubric treats an actual injury, including a small cut or scrape, as an injury needing assessment; such an injury is not used as a `minor` target here.
- `needs_review`: insufficient, stale, conflicting, hypothetical, quoted, or subject-uncertain information. This is an abstention requiring human clarification, not a fourth physical injury grade.

The cases include straightforward reports, negated warning words, recovered symptoms, past events, equipment described as dead, uncertain subjects, quoted and conditional dangers, multiple casualties, ongoing property hazards, and short unclear requests. No text intentionally names its target severity grade.

## Reporting diagnostic results

Report both the overall description score and results by category, language, and label. Show each semantic scenario/family only once when discussing sample independence. Include abstentions and dangerous lower-severity assignments in error analysis; never improve a reported score by silently dropping unclear cases. Record the tested model artifact, feature contract, rule version, and test-file digest. Preserve this synthetic provenance alongside every result.
