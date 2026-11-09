"""Private, scheduled feedback conversion and candidate-only retraining.

Never modifies a live selector or grants deployment approval. Human-reviewed
snapshots are data, never commands. Missing coverage or independent evidence
produces an explicit waiting state instead of a claimed accuracy result.
"""
from __future__ import annotations

import argparse
from contextlib import contextmanager
import csv
from datetime import datetime, timezone
import hashlib
import json
import math
import os
from pathlib import Path
import re
import signal
from threading import Event, current_thread, main_thread
import uuid

from .incident_types import INCIDENT_TYPES, normalize_incident_type
from .text_training import LABELS, LANGUAGES, CATEGORY_COLUMNS, digest, load_dataset, normalized_text

MAX_EXPORT_BYTES = 25_000_000
MAX_EXAMPLES = 50_000
CONTACT = re.compile(r"[\w.+-]+@[\w.-]+\.[a-z]{2,}|(?:https?://|www\.)\S+|(?<!\w)(?:\+?\d[\d ()-]{7,}\d)(?!\w)", re.I)
DEMO = re.compile(r"\b(?:demo only|simulated|simulation|test report|for testing|training example)\b", re.I)
EXCLUSION_REASONS = (
    "unsupported_schema", "not_explicitly_approved", "invalid_description",
    "snapshot_hash_mismatch", "contact_or_demo_text", "unreviewed_label_or_language",
    "invalid_category_or_review_time", "missing_review_provenance", "duplicate_text",
    "conflicting_exact_text_incident_types", "conflicting_exact_text_labels",
    "conflicting_source_family_incident_types", "conflicting_source_family_labels",
)


def fingerprint(value) -> str:
    return hashlib.sha256(json.dumps(value, sort_keys=True, ensure_ascii=False, separators=(",", ":")).encode()).hexdigest()


def write_json(path: Path, value) -> None:
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    temporary = path.with_name(path.name + "." + uuid.uuid4().hex + ".tmp")
    try:
        temporary.write_text(json.dumps(value, ensure_ascii=False, indent=2, allow_nan=False) + "\n", encoding="utf-8")
        temporary.replace(path)
    finally:
        temporary.unlink(missing_ok=True)


def status_summary(result: dict) -> dict:
    """Bounded admin projection; per-example provenance stays in private receipts.

    PHP reads this file with a 64-KiB limit and depth 16. Enumerated coverage and
    reason keys keep its size independent of the number of feedback families.
    """
    def count(value):
        return value if type(value) is int and 0 <= value <= 100_000_000 else None

    conversion = result.get("conversion", {})
    excluded = conversion.get("excluded", {})
    status = result.get("status", "unavailable")
    summary = {
        "schema_version": 1,
        "status": status if isinstance(status, str) and re.fullmatch(r"[a-z_]{1,80}", status) else "unavailable",
        "new_examples": count(result.get("new_examples")),
        "changed_examples": count(result.get("changed_examples")),
        "removed_examples": count(result.get("removed_examples")),
        "conversion": {"eligible": count(conversion.get("eligible")),
                       "excluded": {key: count(excluded[key]) for key in EXCLUSION_REASONS if key in excluded}},
        "coverage": {category: {language: {label: count(result.get("coverage", {}).get(category, {}).get(language, {}).get(label))
                     for label in LABELS} for language in LANGUAGES} for category in INCIDENT_TYPES},
        "approved_for_deployment": False, "activated": False,
    }
    if isinstance(result.get("acceptance_gate"), dict):
        gate = result["acceptance_gate"]
        summary["acceptance_gate"] = {
            "meets_configured_targets": gate.get("meets_configured_targets") is True,
            "eligible_for_manual_release_review": gate.get("eligible_for_manual_release_review") is True,
            "approved_for_deployment": False, "activated": False,
        }
    return summary


@contextmanager
def exclusive_run(directory: Path):
    """OS lock is released even if a scheduled worker is killed."""
    directory.mkdir(parents=True, exist_ok=True, mode=0o700)
    with (directory / "runner.lock").open("a+b") as handle:
        if handle.tell() == 0:
            handle.write(b"0")
            handle.flush()
        handle.seek(0)
        if os.name == "nt":
            import msvcrt
            try:
                msvcrt.locking(handle.fileno(), msvcrt.LK_NBLCK, 1)
            except OSError:
                yield False
                return
            try:
                yield True
            finally:
                handle.seek(0)
                msvcrt.locking(handle.fileno(), msvcrt.LK_UNLCK, 1)
        else:
            import fcntl
            try:
                fcntl.flock(handle.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                yield False
                return
            try:
                yield True
            finally:
                fcntl.flock(handle.fileno(), fcntl.LOCK_UN)


def reviewed_feedback(path: Path) -> tuple[list[dict], dict]:
    """Validate a complete current export; incomplete/unapproved data is excluded.

    The exporter must omit superseded/revoked reviews. No old snapshot is carried
    forward here. Contact screening is conservative, not a general name detector;
    explicit human de-identification attestation remains necessary.
    """
    if path.stat().st_size > MAX_EXPORT_BYTES:
        raise ValueError("Feedback export exceeds the configured size limit")
    candidates, rejected, seen = [], {}, set()
    def reject(reason):
        rejected[reason] = rejected.get(reason, 0) + 1
    with path.open(encoding="utf-8-sig") as stream:
        for number, line in enumerate(stream, 1):
            if number > MAX_EXAMPLES:
                raise ValueError("Feedback export exceeds the example limit")
            if not line.strip():
                continue
            try:
                item = json.loads(line)
            except ValueError as exc:
                raise ValueError("Feedback export contains invalid JSON") from exc
            if not isinstance(item, dict) or item.get("schema_version") != 1:
                reject("unsupported_schema")
                continue
            if item.get("approved_for_training") is not True or item.get("review_scope") != "description_only":
                reject("not_explicitly_approved")
                continue
            text = item.get("description")
            if not isinstance(text, str) or not text.strip() or len(text) > 1000:
                reject("invalid_description")
                continue
            if item.get("description_sha256") != hashlib.sha256(text.encode()).hexdigest():
                reject("snapshot_hash_mismatch")
                continue
            if CONTACT.search(text) or DEMO.search(text):
                reject("contact_or_demo_text")
                continue
            label, language = item.get("label"), item.get("language")
            if label not in LABELS or language not in LANGUAGES:
                reject("unreviewed_label_or_language")
                continue
            try:
                category = normalize_incident_type(item.get("incident_type"))
                if item.get("incident_type") is None:
                    raise ValueError("Missing explicit incident type")
                reviewed_at = datetime.fromisoformat(str(item.get("reviewed_at", "")).replace("Z", "+00:00"))
                if reviewed_at.tzinfo is None or reviewed_at > datetime.now(timezone.utc):
                    raise ValueError("Invalid review time")
            except (ValueError, TypeError):
                reject("invalid_category_or_review_time")
                continue
            identity = [item.get(key) for key in ("feedback_id", "group_id", "reviewer")]
            if any(not isinstance(value, str) or not value.strip() or len(value) > 200 for value in identity):
                reject("missing_review_provenance")
                continue
            if identity[0] in seen:
                raise ValueError("Feedback export repeats an identifier")
            seen.add(identity[0])
            candidates.append({"case_id": "feedback-" + fingerprint(identity[0])[:32],
                               "group_id": "feedback-group-" + fingerprint(identity[1])[:32],
                               "language": language, "text": text.strip(), "label": label,
                               "review_status": "approved", "reviewer": identity[2],
                               "notes": "Explicit text-only training approval; review time " + reviewed_at.isoformat(),
                               "incident_type": category})
    # Union declared source families and equal texts BEFORE dropping duplicates.
    # Otherwise a duplicate can hide a relationship between two paraphrases.
    parents = {row["group_id"]: row["group_id"] for row in candidates}
    def find(group):
        while parents[group] != group:
            parents[group] = parents[parents[group]]
            group = parents[group]
        return group
    def union(left, right):
        left, right = find(left), find(right)
        parents[max(left, right)] = min(left, right)
    by_text = {}
    for row in candidates:
        key = normalized_text(row["text"])
        previous = by_text.setdefault(key, row)
        union(previous["group_id"], row["group_id"])
    families, exact = {}, {}
    for row in candidates:
        families.setdefault(find(row["group_id"]), []).append(row)
        exact.setdefault(normalized_text(row["text"]), []).append(row)
    invalid = {}
    for family, items in families.items():
        if any(len({row["incident_type"] for row in exact[normalized_text(item["text"])]}) > 1 for item in items):
            invalid[family] = "conflicting_exact_text_incident_types"
        elif any(len({row["label"] for row in exact[normalized_text(item["text"])]}) > 1 for item in items):
            invalid[family] = "conflicting_exact_text_labels"
        elif len({row["incident_type"] for row in items}) > 1:
            invalid[family] = "conflicting_source_family_incident_types"
        elif len({row["label"] for row in items}) > 1:
            invalid[family] = "conflicting_source_family_labels"
    unique, rows, family_groups = set(), [], {}
    for row in sorted(candidates, key=lambda r: r["case_id"]):
        family = find(row["group_id"])
        text_key = normalized_text(row["text"])
        if family in invalid:
            reject(invalid[family])
            continue
        family_groups.setdefault(family, set()).add(row["group_id"])
        if text_key in unique:
            reject("duplicate_text")
            continue
        unique.add(text_key)
        row["group_id"] = family
        rows.append(row)
    return rows, {"eligible": len(rows), "excluded": rejected,
                  "family_groups": {key: sorted(groups) for key, groups in family_groups.items()},
                  "independence_note": "Declared source families and exact texts are grouped; undeclared semantic/source relationships still require human review."}


def write_dataset(path: Path, rows: list[dict]) -> None:
    temporary = path.with_name(path.name + "." + uuid.uuid4().hex + ".tmp")
    try:
        with temporary.open("w", encoding="utf-8-sig", newline="") as stream:
            writer = csv.DictWriter(stream, fieldnames=CATEGORY_COLUMNS)
            writer.writeheader()
            writer.writerows(rows)
        temporary.replace(path)
    finally:
        temporary.unlink(missing_ok=True)


def coverage(rows: list[dict]) -> dict:
    return {category: {language: {label: sum(row.get("incident_type", "road_accident") == category
                    and row["language"] == language and row["label"] == label for row in rows)
                    for label in LABELS} for language in LANGUAGES} for category in INCIDENT_TYPES}


def train_candidate(dataset: Path, output: Path) -> dict:
    # The baseline is deliberately small and CPU-only; never invoke transformer training.
    from .text_training import build_parser, run_baseline
    args = build_parser().parse_args(["baseline", "--data", str(dataset), "--output", str(output)])
    return run_baseline(args)


def inherit_rule_profile(candidate: Path, base: Path) -> None:
    """Retain an explicit baseline policy only within newly trained capabilities.

    No approval, threshold, weights or unreviewed source examples are inherited.
    A road-only baseline cannot silently opt future candidates into wider rules.
    """
    from .incident_types import metadata_contract
    from .text_inference import MULTIINCIDENT_RULE_PROFILE, text_rule_contract
    path = base / 'metadata.json'
    if not path.is_file():
        return
    baseline = json.loads(path.read_text(encoding='utf-8'))
    profile, scope = text_rule_contract(baseline)
    if profile != MULTIINCIDENT_RULE_PROFILE:
        return
    target = candidate / 'metadata.json'
    metadata = json.loads(target.read_text(encoding='utf-8'))
    _, supported = metadata_contract(metadata)
    selected = [category for category in scope if category in supported]
    if selected:
        metadata.update(text_rule_profile=profile, text_rule_incident_types=selected)
        text_rule_contract(metadata)
        write_json(target, metadata)


def policy_predict(predictor, row: dict) -> str:
    from .incident_types import LEGACY_INPUT_FORMAT, metadata_contract, normalize_incident_type
    from .text_inference import apply_text_rule_profile
    category = normalize_incident_type(row.get("incident_type"))
    input_format, supported = metadata_contract(predictor.metadata)
    if category not in supported:
        return "needs_review"
    result = (predictor.predict(row["text"]) if input_format == LEGACY_INPUT_FORMAT
              else predictor.predict(row["text"], incident_type=category))
    result = apply_text_rule_profile(row["text"], category, result, predictor.metadata,
                                     rules_enabled=True)
    return result["severity"] or "needs_review"


def decision_metrics(rows: list[dict], predictions: list[str]) -> dict:
    from .text_training import _classification_metrics
    actual = [row["label"] for row in rows]
    result = _classification_metrics(actual, predictions)
    accepted = [i for i, label in enumerate(predictions) if label != "needs_review"]
    urgent = [i for i, label in enumerate(actual) if label in ("serious", "critical")]
    rank = {"minor": 0, "serious": 1, "critical": 2}
    result.update(coverage=len(accepted) / len(rows) if rows else None,
                  urgent_review_count=sum(predictions[i] == "needs_review" for i in urgent),
                  urgent_support=len(urgent),
                  urgent_lower_category_count=sum(predictions[i] in rank and rank[predictions[i]] < rank[actual[i]] for i in urgent))
    return result


def evaluate_candidate(model: Path, base: Path, rows: list[dict]) -> dict:
    from .text_inference import TfidfSeverityPredictor, text_rule_capabilities
    candidate = TfidfSeverityPredictor(model, allow_experimental=True)
    previous = TfidfSeverityPredictor(base, allow_experimental=True)
    predicted = [policy_predict(candidate, row) for row in rows]
    old = [policy_predict(previous, row) for row in rows]
    def slice_metrics(indices):
        subset = [rows[i] for i in indices]
        return {"candidate": decision_metrics(subset, [predicted[i] for i in indices]),
                "baseline": decision_metrics(subset, [old[i] for i in indices])}
    candidate_policy = text_rule_capabilities(candidate.metadata, rules_enabled=True)
    baseline_policy = text_rule_capabilities(previous.metadata, rules_enabled=True)
    return {"policy": "Category-aware classifier plus each artifact's explicitly configured rule profile",
            "rule_version": candidate_policy["text_rules_version"],
            "candidate_policy": candidate_policy, "baseline_policy": baseline_policy,
            "supported_incident_types": candidate.metadata.get("supported_incident_types", ["road_accident"]),
            "baseline_supported_incident_types": previous.metadata.get("supported_incident_types", ["road_accident"]),
            "overall": slice_metrics(range(len(rows))),
            "per_incident_type": {kind: slice_metrics([i for i, row in enumerate(rows) if row.get("incident_type", "road_accident") == kind]) for kind in INCIDENT_TYPES},
            "per_language": {lang: slice_metrics([i for i, row in enumerate(rows) if row["language"] == lang]) for lang in LANGUAGES},
            "per_category_language": {kind: {lang: slice_metrics([i for i, row in enumerate(rows)
                   if row.get("incident_type", "road_accident") == kind and row["language"] == lang]) for lang in LANGUAGES} for kind in INCIDENT_TYPES}}


def acceptance_gate(evaluation: dict, *, target: float, min_cell: int, critical_recall: float) -> dict:
    failures = []
    def check(name, pair, minimum, compare=False):
        current, baseline = pair["candidate"], pair["baseline"]
        if current["count"] < minimum:
            failures.append(name + ":insufficient_test_examples")
            return
        if current["accuracy"] is None or current["accuracy"] < target:
            failures.append(name + ":accuracy_below_target")
        if current["macro_f1"] is None or current["macro_f1"] < target:
            failures.append(name + ":macro_f1_below_target")
        critical = current["per_class"]["critical"]
        if critical["support"] < 1:
            failures.append(name + ":missing_critical_examples")
        elif critical["recall"] < critical_recall:
            failures.append(name + ":critical_recall_below_target")
        if current["urgent_lower_category_count"]:
            failures.append(name + ":urgent_lower_category_assignment")
        if compare and baseline["count"] and current["accuracy"] < baseline["accuracy"]:
            failures.append(name + ":regresses_baseline_accuracy")
    check("overall", evaluation["overall"], min_cell)
    for category in INCIDENT_TYPES:
        if category not in evaluation["supported_incident_types"]:
            failures.append(category + ":not_supported_by_candidate")
        check(category, evaluation["per_incident_type"][category], min_cell,
              category in evaluation["baseline_supported_incident_types"])
        for language in LANGUAGES:
            check(category + "/" + language, evaluation["per_category_language"][category][language], min_cell)
    for language in LANGUAGES:
        check(language, evaluation["per_language"][language], min_cell)
    return {"meets_configured_targets": not failures, "failures": failures,
            "target_accuracy_and_macro_f1": target, "critical_recall_target": critical_recall,
            "minimum_examples_per_category_language": min_cell,
            "eligible_for_manual_release_review": not failures,
            "approved_for_deployment": False, "activated": False,
            "note": "Engineering gates, not a clinical approval or a guaranteed field accuracy. Review provenance and sample adequacy remain human responsibilities."}


def exposure(rows: list[dict], family_groups: dict | None = None) -> dict:
    """Opaque identity/text/source tokens; no descriptions enter the ledger."""
    groups = {row["group_id"] for row in rows}
    for members in (family_groups or {}).values():
        groups.update(members)
    # Holdout authors may use the supplied opaque source ID or its converted ID.
    # Both namespaces must compare equal without retaining raw source identifiers.
    return {"case_hashes": sorted({token for row in rows for token in
                    (digest(row["case_id"]), digest("feedback-" + fingerprint(row["case_id"])[:32]))}),
            "text_hashes": sorted({digest(normalized_text(row["text"])) for row in rows}),
            "group_hashes": sorted({token for group in groups for token in
                    (digest(group), digest("feedback-group-" + fingerprint(group)[:32]))})}


def merge_exposure(*ledgers: dict) -> dict:
    return {key: sorted(set().union(*(set(ledger.get(key, [])) for ledger in ledgers)))
            for key in ("case_hashes", "text_hashes", "group_hashes")}


def overlaps(left: dict, right: dict) -> bool:
    return any(set(left.get(key, [])) & set(right.get(key, []))
               for key in ("case_hashes", "text_hashes", "group_hashes"))


def bound_holdout(path: Path) -> tuple[list[dict], str]:
    # Hash exact bytes, not just metadata: a provenance attestation cannot silently
    # travel to a changed CSV. These attestations are supplied by a human reviewer.
    if path.stat().st_size > MAX_EXPORT_BYTES:
        raise ValueError("Holdout exceeds the configured size limit")
    content_hash = hashlib.sha256(path.read_bytes()).hexdigest()
    rows = load_dataset(path)
    if len(rows) > MAX_EXAMPLES:
        raise ValueError("Holdout exceeds the configured example limit")
    if hashlib.sha256(path.read_bytes()).hexdigest() != content_hash:
        raise ValueError("Holdout changed while it was read")
    provenance = json.loads(Path(str(path) + ".manifest.json").read_text(encoding="utf-8"))
    if (not isinstance(provenance, dict) or provenance.get("schema_version") != 1
            or provenance.get("independent_review") is not True or provenance.get("ai_blinded") is not True
            or not isinstance(provenance.get("reviewer"), str) or not provenance["reviewer"].strip()
            or provenance.get("dataset_sha256") != content_hash):
        raise ValueError("Holdout requires a matching dataset_sha256 and accountable independently reviewed, AI-blinded provenance")
    return rows, content_hash


def validate_holdout(rows: list[dict], key: str, training: list[dict], base: Path,
                     used: list[str], *, prior_models=(), prior_exposure: dict | None = None,
                     family_groups: dict | None = None) -> None:
    from .text_review import check_fresh_holdout
    check_fresh_holdout(rows, list(dict.fromkeys([base, *prior_models])))
    if key in used or overlaps(exposure(rows), merge_exposure(exposure(training, family_groups), prior_exposure or {})):
        raise ValueError("Holdout overlaps training or previously exposed examples; supply a fresh acceptance set")


def independent_holdout(path: Path, training: list[dict], base: Path, used: list[str],
                        **checks) -> tuple[list[dict], str]:
    rows, key = bound_holdout(path)
    validate_holdout(rows, key, training, base, used, **checks)
    return rows, key


def artifact_identity(path: Path) -> dict:
    return {"directory": str(path.resolve()),
            "metadata_sha256": hashlib.sha256((path / "metadata.json").read_bytes()).hexdigest(),
            "artifact_sha256": hashlib.sha256((path / "baseline.json").read_bytes()).hexdigest(),
            "split_manifest_sha256": hashlib.sha256((path / "split_manifest.json").read_bytes()).hexdigest()}


def policy_identity(path: Path) -> dict:
    """Bind cached decisions to both the profile/version and its actual code."""
    from .text_inference import MULTIINCIDENT_RULE_PROFILE, text_rule_capabilities
    metadata = json.loads((path / "metadata.json").read_text(encoding="utf-8"))
    capabilities = text_rule_capabilities(metadata, rules_enabled=True)
    files = ["text_cues.py"]
    if capabilities["text_rule_profile"] == MULTIINCIDENT_RULE_PROFILE:
        # The expanded profile deliberately reuses the legacy context policy.
        files.append("multiincident_cues.py")
    return {"capabilities": capabilities,
            "rule_code_sha256": {name: digest(Path(__file__).with_name(name).read_text(encoding="utf-8"))
                                 for name in files}}


def inspect_candidate(candidate: Path, state: dict, state_path: Path, rows: list[dict], args, result: dict) -> None:
    """A holdout may arrive after training; never retrain merely to inspect it."""
    if not args.holdout:
        return
    holdout, holdout_key = bound_holdout(Path(args.holdout))
    base = Path(args.base_model)
    context = {"candidate": artifact_identity(candidate), "baseline": artifact_identity(base),
               "holdout_sha256": holdout_key,
               "candidate_policy": policy_identity(candidate), "baseline_policy": policy_identity(base),
               "inference_code_sha256": digest(Path(__file__).with_name("text_inference.py").read_text(encoding="utf-8")),
               "evaluation_code_sha256": digest(Path(__file__).read_text(encoding="utf-8")),
               "target_accuracy": args.target_accuracy, "min_cell": args.min_cell,
               "critical_recall": args.critical_recall}
    cache_key = fingerprint(context)
    cache_path = candidate / "evaluations" / (cache_key + ".json")
    if cache_path.exists():
        cached = json.loads(cache_path.read_text(encoding="utf-8"))
        if cached.get("context") != context:
            raise ValueError("Candidate evaluation cache does not match its inputs")
        evaluation, gate = cached["evaluation"], cached["acceptance_gate"]
        result["evaluation_cached"] = True
    else:
        prior_models = [path.parent for path in (state_path.parent / "candidates").glob("*/split_manifest.json")]
        prior = merge_exposure(state.get("training_exposure", {}), state.get("holdout_exposure", {}))
        validate_holdout(holdout, holdout_key, rows, base, state.get("used_holdouts", []),
                         prior_models=prior_models, prior_exposure=prior,
                         family_groups=result.get("conversion", {}).get("family_groups", {}))
        # Consume exposure before prediction; interrupted/failed inspection must
        # never offer the same data as an allegedly fresh future acceptance set.
        state["holdout_exposure"] = merge_exposure(state.get("holdout_exposure", {}), exposure(holdout))
        state.setdefault("used_holdouts", []).append(holdout_key)
        write_json(state_path, state)
        evaluation = evaluate_candidate(candidate, base, holdout)
        gate = acceptance_gate(evaluation, target=args.target_accuracy, min_cell=args.min_cell, critical_recall=args.critical_recall)
        if artifact_identity(candidate) != context["candidate"] or artifact_identity(base) != context["baseline"]:
            raise ValueError("Evaluation model files changed during inspection")
        if policy_identity(candidate) != context["candidate_policy"] or policy_identity(base) != context["baseline_policy"]:
            raise ValueError("Evaluation rule policy changed during inspection")
        write_json(cache_path, {"context": context, "evaluation": evaluation, "acceptance_gate": gate})
    write_json(candidate / "independent_evaluation.json", evaluation)
    write_json(candidate / "acceptance_gate.json", gate)
    result.update(status="candidate_ready_for_release_review" if gate["meets_configured_targets"] else "candidate_failed_acceptance",
                  acceptance_gate=gate)


def validate_options(args) -> None:
    for name in ("target_accuracy", "critical_recall"):
        value = getattr(args, name)
        if not math.isfinite(value) or not 0 < value <= 1:
            raise ValueError(name + " must be finite, above zero and at most one")
    if args.target_accuracy < 0.8 or args.min_new < 1 or args.min_cell < 1:
        raise ValueError("Accuracy target must be at least 0.8; example limits must be positive")


def run(args, *, stop_event: Event | None = None) -> dict:
    validate_options(args)
    state_dir = Path(args.state_dir).resolve()
    with exclusive_run(state_dir) as acquired:
        if not acquired:
            return {"status": "already_running", "activated": False}
        state_path = state_dir / "state.json"
        state = json.loads(state_path.read_text(encoding="utf-8")) if state_path.exists() else {}
        rows, conversion = reviewed_feedback(Path(args.feedback))
        dataset = state_dir / "reviewed_feedback.csv"
        write_dataset(dataset, rows)
        ids = {row["case_id"] for row in rows}
        trained_ids = set(state.get("trained_feedback_ids", []))
        # Duplicate suppression must not hide newly declared source relationships.
        # A new alias can invalidate holdout independence without changing CSV rows.
        records = {row["case_id"]: fingerprint({"row": row,
                    "family_groups": conversion["family_groups"][row["group_id"]]}) for row in rows}
        previous_records = state.get("trained_record_fingerprints", {})
        changed = {key for key in ids & trained_ids if previous_records.get(key) != records[key]}
        snapshot = fingerprint({"rows": rows, "family_groups": conversion["family_groups"]})
        result = {"schema_version": 1, "status": "prepared", "conversion": conversion,
                  "coverage": coverage(rows), "new_examples": len(ids - trained_ids),
                  "changed_examples": len(changed), "removed_examples": len(trained_ids - ids), "snapshot": snapshot,
                  "approved_for_deployment": False, "activated": False}
        write_json(state_dir / "conversion_receipt.json", {
            "schema_version": 1, "snapshot": snapshot, "conversion": conversion,
            "approved_for_deployment": False, "activated": False,
        })
        # Revocation/correction is effective even in conversion-only mode or while
        # a new batch is too small. A withdrawn candidate is never reactivated.
        if state.get("candidate_path") and (state.get("last_candidate_snapshot") != snapshot
                or (Path(state["candidate_path"]) / "feedback_snapshot_superseded.json").exists()):
            previous_candidate = Path(state["candidate_path"])
            write_json(previous_candidate / "feedback_snapshot_superseded.json",
                       {"status": "superseded", "current_feedback_snapshot": snapshot,
                        "approved_for_deployment": False,
                        "note": "Feedback changed or was withdrawn; this previous candidate is not current."})
            gate_path = previous_candidate / "acceptance_gate.json"
            if gate_path.exists():
                try:
                    old_gate = json.loads(gate_path.read_text(encoding="utf-8"))
                except (ValueError, OSError):
                    old_gate = {}
                if not isinstance(old_gate, dict):
                    old_gate = {}
                old_gate.update(superseded=True, feedback_snapshot_current=False,
                                eligible_for_manual_release_review=False, approved_for_deployment=False, activated=False)
                write_json(gate_path, old_gate)
            state["superseded_candidate_path"] = str(previous_candidate)
            state["superseded_candidate_snapshot"] = state.get("last_candidate_snapshot")
            state["candidate_path"] = None
            write_json(state_path, state)
        if stop_event is not None and stop_event.is_set():
            result["status"] = "stopping"
        elif not args.train:
            result["status"] = "prepared_training_not_requested"
        elif state.get("candidate_path") and state.get("last_candidate_snapshot") == snapshot:
            result["status"] = "no_new_snapshot"
            if args.holdout and state.get("candidate_path"):
                try:
                    result["candidate"] = state["candidate_path"]
                    inspect_candidate(Path(state["candidate_path"]), state, state_path, rows, args, result)
                except (ValueError, OSError, KeyError, ImportError, RuntimeError) as error:
                    result.update(status="candidate_blocked", reason_type=type(error).__name__,
                                  reason="Independent evaluation requires a fresh validated holdout and provenance; no live model was changed.")
        elif not rows or (len(ids - trained_ids) < args.min_new and not (trained_ids - ids) and not changed
                         and state.get("superseded_candidate_snapshot") != snapshot):
            result["status"] = "waiting_for_reviewed_examples"
        else:
            # One baseline attempt per validated snapshot. Failures wait for new
            # feedback instead of starting a costly training loop every hour.
            if state.get("last_attempt_snapshot") == snapshot and state.get("last_attempt_status") in {"running", "failed"}:
                result["status"] = "waiting_for_changed_snapshot"
            else:
                state["last_attempt_snapshot"] = snapshot
                state["last_attempt_status"] = "running"
                state["training_exposure"] = merge_exposure(state.get("training_exposure", {}),
                                                              exposure(rows, conversion["family_groups"]))
                write_json(state_path, state)
                candidate = state_dir / "candidates" / (datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ") + "-" + snapshot[:12] + "-" + uuid.uuid4().hex[:8])
                try:
                    for variable in ("OMP_NUM_THREADS", "OPENBLAS_NUM_THREADS", "MKL_NUM_THREADS"):
                        os.environ.setdefault(variable, "1")
                    train_candidate(dataset, candidate)
                    inherit_rule_profile(candidate, Path(args.base_model))
                    result.update(status="candidate_trained_waiting_for_independent_holdout", candidate=str(candidate))
                    write_json(candidate / "feedback_receipt.json", {"snapshot": snapshot, "feedback_case_ids": sorted(ids),
                              "policy": "approved descriptions only; no live activation", "coverage": result["coverage"],
                              "conversion": conversion,
                              "exposure": exposure(rows, conversion["family_groups"])})
                    state.update(last_candidate_snapshot=snapshot, trained_feedback_ids=sorted(ids),
                                 trained_record_fingerprints=records, candidate_path=str(candidate), last_attempt_status="complete")
                    write_json(state_path, state)
                    if stop_event is None or not stop_event.is_set():
                        inspect_candidate(candidate, state, state_path, rows, args, result)
                except (ValueError, OSError, KeyError, ImportError, RuntimeError) as error:
                    # Detailed input text is never emitted by the runner.
                    result.update(status="candidate_blocked", reason_type=type(error).__name__)
                    result["reason"] = "Candidate preparation or independent evaluation failed validation; inspect data coverage and the private run artifacts."
                    if state.get("last_attempt_status") != "complete":
                        state["last_attempt_status"] = "failed"
                write_json(state_path, state)
        write_json(state_dir / "latest_status.json", status_summary(result))
        return result


def build_parser():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--feedback", required=True)
    parser.add_argument("--state-dir", required=True)
    parser.add_argument("--base-model", required=True)
    parser.add_argument("--min-new", type=int, default=50)
    parser.add_argument("--target-accuracy", type=float, default=0.8)
    parser.add_argument("--critical-recall", type=float, default=0.95)
    parser.add_argument("--min-cell", type=int, default=5)
    parser.add_argument("--holdout")
    parser.add_argument("--train", action="store_true")
    parser.add_argument("--watch", action="store_true", help="Run scheduled private preparation; never activate a model")
    parser.add_argument("--interval", type=int, default=3600, help="Seconds between watch runs (minimum 60)")
    return parser


def print_summary(result: dict) -> None:
    # Never print exception messages, private text, paths, or per-case identities.
    print(json.dumps({key: result.get(key) for key in
                     ("status", "new_examples", "changed_examples", "removed_examples", "activated")},
                     allow_nan=False), flush=True)


@contextmanager
def shutdown_signals(stop: Event):
    previous = {}
    if current_thread() is main_thread():
        for signum in (signal.SIGTERM, signal.SIGINT):
            previous[signum] = signal.getsignal(signum)
            signal.signal(signum, lambda _number, _frame: stop.set())
    try:
        yield
    finally:
        for signum, handler in previous.items():
            signal.signal(signum, handler)


def watch(args, *, stop_event: Event | None = None) -> int:
    """Bounded CPU work plus interruptible waits; finish the current atomic step."""
    if args.interval < 60:
        raise ValueError("Watch interval must be at least 60 seconds")
    validate_options(args)
    stop = stop_event if stop_event is not None else Event()
    with shutdown_signals(stop):
        try:
            while not stop.is_set():
                try:
                    if not Path(args.feedback).is_file():
                        result = {"status": "waiting_for_feedback_export", "activated": False}
                    else:
                        result = run(args, stop_event=stop)
                except (ValueError, OSError, KeyError, ImportError, RuntimeError):
                    result = {"status": "input_validation_failed", "activated": False}
                print_summary(result)
                if stop.wait(args.interval):
                    break
        except KeyboardInterrupt:
            stop.set()
    print_summary({"status": "stopped", "activated": False})
    return 0


def main(argv=None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)
    try:
        if args.watch:
            return watch(args)
        result = run(args)
    except (ValueError, OSError, KeyError) as error:
        parser.exit(2, "Feedback learning input/configuration validation failed (" + type(error).__name__ + ").\n")
    print_summary(result)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
