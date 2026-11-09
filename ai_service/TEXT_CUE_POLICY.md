# Trilingual description suggestions and retained admin reviews

Ordinary-service version: `text-cues-20261109-v2`, with the unchanged road-only
TF-IDF v2 model on port 8100. The separate admin Lab on port **8101** uses
`triage-multiincident-tfidf-20261109-v1`, profile `multiincident_common_v1`, and
version `multiincident-cues-20261109-v4`. Both are experimental; current Laravel
reporting remains **`MANUAL_ONLY`**. The older metrics below refer to their named
historical profiles and do not describe the six-category Lab result.

## Isolated six-category profile — 2026-11-09

The Lab model uses `incident_type_description_v1` features, constructed identically
in training and inference for Road Accident, Fire Incident, Flood/Landslide,
Medical Emergency, Security Incident and Other. The selected unaugmented fit has
1,068 training, 308 validation and 144 family-separated test descriptions across
English, Tagalog, Cebuano/Bisaya and mixed language. Context augmentation was
tried but not selected.

The opt-in multi-incident profile keeps the legacy source unchanged. It adds
bounded warning-sign combinations, separate human/device/casualty references,
negation, uncertainty, time, recovery and explicit resolved-scene handling.
Current uncontained hazards may supply a Serious **minimum**; that floor cannot
downgrade a classifier-leading Critical signal. Explicit supported grave signs
may override to Critical. Unresolved uncertainty can route to Needs review.
Positive resolved-scene checks can leave the classifier assessment untouched.
No rule supplies a confidence percentage, medical diagnosis, or clearance.

**The 80% target failed.** Final recorded results (`final_diagnostic_v2`):

| Diagnostic set | Raw accuracy | Full-policy accuracy | Critical recall | Urgent cases assigned a lower category |
| --- | ---: | ---: | ---: | ---: |
| Family-separated test, 144 examples | 73.6111% | 72.9167% | 69.2308% | 5 |
| Separate `fresh_v2`, 384 examples | 83.3333% | 68.4896% | 50.0000% | 10 |

These are synthetic draft labels without human approval, not clinical validation
or independently reviewed real-report accuracy. Raw accuracy does not become
full-policy accuracy. Human-review and deployment-approval flags remain false;
the candidate stays in the isolated Lab. These inspected sets are now exposed
diagnostic evidence. No further training is part of this wrap-up. See
[the complete evaluation](artifacts/reports/multiincident_common_20261109/EVALUATION.md)
and [the Lab setup](LOCAL_TEXT_AI_LAB.md).

## Retained road-only policy

The legacy November update adds bounded contracted-negation, nonpatient-subject,
time/recovery and unresponsiveness variants. Critical/context checks remain
overrides; generic injury fallback remains bounded and cannot downgrade an
uncertain Critical model signal. The category scope gate runs before road-only
rules: new incident types do not inherit road-trained capability merely by
appearing in a dropdown. No weights or live model selector were changed.

## Input and decision policy

When advisory inference is enabled, Laravel sends `description` and the selected `incident_type` to `POST /predict/text`. Photos and location are not model inputs. The ordinary-service legacy TF-IDF weights use description-only features and support road accidents only; other categories return Needs review before classifier or phrase rules run. Its legacy phrase rules apply only within the supported road category. The isolated Lab's category-aware features and separate rule profile are described above; it does not enable ordinary reporting inference.

| Suggested category | Example reported language |
| --- | --- |
| Critical | `patay`, `namatay`, `dead`, `fifty-fifty` in a patient context, unresponsive/unconscious, absent or difficult breathing, heavy/uncontrolled bleeding, drowning or specified active entrapment danger |
| Serious | Affirmed injury/wound/fracture descriptions such as `injured`, `nasugatan`, `nasamdan`, when the classifier abstains and the text does not support a higher-risk rule |
| Minor | Explicit limited damage together with no reported injuries; otherwise use the classifier's accepted category |
| Needs review | Negated/uncertain/conflicting or historical indications, metaphor/nonpatient mentions, insufficient information, or an unsupported classifier output |

These mappings are project proposals for admin verification, not medical diagnoses. `patay` is a reported death claim; the software must not certify death. `fifty-fifty` is colloquial language, not a measured survival probability. Minor must not imply medical clearance.

For the legacy profile, Critical indicators and explicit context/negation exclusions take precedence. Other phrase rules fill classifier abstentions; they do not blindly replace every accepted model output. General injury words cannot downgrade an uncertain Critical classifier signal. The legacy 0.65 classifier cutoff remains unchanged; the isolated Lab uses its own recorded artifact settings. A rule may suggest Serious for an explicit injury even when the classifier abstains; it carries no invented confidence percentage.

Rules include bounded negation, subject, uncertainty, time and conflict checks: `walay patay`, `hindi patay`, `not dead`, `dead battery`, financial fifty-fifty references and hypothetical examples do not become affirmed death categories. `dili mohunong ang pagdugo` describes ongoing bleeding, not absence of bleeding. These checks are incomplete lexical heuristics; they cannot resolve all grammar, sarcasm, changing conditions or multiple-person references. An admin must review every operational severity.

The earlier standalone `patay` mention alert remains a separate conservative queue flag, including negated mentions, as requested in the existing system. It is not a final severity or confirmed death and is not the rule category above.

## Scores and provenance

The service returns `assessment_method=trained_model` for normal model outputs. Phrase suggestions return `assessment_method=text_rules`, `matched_cues`, and their actual rule name/version: `DualPath Trilingual Severity Rules` for the legacy profile or `Experimental Multilingual Multi-Incident Cues` for the isolated Lab profile. Their top-level confidence and probabilities are null. `model_assessment` separately contains the actual unchanged classifier output and four normalized class scores. No percentage is manufactured from keyword matches.

Laravel uses `ai_source=text_rule_advisory` for supported phrase suggestions. It stores genuine classifier scores separately in `ai_probabilities`, leaves rule confidence null, and shows all four categories as **uncalibrated model scores**. The analysis summary records the classifier name/version for audit. The rule suggestion can differ from the classifier's top score; the UI says so. The scores are not accuracy, a safety probability or a diagnosis.

## Automatic reports and admin review

Report search, cards, severity sorting and exports now share **one current severity category**: the final admin assessment after review; otherwise the AI's top-scoring category, or Needs review for tied model scores. Missing/invalid analysis is Unassessed. This display/filter category does not authorize dispatch. An admin correction supersedes the AI category for current results, while the original AI output remains in review history. No learned scores are rewritten to agree with a human correction.

The shared admin display also shows **AI top category** and its actual highest classifier percentage for all stored text assessments, even when that score is below the acceptance cutoff. `Needs review` and tied scores remain explicit. This is a provisional model ranking, not a newly accepted severity; it never sets final operational severity or overrides a phrase-rule suggestion. After admin review the same ranking is available from the original score snapshot. The Analyze again action updates the open modal in place and preserves pending admin notes; no threshold or model-weight change is made by this UI fix.

When advisory reporting is explicitly enabled, new web/API reports call the connected text service automatically on submission. Current local Laravel reporting remains `MANUAL_ONLY`, so the isolated Lab does not perform automatic report analysis. Existing review flows retain their original suggestion/scores when available. Service failure must not prevent report receipt, fabricate scores or assign a final AI severity.

Unconfirmed model/rule suggestions are excluded from final operational severity, assessed counts and dispatch recommendations. Admins confirm/correct or choose Needs review. On review the existing coordination log retains `ai_suggestion` including the original label, summary, model/rule identity, source, top model score where applicable and all four `model_probabilities`. The review card displays **Original AI suggestion**, **Original trained-model scores** and **Reviewed by admin** together. Later corrections preserve the original automated snapshot. Admin-only API responses expose it as `original_ai_assessment`. No schema migration is required. Editing a description invalidates its current linked label; historical audit entries remain.

## Deployment and rollback

`active_text_config.json` retains separate ordinary and Lab model selectors. The Laragon startup helper starts the configured Lab on 8101 without changing the ordinary model on 8100 or Laravel's manual-only reporting mode. `AI_TEXT_TEST_RULES_ENABLED` controls the isolated Lab rules; `AI_TEXT_RULES_ENABLED` controls ordinary-service rules when inference is enabled. Artifact metadata selects the supported category scope and versioned profile. `/system/ai-status` describes the ordinary service; verify the Lab through `8101/ready/text` and its own metadata. The free Render profile remains disabled. This Lab setup is not an online deployment or approval to promote the failed candidate.

## Evaluation limits

The November v2 policy scored **186/220 (84.55%)** on that same already exposed
historical diagnostic split, with 68.18% suggestion coverage and no observed
lower-category assignments among its 101 Serious/Critical examples. Per-language
results were English 82.61%, Tagalog 83.61%, Cebuano 84.21%, and mixed 90.91%.
All 16 targeted November challenge probes passed. These results describe
engineering checks on synthetic, previously used data; they do not establish
independent real-report accuracy or support for the other five categories.
The combined overall result is lower than v1's historical 85.9%; the new bounded
context checks must not be described as an overall accuracy improvement.

See [the diagnostic report](artifacts/reports/text_rules_20261016/EVALUATION.md). Behavior tests exercise 70 synthetic descriptions across English, Tagalog and Bisaya, plus contract/integration tests for real score retention, automatic intake and admin confirmation. These are engineering checks, not independent accuracy validation.

The original classifier's 92.7% raw synthetic test accuracy does **not** become the accuracy of the combined policy. On the already exposed 220-row historical diagnostic split, the final service policy scores 85.9% after review routing (English 79.7%, Tagalog 86.9%, Cebuano 86.0%, mixed 97.0%); suggestion coverage is 65.5%, with zero observed high-risk underclassifications in that small diagnostic sample. That sample was used to find implementation errors and is no longer a holdout for this policy. This does not prove 80–85% real-report or per-language accuracy. No human approval fields, dataset labels, training weights or calibrated-confidence claims were created.

Clinical context reference: [WHO Interagency Integrated Triage Tool](https://www.who.int/tools/triage) and its [adult criteria](https://cdn.who.int/media/docs/default-source/integrated-health-services-%28ihs%29/csy/iitt/iitt_adult.pdf) identify high-risk clinical signs. They do not validate this project's language rules or colloquial terms; their medical assessment workflow is not reproduced by this lexical software.
