# Synthetic scenario sources

Three authored JSON packs retain 120 multilingual fictional scenarios before word-budget selection. Each contains the same scenario in English, Tagalog and Bisaya plus a proposed label and rationale. These are AI-authored drafts, not approved operational labels or real incidents.

`scenario_groups.json` conservatively merges 14 families of close variants before splitting. It includes translations, near-paraphrases, and selected repeated semantic patterns. Automatic normalized-text/character-similarity checks and an AI spot review cannot establish complete semantic independence or clinical correctness.

The counted output is [the separate 9,000-word pack](../experiments/synthetic_9000words_20260920/README.md). Its manifest records exact source hashes. Do not rerun the generator over an existing output or silently change a training source after evaluating it.

From `ai_service/`, reproduce into a **new** output directory:

```powershell
& 'C:\laragon\bin\python\python-3.13\python.exe' generate_text_pack.py --sources datasets/emergency_text/generation/road_scenarios_a.json datasets/emergency_text/generation/road_scenarios_b.json datasets/emergency_text/generation/road_scenarios_c.json --group-map datasets/emergency_text/generation/scenario_groups.json --output datasets/emergency_text/synthetic_9000words_reproduction
```

The selector chooses whole descriptions for 750 words per label per language. It never truncates or pads text to hit that budget. Not every source translation is selected; those that are selected keep the same scenario/family group. Metadata, headings and duplicated folder copies are not counted as description words.

The existing [provisional annotation guide](../annotation_guide.md) defines this project's draft labels. The [WHO Interagency Integrated Triage Tool](https://www.who.int/tools/triage) is a clinical reference for facility-based triage, **not** a validation of these text labels or a direct mapping to this app's four outputs. Its [reference card](https://cdn.who.int/media/docs/default-source/integrated-health-services-%28ihs%29/csy/iitt/iitt_reference-card09165e1c-0df5-472d-ab53-45506191a7bd.pdf) also highlights high-risk injury mechanisms; the synthetic review removed pedestrian-strike and wheel-crush examples from the generic stable-injury group rather than assuming normal breathing makes those mechanisms safe. Remaining boundaries still require local responder review.

Known limitations: this is a small, deliberately balanced artificial corpus. Repeated wording such as restrained occupants, low-speed contact and normal breathing may become shortcuts. Negated, historical and contradictory reports are included, but actual misspellings, regional usage, code-switching, base rates and reporting noise are not adequately represented. Do not claim clinical reliability or deploy automatically from this pack's scores.
