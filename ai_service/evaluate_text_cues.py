"""Diagnostic evaluation of the serving policy; never approves a release.

Historical test mode requires unchanged source data and saved test IDs.
``--external`` accepts a separate authored/synthetic diagnostic CSV, which does
not establish independently reviewed real-report performance.
"""
from __future__ import annotations

import argparse
import json
from collections import Counter
from pathlib import Path

from src.ai_service.incident_types import (INCIDENT_TYPES, LEGACY_INPUT_FORMAT,
                                          metadata_contract, normalize_incident_type)
from src.ai_service.text_inference import (TfidfSeverityPredictor, apply_text_rule_profile,
                                         category_review_payload, text_rule_capabilities)
from src.ai_service.text_training import (COLUMNS, LABELS, LANGUAGES, _classification_metrics,
                                         dataset_fingerprint, evaluation_rows,
                                         load_dataset, select_rows)


def policy_prediction(description: str, incident_type: str, predictor, *, rules_enabled: bool = True) -> tuple[dict, dict]:
    """Return model and final policy snapshots with the runtime scope gates."""
    category = normalize_incident_type(incident_type)
    input_format, supported = metadata_contract(predictor.metadata)
    if category not in supported:
        result = category_review_payload(predictor.metadata, category)
        return result, result
    model = (predictor.predict(description) if input_format == LEGACY_INPUT_FORMAT
             else predictor.predict(description, category))
    model = {**model, "incident_type": category}
    return model, apply_text_rule_profile(description, category, model, predictor.metadata,
                                          rules_enabled=rules_enabled)


def _summary(results: list[dict]) -> dict:
    actual = [row["label"] for row in results]
    routed = [row["routed_label"] for row in results]
    metrics = _classification_metrics(actual, routed)
    raw_scored = [row for row in results if row["model_probabilities"]]
    accepted = [row for row in results if row["suggestion"] is not None]
    rank = {"minor": 0, "serious": 1, "critical": 2}
    urgent = [row for row in results if row["label"] in ("serious", "critical")]

    def lower(row, key):
        return row[key] in rank and rank[row[key]] < rank[row["label"]]

    lower_count = sum(lower(row, "routed_label") for row in urgent)
    raw_urgent = [row for row in raw_scored if row["label"] in ("serious", "critical")]
    raw_lower = sum(lower(row, "model_predicted_label") for row in raw_urgent)
    metrics.update({
        "review_routed": _classification_metrics(actual, routed),
        "raw_classifier": _classification_metrics([row["label"] for row in raw_scored],
                                                   [row["model_predicted_label"] for row in raw_scored]),
        "unscored_review_count": len(results) - len(raw_scored),
        "methods": dict(Counter(row["method"] for row in results)),
        "coverage": len(accepted) / len(results) if results else None,
        "abstention_rate": 1 - len(accepted) / len(results) if results else None,
        "accepted_accuracy": sum(row["suggestion"] == row["label"] for row in accepted) / len(accepted) if accepted else None,
        "high_risk_support": len(urgent),
        "high_risk_underclassification_count": lower_count,
        "high_risk_underclassification_rate": lower_count / len(urgent) if urgent else None,
        "high_risk_undertriage_count": lower_count,
        "high_risk_undertriage_rate": lower_count / len(urgent) if urgent else None,
        "high_risk_review_count": sum(row["routed_label"] == "needs_review" for row in urgent),
        "high_risk_review_rate": sum(row["routed_label"] == "needs_review" for row in urgent) / len(urgent) if urgent else None,
        "raw_high_risk_support": len(raw_urgent),
        "raw_high_risk_undertriage_count": raw_lower,
        "raw_high_risk_undertriage_rate": raw_lower / len(raw_urgent) if raw_urgent else None,
    })
    for label in ("serious", "critical"):
        selected = [row for row in results if row["label"] == label]
        metrics[f"{label}_support"] = len(selected)
        metrics[f"{label}_lower_assignment_count"] = sum(lower(row, "routed_label") for row in selected)
        metrics[f"{label}_recall"] = metrics["per_class"][label]["recall"] if selected else None
    return metrics


def evaluate_rows(rows: list[dict], predictor, *, rules_enabled: bool = True,
                  external: bool = False) -> tuple[dict, list[dict]]:
    """Count all routed outputs; retain actual model and rule scores separately.

    Classifier metrics describe only scored rows. ``unscored_review_count`` makes
    scope abstention explicit, and rule suggestions never gain invented scores.
    """
    if not rows:
        raise ValueError("Diagnostic evaluation requires nonempty examples.")
    results = []
    for row in rows:
        if row["label"] not in LABELS or row["language"] not in LANGUAGES:
            raise ValueError("Diagnostic examples contain an unsupported label or language.")
        category = normalize_incident_type(row.get("incident_type"))
        model, decision = policy_prediction(row["text"], category, predictor, rules_enabled=rules_enabled)
        routed = decision.get("severity") or "needs_review"
        predicted = model.get("predicted_label") or "needs_review"
        if routed not in LABELS or predicted not in LABELS:
            raise ValueError("Diagnostic predictor returned an unsupported label.")
        results.append({
            "case_id": row["case_id"], "group_id": row.get("group_id"),
            "language": row["language"], "incident_type": category, "label": row["label"],
            "suggestion": decision.get("severity"), "routed_label": routed,
            "method": decision.get("assessment_method", "trained_model"),
            "confidence": decision.get("confidence"), "probabilities": decision.get("probabilities", {}),
            "responder_review_required": decision.get("responder_review_required"),
            "model_predicted_label": predicted, "model_suggestion": model.get("severity"),
            "model_confidence": model.get("confidence"), "model_probabilities": model.get("probabilities", {}),
            "model_status": model.get("status"), "model_reason": model.get("reason"),
            "model_responder_review_required": model.get("responder_review_required"),
        })
    metrics = _summary(results)
    metrics["per_language"] = {language: _summary([row for row in results if row["language"] == language])
                               for language in LANGUAGES}
    metrics["per_incident_type"] = {category: _summary([row for row in results if row["incident_type"] == category])
                                    for category in INCIDENT_TYPES}
    metrics["per_incident_type_language"] = {
        category: {language: _summary([row for row in results if row["incident_type"] == category and row["language"] == language])
                   for language in LANGUAGES} for category in INCIDENT_TYPES}
    input_format, supported = metadata_contract(predictor.metadata)
    rule_capabilities = text_rule_capabilities(predictor.metadata, rules_enabled=rules_enabled)
    metrics.update({
        **rule_capabilities,
        "rule_version": rule_capabilities["text_rules_version"], "rules_enabled": rules_enabled,
        "rules_scope": rule_capabilities["text_rules_scope"], "model_version": predictor.metadata["version"],
        "input_format": input_format, "supported_incident_types": supported,
        "review_threshold": predictor.metadata["review_threshold"], "confidence_calibrated": False,
        "evaluation_kind": "external_synthetic_diagnostic" if external else "historical_synthetic_diagnostic",
        "provenance": ("External authored/synthetic diagnostic scenarios; not independently reviewed real-report validation."
                       if external else "Historical synthetic test already exposed during development; diagnostic only, not independent validation."),
        "independent_validation": False, "approved_for_deployment": False,
        "all_data_reviewed": all(row.get("review_status") == "approved" for row in rows),
        "accuracy_denominator": "All evaluated examples; needs_review is an output class, not an excluded error.",
        "raw_classifier_denominator": "Only examples with a model probability vector; inspect unscored_review_count.",
    })
    if all(all(key in row for key in COLUMNS) for row in rows):
        metrics["dataset_fingerprint"] = dataset_fingerprint(rows)
    return metrics, results


def main(argv: list[str] | None = None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--data", required=True)
    parser.add_argument("--manifest", help="Frozen source split manifest; required in historical test mode")
    parser.add_argument("--model", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--external", action="store_true", help="Separate synthetic diagnostic CSV; never independent validation")
    parser.add_argument("--allow-draft", action="store_true")
    parser.add_argument("--allow-experimental", action="store_true")
    parser.add_argument("--no-rules", action="store_true", help="Match a runtime with language rules disabled")
    args = parser.parse_args(argv)
    if not args.external and not args.manifest:
        parser.error("--manifest is required unless --external explicitly selects a separate diagnostic CSV")
    model_path = Path(args.model)
    predictor = TfidfSeverityPredictor(model_path, allow_experimental=args.allow_experimental)
    all_rows = select_rows(load_dataset(args.data), args.allow_draft)
    overlap_check = False
    if args.external:
        manifest_path = Path(args.manifest) if args.manifest else model_path / "split_manifest.json"
        if manifest_path.exists():
            rows = evaluation_rows(all_rows, predictor.metadata, json.loads(manifest_path.read_text(encoding="utf-8")), "external")
            overlap_check = True
        else:
            rows = all_rows
    else:
        if dataset_fingerprint(all_rows) != predictor.metadata["dataset_fingerprint"]:
            raise ValueError("Diagnostic test requires the unchanged source dataset and labels.")
        manifest = json.loads(Path(args.manifest).read_text(encoding="utf-8"))
        rows = evaluation_rows(all_rows, predictor.metadata, manifest, "test")
    metrics, results = evaluate_rows(rows, predictor, rules_enabled=not args.no_rules, external=args.external)
    metrics.update({"exact_train_validation_overlap_checked": overlap_check,
                    "saved_source_fingerprint_and_test_ids_checked": not args.external})
    output = Path(args.output)
    output.mkdir(parents=True, exist_ok=False)
    for filename, payload in (("metrics.json", metrics), ("predictions.json", results)):
        (output / filename).write_bytes((json.dumps(payload, ensure_ascii=False, indent=2, allow_nan=False) + "\n").encode("utf-8"))
    print(json.dumps({"count": metrics["count"], "routed_accuracy": metrics["accuracy"],
                      "raw_accuracy": metrics["raw_classifier"]["accuracy"],
                      "per_language": {language: item["accuracy"] for language, item in metrics["per_language"].items()},
                      "per_incident_type": {category: item["accuracy"] for category, item in metrics["per_incident_type"].items()},
                      "coverage": metrics["coverage"], "underclassification": metrics["high_risk_underclassification_count"],
                      "independent_validation": False, "approved_for_deployment": False}))


if __name__ == "__main__":
    main()
