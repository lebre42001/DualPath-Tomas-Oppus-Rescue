"""Prepare blinded responder review and real-report intake templates.

Uses only the saved seed-41 test predictions. Creates no emergency examples,
does not relabel data, and does not change any model/configuration.
"""
from __future__ import annotations

import argparse
import csv
import json
from pathlib import Path
import sys

AI_ROOT = Path(__file__).resolve().parents[1]
ROOT = AI_ROOT.parent
sys.path.insert(0, str(AI_ROOT))

from src.ai_service.text_training import LABELS

DATASET = AI_ROOT / "datasets/emergency_text/reviewed/civilian_multilingual_850_responder_reconciled_20261007"
EVALUATION = AI_ROOT / "artifacts/reports/xlmr_responder_reconciled_20261007/seed_41_test_evaluation"
DEFAULT_OUTPUT = AI_ROOT / "artifacts/reports/xlmr_responder_reconciled_20261007/followup_data_quality"
RANK = {"minor": 0, "serious": 1, "critical": 2}


def load_rows(path: Path) -> list[dict[str, str]]:
    with path.open(encoding="utf-8-sig", newline="") as handle:
        return list(csv.DictReader(handle))


def write_csv(path: Path, fields: list[str], rows: list[dict[str, str]]) -> None:
    with path.open("w", encoding="utf-8-sig", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields, extrasaction="raise", lineterminator="\r\n")
        writer.writeheader()
        writer.writerows(rows)


def prepare(output: Path) -> dict:
    if output.exists():
        raise ValueError(f"Refusing to overwrite existing follow-up package: {output}")
    predictions = json.loads((EVALUATION / "evaluation_predictions.json").read_text(encoding="utf-8"))["rows"]
    reports = {row["case_id"]: row for row in load_rows(DATASET / "reports.csv")}
    human = {row["case_id"]: row for row in load_rows(DATASET / "human_review_records.csv")}

    cases = []
    for row in predictions:
        probabilities = row["probabilities"]
        predicted = LABELS[max(range(len(probabilities)), key=lambda index: probabilities[index])]
        actual = row["label"]
        if actual in RANK and predicted in RANK and RANK[predicted] < RANK[actual]:
            source = reports[row["case_id"]]
            review = human[row["case_id"]]
            cases.append({
                "case_id": row["case_id"], "language": row["language"], "text": source["text"],
                "actual_label": actual, "model_prediction": predicted,
                "probabilities": dict(zip(LABELS, probabilities)),
                "scenario_family_id": review["scenario_family_id"],
                "annotation_group_id": review["annotation_group_id"],
                "split_group_id": source["group_id"],
            })
    cases.sort(key=lambda row: row["case_id"])
    if len(cases) != 3:
        raise ValueError(f"Expected exactly 3 raw serious/critical under-triage cases, found {len(cases)}")

    output.mkdir(parents=True)
    response_fields = [
        "reviewer_id", "proposed_final_label", "decisive_evidence",
        "ambiguity_or_uncertainty", "language_concern", "policy_issue",
        "immediate_human_attention", "review_status", "reviewed_at",
        "rubric_version",
    ]
    blinded_fields = ["case_alias", "language", "description", *response_fields]
    aliases = {case["case_id"]: f"UA-{index:03d}" for index, case in enumerate(cases, 1)}
    blinded_rows = [
        {"case_alias": aliases[case["case_id"]], "language": case["language"], "description": case["text"], **{field: "" for field in response_fields}}
        for case in cases
    ]
    write_csv(output / "UNDERTRIAGE_REVIEWER_A.csv", blinded_fields, blinded_rows)
    write_csv(output / "UNDERTRIAGE_REVIEWER_B.csv", blinded_fields, blinded_rows)

    key_fields = [
        "case_alias", "case_id", "language", "description", "actual_label",
        "model_prediction", "probability_minor", "probability_serious",
        "probability_critical", "probability_needs_review", "scenario_family_id",
        "annotation_group_id", "split_group_id", "family_overlap_followup_flag",
    ]
    key_rows = []
    for case in cases:
        key_rows.append({
            "case_alias": aliases[case["case_id"]], "case_id": case["case_id"],
            "language": case["language"], "description": case["text"],
            "actual_label": case["actual_label"], "model_prediction": case["model_prediction"],
            **{f"probability_{label}": case["probabilities"][label] for label in LABELS},
            "scenario_family_id": case["scenario_family_id"],
            "annotation_group_id": case["annotation_group_id"],
            "split_group_id": case["split_group_id"],
            "family_overlap_followup_flag": "possible_similar_tagalog_cases_review_pair" if case["case_id"] in {"TL-077", "TL-099"} else "",
        })
    write_csv(output / "COORDINATOR_KEY.csv", key_fields, key_rows)

    adjudication_fields = [
        "case_alias", "reviewer_a_label", "reviewer_b_label", "disagreement_type",
        "critical_review_trigger", "language_issue", "policy_issue", "adjudicator_id",
        "adjudicated_label", "rationale", "rubric_revision_required", "status",
        "adjudicated_at",
    ]
    adjudication_rows = [{
        "case_alias": aliases[case["case_id"]], "reviewer_a_label": "", "reviewer_b_label": "",
        "disagreement_type": "", "critical_review_trigger": "serious_or_critical_undertriage",
        "language_issue": "", "policy_issue": "", "adjudicator_id": "",
        "adjudicated_label": "", "rationale": "", "rubric_revision_required": "",
        "status": "pending", "adjudicated_at": "",
    } for case in cases]
    write_csv(output / "UNDERTRIAGE_ADJUDICATION.csv", adjudication_fields, adjudication_rows)

    family_fields = [
        "case_alias_a", "case_alias_b", "case_id_a", "case_id_b",
        "current_family_a", "current_family_b", "relationship_type",
        "semantic_equivalence", "translation_or_paraphrase",
        "decisive_information_changed", "recommended_scenario_family_id",
        "recommended_annotation_group_id", "reviewer", "notes", "status",
    ]
    family_rows = []
    if {case["case_id"] for case in cases} >= {"TL-077", "TL-099"}:
        one, two = next(c for c in cases if c["case_id"] == "TL-077"), next(c for c in cases if c["case_id"] == "TL-099")
        family_rows.append({
            "case_alias_a": aliases[one["case_id"]], "case_alias_b": aliases[two["case_id"]],
            "case_id_a": one["case_id"], "case_id_b": two["case_id"],
            "current_family_a": one["scenario_family_id"], "current_family_b": two["scenario_family_id"],
            "relationship_type": "", "semantic_equivalence": "", "translation_or_paraphrase": "",
            "decisive_information_changed": "", "recommended_scenario_family_id": "",
            "recommended_annotation_group_id": "", "reviewer": "",
            "notes": "Two similar Tagalog test texts are listed for review only; do not merge automatically.",
            "status": "pending_human_review",
        })
    write_csv(output / "FAMILY_LINK_REVIEW.csv", family_fields, family_rows)

    intake_fields = [
        "case_id", "scenario_family_id", "language", "deidentified_text",
        "collection_batch_id", "source_record_reference_sha256", "source_verified_by",
        "source_verified_at_utc", "deidentification_checked_by", "deidentification_checked_at_utc",
        "partition_assignment", "notes_no_personal_data",
    ]
    write_csv(output / "REAL_INCIDENT_INTAKE_TEMPLATE.csv", intake_fields, [])
    review_fields = [
        "case_alias", "language", "deidentified_text", *response_fields,
    ]
    write_csv(output / "NEW_CASE_REVIEWER_A_TEMPLATE.csv", review_fields, [])
    write_csv(output / "NEW_CASE_REVIEWER_B_TEMPLATE.csv", review_fields, [])
    write_csv(output / "NEW_CASE_ADJUDICATION_TEMPLATE.csv", adjudication_fields, [])

    manifest = {
        "status": "review_package_prepared_pending_human_review",
        "source_evaluation": "seed_41 frozen test split",
        "undertriage_rows": len(cases),
        "reviewer_initial_fields": ["case_alias", "language", "description", *response_fields],
        "blinded_reviewers_must_not_receive": ["case_id", "actual_label", "model_prediction", "confidence", "scenario_family_id", "split_group_id"],
        "no_examples_generated": True,
        "no_labels_changed": True,
        "training_performed": False,
        "active_model_changed": False,
        "deployment_approved": False,
    }
    (output / "PACKAGE_MANIFEST.json").write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    guide = """# Responder follow-up and real incident data workflow

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
"""
    (output / "FOLLOWUP_WORKFLOW.md").write_text(guide, encoding="utf-8")
    return {**manifest, "output": str(output), "files": sorted(path.name for path in output.iterdir())}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT)
    args = parser.parse_args()
    print(json.dumps(prepare(args.output), ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
