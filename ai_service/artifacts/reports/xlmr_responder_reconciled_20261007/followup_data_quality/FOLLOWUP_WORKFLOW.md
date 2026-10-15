# Responder follow-up and real incident data workflow

## A. Review the three under-triage cases

Give Reviewer A and Reviewer B their separate CSV files. Do not give either reviewer `COORDINATOR_KEY.csv`, the other reviewer's answers, the actual labels, the model prediction, its confidence, or family IDs before their independent reviews are submitted. Reviewers may choose `minor`, `serious`, `critical`, or `needs_review`; if unresolved, set `review_status=deferred` and leave the label blank. Do not use `needs_review` as a substitute for an unfinished review.

Ask reviewers to record the decisive words or facts, what remains unclear, language concerns, policy issues, and whether immediate human attention is warranted. Do not tell reviewers what the model missed before they make their independent judgment.

After both worksheets are returned, compare labels using `UNDERTRIAGE_ADJUDICATION.csv`. Adjudicate disagreements, every Critical judgment, and any policy/language concern with a qualified responder. Record a rationale and any rubric revision; do not change the frozen test labels silently. This is an error-analysis review of already exposed test cases, so these three cases must not remain part of a future untouched test set.

`TL-077` and `TL-099` are similar Tagalog descriptions but have separate source family IDs. `FAMILY_LINK_REVIEW.csv` asks for human review only; it does not merge them. The distinction between a deep cut with controlled bleeding and a continuing/uncertain threat should be assessed by the responder.

## B. Collect new real incident descriptions

Use `REAL_INCIDENT_INTAKE_TEMPLATE.csv`. Add only de-identified, authorized real incident descriptions in scope. Never place names, phone numbers, email, exact home addresses, license plates, account IDs, or identifying photos in the training intake. Keep the original source in its authorized restricted system; the AI dataset should contain only the de-identified text and a non-reversible/hash reference if traceability is required. Preserve emergency-relevant facts such as mechanism, consciousness, breathing, bleeding control, entrapment, mobility, hazards, and whether help is already present.

Use a neutral `case_id`. Reuse one `scenario_family_id` for translations or paraphrases of the same underlying incident; do not reuse it for a materially different event. Reviewers work independently using separate copies of `NEW_CASE_REVIEWER_A_TEMPLATE.csv` and `NEW_CASE_REVIEWER_B_TEMPLATE.csv`; then resolve conflicts in `NEW_CASE_ADJUDICATION_TEMPLATE.csv`. Keep reviewer IDs and actual review timestamps. An unresolved/deferred case stays unlabeled and out of supervised training until resolved.

Assign a prospective test batch before training/tuning begins. Keep it sealed from model developers and training jobs. Every translation/paraphrase of one event must remain in the same partition. Do not move a difficult test case into training after seeing a prediction; create a new version and a new future holdout if the test set has been exposed.

## C. Before a future candidate run

Validate de-identification, schema, severity label definitions, reviewer provenance, label counts, language counts, scenario-family IDs, duplicate text, and group leakage. Freeze the dataset hash and split manifest. Compare a simple baseline against XLM-R using the same frozen split, and report critical recall, serious/critical under-triage, per-language raw metrics, thresholded responder-review coverage, confidence calibration, and uncertainty intervals. The current XLM-R candidate and active AI configuration remain unchanged.
