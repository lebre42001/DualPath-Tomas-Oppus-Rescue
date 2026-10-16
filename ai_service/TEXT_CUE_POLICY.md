# Trilingual description suggestions and retained admin reviews

Version: `text-cues-20261016-v1`. Explicitly requested by the system owner on 2026-10-16. Status: **experimental admin-supervised assistance**, not clinically validated triage.

## Input and decision policy

Only `description` is sent to `POST /predict/text`. Photos, location and incident type do not determine these text suggestions. The unchanged TF-IDF classifier is trained mainly on road/vehicle descriptions; the additional explicit English, Tagalog and Cebuano/Bisaya phrase rules can recognize stated conditions across activities, including an injury described during snowboarding.

| Suggested category | Example reported language |
| --- | --- |
| Critical | `patay`, `namatay`, `dead`, `fifty-fifty` in a patient context, unresponsive/unconscious, absent or difficult breathing, heavy/uncontrolled bleeding, drowning or specified active entrapment danger |
| Serious | Affirmed injury/wound/fracture descriptions such as `injured`, `nasugatan`, `nasamdan`, when the classifier abstains and the text does not support a higher-risk rule |
| Minor | Explicit limited damage together with no reported injuries; otherwise use the classifier's accepted category |
| Needs review | Negated/uncertain/conflicting or historical indications, metaphor/nonpatient mentions, insufficient information, or an unsupported classifier output |

These mappings are project proposals for admin verification, not medical diagnoses. `patay` is a reported death claim; the software must not certify death. `fifty-fifty` is colloquial language, not a measured survival probability. Minor must not imply medical clearance.

Critical indicators and explicit context/negation exclusions take precedence. Other phrase rules fill classifier abstentions; they do not blindly replace every accepted model output. General injury words cannot downgrade an uncertain Critical classifier signal. The 0.65 classifier cutoff remains unchanged. A rule may suggest Serious for an explicit injury even when the classifier abstains; it carries no invented confidence percentage.

Rules include bounded negation, subject, uncertainty, time and conflict checks: `walay patay`, `hindi patay`, `not dead`, `dead battery`, financial fifty-fifty references and hypothetical examples do not become affirmed death categories. `dili mohunong ang pagdugo` describes ongoing bleeding, not absence of bleeding. These checks are incomplete lexical heuristics; they cannot resolve all grammar, sarcasm, changing conditions or multiple-person references. An admin must review every operational severity.

The earlier standalone `patay` mention alert remains a separate conservative queue flag, including negated mentions, as requested in the existing system. It is not a final severity or confirmed death and is not the rule category above.

## Scores and provenance

The service returns `assessment_method=trained_model` for normal model outputs. Phrase suggestions return `assessment_method=text_rules`, `matched_cues`, `model_name=DualPath Trilingual Severity Rules`, and the rule version. Their top-level confidence and probabilities are null. `model_assessment` separately contains the actual unchanged classifier output and four normalized class scores. No percentage is manufactured from keyword matches.

Laravel uses `ai_source=text_rule_advisory` for supported phrase suggestions. It stores genuine classifier scores separately in `ai_probabilities`, leaves rule confidence null, and shows all four categories as **uncalibrated model scores**. The analysis summary records the classifier name/version for audit. The rule suggestion can differ from the classifier's top score; the UI says so. The scores are not accuracy, a safety probability or a diagnosis.

## Automatic reports and admin review

New web/API reports call the connected text service automatically on submission. Admins receive the suggestion/scores with review still pending; no Analyze click is needed for new reports. Existing unreviewed records can use Analyze description with AI. Service failure must not prevent report receipt, fabricate scores or assign a final AI severity.

Unconfirmed model/rule suggestions are excluded from final operational severity, assessed counts and dispatch recommendations. Admins confirm/correct or choose Needs review. On review the existing coordination log retains `ai_suggestion` including the original label, summary, model/rule identity, source, top model score where applicable and all four `model_probabilities`. The review card displays **Original AI suggestion**, **Original trained-model scores** and **Reviewed by admin** together. Later corrections preserve the original automated snapshot. Admin-only API responses expose it as `original_ai_assessment`. No schema migration is required. Editing a description invalidates its current linked label; historical audit entries remain.

## Deployment and rollback

`active_text_config.json` and the Laragon startup helper enable this policy. Dokploy's private `text-ai` service uses `AI_TEXT_RULES_ENABLED=true` by default; override it with false and redeploy to retain model-only suggestions. Laravel must still enable advisory inference and allow the experimental pilot. `/system/ai-status` reports both the trained model and `text_rules_enabled` / `text_rules_version`. The free Render profile remains disabled.

## Evaluation limits

See [the diagnostic report](artifacts/reports/text_rules_20261016/EVALUATION.md). Behavior tests exercise 70 synthetic descriptions across English, Tagalog and Bisaya, plus contract/integration tests for real score retention, automatic intake and admin confirmation. These are engineering checks, not independent accuracy validation.

The original classifier's 92.7% raw synthetic test accuracy does **not** become the accuracy of the combined policy. On the already exposed 220-row historical diagnostic split, the final service policy scores 85.9% after review routing (English 79.7%, Tagalog 86.9%, Cebuano 86.0%, mixed 97.0%); suggestion coverage is 65.5%, with zero observed high-risk underclassifications in that small diagnostic sample. That sample was used to find implementation errors and is no longer a holdout for this policy. This does not prove 80–85% real-report or per-language accuracy. No human approval fields, dataset labels, training weights or calibrated-confidence claims were created.

Clinical context reference: [WHO Interagency Integrated Triage Tool](https://www.who.int/tools/triage) and its [adult criteria](https://cdn.who.int/media/docs/default-source/integrated-health-services-%28ihs%29/csy/iitt/iitt_adult.pdf) identify high-risk clinical signs. They do not validate this project's language rules or colloquial terms; their medical assessment workflow is not reproduced by this lexical software.
