# Additional common-language incident scenarios

`additional_scenarios.json` contains **144 fictional scenarios / 576 descriptions** authored for the expanded multi-incident experiment. Every scenario has English, Tagalog, Cebuano, and mixed-language descriptions. These are developer-authored synthetic examples, **not human-reviewed emergency reports**. Every source record is `draft`, with a blank reviewer; no approval or deployment acceptance is supplied.

The descriptions use common civilian and LoRa-node reporting language, without requiring a location, name, contact number, photograph, later outcome, or diagnosis. They were authored without reading final-test predictions or the separate blind diagnostic's contents or predictions. No private report data was used.

## Coverage

| Incident category | Minor | Serious | Critical | Needs Review | Scenarios | Descriptions |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Road Accident | 6 | 6 | 6 | 6 | 24 | 96 |
| Fire Incident | 6 | 6 | 6 | 6 | 24 | 96 |
| Flood / Landslide | 6 | 6 | 6 | 6 | 24 | 96 |
| Medical Emergency | 6 | 6 | 6 | 6 | 24 | 96 |
| Security Incident | 6 | 6 | 6 | 6 | 24 | 96 |
| Other | 6 | 6 | 6 | 6 | 24 | 96 |
| **Total** | **36** | **36** | **36** | **36** | **144** | **576** |

Descriptions contain **17–25 whitespace-delimited words** and remain below the application's 1,000-character limit. Word counts are a writing check, not a measure of learned vocabulary or accuracy.

| Language | Description words | Unique surface words |
| --- | ---: | ---: |
| English | 3,197 | 893 |
| Tagalog | 3,052 | 725 |
| Cebuano | 3,045 | 697 |
| Mixed | 2,867 | 887 |

Unique surface words count case-folded Unicode alphabetic words, allowing internal hyphens and apostrophes. Mixed language deliberately includes code-switching; its word inventory overlaps the other languages. These counts cannot be added to claim distinct learned words. The current TF-IDF classifier learns character n-grams from its training partition, rather than a manually approved word dictionary.

## Provisional rubric for this source

- **Minor:** explicit absence of current injury and active danger, or a resolved low-impact complaint. Examples include cosmetic contact, fully extinguished cooking flames, cleared rainwater, resolved discomfort, and secured harmless objects. A dead phone or engine alone is not a human fatality.
- **Serious:** a current injury with reported alertness and normal breathing, or an ongoing hazard needing urgent response without an explicitly immediate human life threat. Examples include controlled wounds, painful limbs, uncontained property fires after evacuation, rising floodwater affecting routes, unstable debris, and ongoing unarmed disturbances.
- **Critical:** an explicit current human life threat or grave combination of signs, such as unresponsiveness with abnormal breathing, uncontrolled heavy bleeding, chest compression, current drowning, smoke-related breathing compromise, or ongoing convulsions without recovery. Descriptions report observations; they do not diagnose a disease or confirm death.
- **Needs Review:** sparse, contradictory, historical, hypothetical, second-hand, or unclear-subject information that does not establish a current severity. This is an abstention, not evidence of low urgency. A past incident, quoted practice sentence, dead device, or uncertain forwarded message must not silently become a present casualty.

This expanded rubric is provisional and specific to the new source. It does not rewrite historical labels. In particular, active property or route hazards may receive Serious here even when no person is injured. Report the source, rubric, rule profile, and version with results; a historical benchmark using a different rubric is not automatically comparable.

## Semantic families and partitions

The source has **98 declared global semantic families**: 24 Minor, 32 Serious, 16 Critical, and 26 Needs Review. Each category/label cell contains at least four distinct declared families.

Translations share a family. Close category variants also share a global family when they describe the same pattern: controlled limb wounds, uncontrolled massive bleeding, unresponsive people with abnormal breathing, chest compression, uncertain dead-device subjects, historical incidents, and conflicting accounts are examples. Different category names must not be used to manufacture independence between paraphrases.

CSV export may qualify `group_id` with the category to satisfy the current schema, but it must retain the unqualified `family_id` in provenance. **All category contexts and translations of a global family must remain in the same partition.** Choose partitions using labels and category/language coverage before inspecting predictions, and freeze the case IDs and source hash. Do not move a difficult example or paraphrase into training after viewing its evaluation result and still describe that evaluation as untouched.

Declared families help prevent direct leakage but cannot prove semantic or real-world independence. These scenarios share language conventions, authoring choices, and a provisional rubric. Even a previously unseen synthetic diagnostic is not independently reviewed field validation.

## Validation and source identity

The on-disk audit checked JSON parsing, unique IDs, exactly six scenarios in all 24 category/label cells, four languages per scenario, description lengths, absence of duplicate normalized descriptions, family/label consistency, and draft/blank-reviewer status. The existing `train_multiincident_common.read_scenarios` loader accepted all 576 descriptions and preserved all 98 global families. No model training or accuracy evaluation was performed as part of authoring these files.

Frozen `additional_scenarios.json` SHA256:

```text
b579da7969fde02ab2075543d9e89ce53a150b53eae25f610c84de00f0d28d38
```

Future content corrections require a new source version and new provenance. Model training, measured diagnostic results, release decisions, and any actual reviewer approval belong in their separate experiment records; none is implied by this dataset.
