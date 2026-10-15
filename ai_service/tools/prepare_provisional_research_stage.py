"""Prepare truthful AI-assisted review and provisional research receipts.

This utility never writes human decisions, changes source datasets, activates a
checkpoint, or grants deployment approval.  It is intentionally specific to the
client-authorized 2026-10-01 offline research stage.
"""

from __future__ import annotations

import csv
import hashlib
import json
import os
import platform
import re
import sys
from collections import Counter
from datetime import datetime
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
AI_ROOT = ROOT / "ai_service"
SOURCE = AI_ROOT / "datasets" / "emergency_text" / "working" / "reports.csv"
CANDIDATES = AI_ROOT / "datasets" / "emergency_text" / "expansions" / "short_panic_v1" / "candidates.csv"
PACK = AI_ROOT / "artifacts" / "review_packs" / "short_panic_v1_human_review_20261001"
MAPPING = PACK / "coordinator" / "mapping.csv"
WORKSHEET_A = PACK / "reviewer_A" / "worksheet.csv"
WORKSHEET_B = PACK / "reviewer_B" / "worksheet.csv"
ACTIVE_CONFIG = AI_ROOT / "active_config.txt"
CHECKPOINTS = AI_ROOT / "artifacts" / "checkpoints"
RUBRIC = "TEXT-RUBRIC-0.2-B"
LABELS = ("minor", "serious", "critical", "needs_review")


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def read_csv(path: Path) -> list[dict[str, str]]:
    with path.open(encoding="utf-8-sig", newline="") as handle:
        return list(csv.DictReader(handle))


def write_csv(path: Path, fields: list[str], rows: list[dict[str, object]]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields, lineterminator="\n")
        writer.writeheader()
        writer.writerows(rows)


def write_json(path: Path, value: object) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def normalized(text: str) -> str:
    return " ".join(text.casefold().split())


def file_inventory(directory: Path) -> list[dict[str, object]]:
    return [
        {"path": path.relative_to(directory).as_posix(), "sha256": sha256(path), "bytes": path.stat().st_size}
        for path in sorted(directory.rglob("*"))
        if path.is_file()
    ]


def length_bucket(text: str) -> str:
    count = len(text.split())
    if count <= 15:
        return "1-15"
    if count <= 30:
        return "16-30"
    if count <= 60:
        return "31-60"
    return "61+"


SUPERFICIAL_BOUNDARY = {"SPV1-025", "SPV1-029", "SPV1-033", "SPV1-038", "SPV1-040"}
FATALITY_WORDING = {"SPV1-050", "SPV1-055", "SPV1-056", "SPV1-057", "SPV1-068", "SPV1-071", "SPV1-072", "SPV1-073"}
LANGUAGE_CONCERNS = {
    "SPV1-051": "Elliptical Cebuano wording needs a fluent reviewer to confirm that absent breathing is the intended current meaning.",
    "SPV1-071": "The single Cebuano word has no subject or timeline and can refer to a person, object, or figurative state.",
    "SPV1-072": "Murag expresses uncertainty; a fluent reviewer must preserve that uncertainty rather than convert it to confirmed death.",
    "SPV1-073": "The Cebuano hearsay phrase lacks an identified subject and direct observation.",
    "SPV1-080": "The mixed-language question expresses uncertainty about breathing and must not be read as a definite finding.",
}
URGENT_AMBIGUOUS = {"SPV1-061", "SPV1-064", "SPV1-068", "SPV1-071", "SPV1-072", "SPV1-073", "SPV1-078", "SPV1-080"}


def ai_record(candidate: dict[str, str], alias: str) -> dict[str, str]:
    case_id = candidate["candidate_id"]
    label = candidate["proposed_label"]
    evidence = candidate["reason"]
    ambiguity = "None material for this provisional suggestion; a responder must still verify the report."
    language_concern = LANGUAGE_CONCERNS.get(case_id, "None identified in the draft; fluent human confirmation remains required.")
    policy_issue = "None identified beyond ordinary responder verification."

    # Policy B changes only the case that explicitly supplies the complete
    # superficial/controlled context. The other small-wound cases remain
    # Serious drafts because one or more Policy B conditions are not stated.
    if case_id == "SPV1-040":
        label = "minor"
        evidence = (
            "The text identifies only a small cut, says the person is awake and breathing normally, "
            "and describes no dangerous mechanism, functional impairment, active bleeding, or unresolved danger."
        )
        policy_issue = "Policy B superficial-injury boundary: provisional Minor; human confirmation is required."
    elif case_id in SUPERFICIAL_BOUNDARY:
        policy_issue = (
            "Policy B superficial-injury boundary: the text does not state every condition needed for Minor, "
            "so the conservative AI draft remains Serious pending human context."
        )

    if label == "needs_review":
        ambiguity = evidence
        evidence = "The description does not contain enough reliable context for a severity decision."
    elif case_id in FATALITY_WORDING:
        ambiguity = "Fatality-like wording is reported or uncertain and must never be treated as AI-confirmed death."
        policy_issue = "Fatality wording requires urgent human verification; Critical is an urgency class, not death confirmation."

    if case_id in {"SPV1-063", "SPV1-066", "SPV1-067", "SPV1-076"}:
        policy_issue = "Insufficient victim-condition detail; needs_review is a text sufficiency class, not a low-severity finding."
    if case_id in {"SPV1-052", "SPV1-063", "SPV1-074"}:
        policy_issue = "Physical versus digital nonresponse boundary must be confirmed from the subject and observation."
    if case_id in {"SPV1-045", "SPV1-053", "SPV1-059", "SPV1-075"}:
        policy_issue = "Entrapment wording must distinguish local pinching from whole-person entrapment and ongoing danger."
    if case_id in {"SPV1-030", "SPV1-036", "SPV1-039", "SPV1-059", "SPV1-077"}:
        policy_issue = "Immobility wording must identify the affected person or object and the scope of impairment."

    attention = "yes" if label == "critical" or case_id in URGENT_AMBIGUOUS else "no"
    reason = f"Contextual Policy B draft: {evidence}"
    return {
        "candidate_id": case_id,
        "neutral_alias": alias,
        "language": candidate["language"],
        "text": candidate["text"],
        "ai_suggested_label": label,
        "ai_decisive_evidence": evidence,
        "ai_ambiguity_or_uncertainty": ambiguity,
        "ai_language_concern": language_concern,
        "ai_policy_issue": policy_issue,
        "ai_immediate_human_attention": attention,
        "ai_review_reason": reason,
        "rubric_version": RUBRIC,
        "draft_status": "ai_draft_not_human_approved",
    }


def prepare_ai_assisted() -> dict[str, object]:
    candidates = {row["candidate_id"]: row for row in read_csv(CANDIDATES)}
    aliases = {row["candidate_id"]: row["neutral_alias"] for row in read_csv(MAPPING)}
    if set(candidates) != set(aliases) or len(candidates) != 80:
        raise ValueError("Candidate/mapping coverage is not exactly 80 matching IDs")
    rows = [ai_record(candidates[case_id], aliases[case_id]) for case_id in sorted(candidates)]
    if any(row["ai_suggested_label"] not in LABELS for row in rows):
        raise ValueError("Invalid AI draft label")

    destination = PACK / "ai_assisted"
    draft_fields = list(rows[0])
    write_csv(destination / "AI_PRE_REVIEW_DRAFT.csv", draft_fields, rows)

    common_a = [
        "candidate_id", "neutral_alias", "language", "text", "ai_suggested_label",
        "ai_decisive_evidence", "ai_immediate_human_attention",
    ]
    human_a = [
        "reviewer_id", "responder_decision", "human_final_label", "human_decisive_evidence",
        "human_notes", "human_immediate_attention", "reviewed_at", "rubric_version", "human_review_status",
    ]
    write_csv(destination / "REVIEWER_A_CONFIRMATION.csv", common_a + human_a, [
        {**{key: row[key] for key in common_a}, **{key: "" for key in human_a}} for row in rows
    ])

    common_b = ["candidate_id", "neutral_alias", "language", "text", "ai_suggested_label", "ai_decisive_evidence"]
    human_b = [
        "reviewer_id", "meaning_confirmed", "language_naturalness", "negation_correct", "subject_correct",
        "timeline_correct", "severity_interpretation_supported", "suggested_correction", "human_final_label",
        "human_notes", "reviewed_at", "rubric_version", "human_review_status",
    ]
    write_csv(destination / "REVIEWER_B_LANGUAGE_CONFIRMATION.csv", common_b + human_b, [
        {**{key: row[key] for key in common_b}, **{key: "" for key in human_b}} for row in rows
    ])

    guide = f"""SIMPLE RESPONDER GUIDE — {RUBRIC}

IMPORTANTE
Ang AI suggestion usa ra ka draft. Dili kini human decision, dili diagnosis, ug dili awtomatikong dispatch decision. Ayaw isulod ang reviewer ID o oras hangtod nga ang tinuod nga reviewer mismo makahuman sa pagsusi.

REVIEWER A — REV-A-002 / Bimbo Trimucha
1. Basaha pag-ayo ang emergency text.
2. Susiha ang AI suggested label ug decisive evidence.
3. Pili og approve, revise, o unresolved.
4. Kung revise, pilia ang hustong label: minor, serious, critical, o needs_review.
5. Isulat ang mubo ug klarong human decisive evidence ug notes.
6. Isulod ang tinuod nga reviewer ID ug aktuwal nga petsa/oras sa review.
7. Kung approve o revise, ibutang human_review_status = completed. Kung dili matino, unresolved ug bilinang blanko ang human_final_label.

REVIEWER B — REV-B-001 / Bimbo Cortel
1. Susiha kung sakto ug natural ang kahulugan sa English, Tagalog, Cebuano/Bisaya, o mixed nga text.
2. Susiha ang negation, kinsa o unsa ang subject, ug kung karon ba o karaan ang panghitabo.
3. Kumpirmahi o usba ang severity suggestion base sa tibuok konteksto.
4. Kung dili kasaligan ang kahulugan, ibutang unresolved ug bilinang blanko ang human_final_label.
5. Isulod lamang ang reviewer identity ug oras human sa aktuwal nga review.

MUBONG LABEL DEFINITIONS
minor: Walay injury, o klarong superficial, controlled, ug low-risk nga injury ubos sa Policy B.
serious: Klarong injury o impairment nga labaw sa superficial Minor, apan walay gisulti nga immediate life threat.
critical: Immediate o posibleng immediate nga hulga sa kinabuhi; kinahanglan paspas nga human verification.
needs_review: Kulang o materially ambiguous ang text aron makapili og severity.

Ang Critical dili pasabot nga confirmed death. Ang fatality wording kinahanglan i-record isip reported/unverified hangtod ma-verify sa tawo.
"""
    (destination / "SIMPLE_RESPONDER_GUIDE.txt").write_text(guide, encoding="utf-8")

    plan = f"""HUMAN CONFIRMATION IMPORT PLAN — {RUBRIC}

Current action: DO NOT IMPORT. Both confirmation sheets contain blank human-response fields.

After real reviews are returned:
1. Preserve each submitted file byte-for-byte in a timestamped, read-only submission directory.
2. Record SHA-256, file size, source reviewer, received time, and rubric version without changing the original submission.
3. Validate allowed values, required fields, reviewer identity, timezone-aware timestamps, source text/alias integrity, and unresolved rows with blank final labels.
4. Compare A and B by candidate_id/neutral_alias while keeping the untouched AI draft separate.
5. Queue all disagreements and required critical/language/policy triggers for a real, named adjudicator; never auto-resolve them.
6. Link reviewed decisions to scenario-family evidence only after independent family review; preserve candidate and annotation group provenance.
7. Export a new versioned reviewed batch containing only cases that satisfy both review and adjudication rules. Record its hash and eligibility receipt.
8. Retain AI_PRE_REVIEW_DRAFT.csv as AI provenance. Never rewrite it as a human worksheet or ground truth.

No row becomes human-reviewed merely because a responder opens the file or accepts a batch. A completed decision requires an actual reviewer action and the required identity, evidence, status, rubric, and timestamp fields.
"""
    (destination / "HUMAN_CONFIRMATION_IMPORT_PLAN.txt").write_text(plan, encoding="utf-8")
    return {
        "rows": len(rows),
        "label_distribution": dict(Counter(row["ai_suggested_label"] for row in rows)),
        "human_fields_prefilled": 0,
        "files": [path.name for path in sorted(destination.iterdir()) if path.is_file()],
    }


def prepare_experiment(experiment: Path) -> dict[str, object]:
    if experiment.exists():
        raise FileExistsError(f"Experiment directory already exists: {experiment}")
    checkpoint_inventory = file_inventory(CHECKPOINTS)
    experiment.mkdir(parents=True)
    rows = read_csv(SOURCE)
    expected = ["case_id", "group_id", "language", "text", "label", "review_status", "reviewer", "notes"]
    if len(rows) != 400 or list(rows[0]) != expected:
        raise ValueError("Working dataset is not the expected 400-row schema")
    if any(row["review_status"] != "draft" for row in rows):
        raise ValueError("This stage expects all working labels to remain draft")

    authorization = {
        "client_approved_research_training": True,
        "client_approved_provisional_evaluation": True,
        "approval_source": "project_owner_reported_client_approval",
        "approver_identity": "not_provided",
        "approval_date": "2026-10-01",
        "authorization_scope": "offline_research_training_and_provisional_evaluation_only",
        "does_not_authorize": [
            "production_deployment", "autonomous_dispatch_decisions", "clinical_validation",
            "confirmed_human_reviewed_ground_truth", "active_checkpoint_promotion", "replacement_of_responder_judgment",
        ],
        "human_review_complete": False,
        "reviewer_a_row_level_review_complete": False,
        "reviewer_b_row_level_review_complete": False,
        "reviewed_eligible_cases": 0,
        "reviewed_milestone_target": 800,
        "training_data_status": "synthetic_provisional_unreviewed",
        "approved_for_deployment": False,
        "deployment_ready": False,
        "clinical_validation_complete": False,
        "active_checkpoint_change_authorized": False,
    }
    write_json(experiment / "authorization.json", authorization)

    snapshot = [{
        "case_id": row["case_id"], "group_id": row["group_id"], "language": row["language"], "text": row["text"],
        "source_label": row["label"], "source_review_status": row["review_status"], "training_label": row["label"],
        "label_provenance": "synthetic_provisional_source_label", "human_reviewed": "false",
        "training_eligibility_scope": "client_approved_offline_research_experiment_only",
    } for row in rows]
    write_csv(experiment / "provisional_training_labels.csv", list(snapshot[0]), snapshot)

    receipt = {
        "created_at": datetime.now().astimezone().isoformat(),
        "source_path": SOURCE.relative_to(ROOT).as_posix(),
        "source_sha256": sha256(SOURCE),
        "row_count": len(rows),
        "unique_normalized_text_count": len({normalized(row["text"]) for row in rows}),
        "group_count": len({row["group_id"] for row in rows}),
        "label_distribution": dict(sorted(Counter(row["label"] for row in rows).items())),
        "language_distribution": dict(sorted(Counter(row["language"] for row in rows).items())),
        "length_distribution": dict(sorted(Counter(length_bucket(row["text"]) for row in rows).items())),
        "word_count_summary": {
            "minimum": min(len(row["text"].split()) for row in rows),
            "maximum": max(len(row["text"].split()) for row in rows),
            "mean": round(sum(len(row["text"].split()) for row in rows) / len(rows), 4),
        },
        "review_status_distribution": dict(sorted(Counter(row["review_status"] for row in rows).items())),
        "schema": expected,
        "rubric_context": RUBRIC,
        "authorization_context": authorization,
        "labels_are_human_reviewed": False,
        "labels_are_synthetic_provisional": True,
        "duplicate_or_derived_exports_added": False,
    }
    write_json(experiment / "dataset_receipt.json", receipt)

    before = {
        "created_at": datetime.now().astimezone().isoformat(),
        "stage": "before_provisional_training",
        "protected_files": {
            SOURCE.relative_to(ROOT).as_posix(): sha256(SOURCE),
            CANDIDATES.relative_to(ROOT).as_posix(): sha256(CANDIDATES),
            WORKSHEET_A.relative_to(ROOT).as_posix(): sha256(WORKSHEET_A),
            WORKSHEET_B.relative_to(ROOT).as_posix(): sha256(WORKSHEET_B),
            ACTIVE_CONFIG.relative_to(ROOT).as_posix(): sha256(ACTIVE_CONFIG),
        },
        "existing_checkpoint_inventory": checkpoint_inventory,
        "existing_checkpoint_file_count": len(checkpoint_inventory),
        "authorization": authorization,
    }
    write_json(experiment / "pre_training_integrity_receipt.json", before)
    return {"experiment": str(experiment), "dataset": receipt, "checkpoint_files_before": len(checkpoint_inventory)}


def main() -> int:
    if len(sys.argv) != 2:
        raise SystemExit("usage: prepare_provisional_research_stage.py EXPERIMENT_DIRECTORY")
    experiment = Path(sys.argv[1]).resolve()
    expected_parent = CHECKPOINTS.resolve()
    if experiment.parent != expected_parent or not re.fullmatch(r"text_provisional_synthetic_research_20261001_\d{6}", experiment.name):
        raise ValueError("Experiment must be a direct timestamped child of artifacts/checkpoints")
    ai = prepare_ai_assisted()
    exp = prepare_experiment(experiment)
    print(json.dumps({"ai_assisted": ai, "experiment": exp}, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
