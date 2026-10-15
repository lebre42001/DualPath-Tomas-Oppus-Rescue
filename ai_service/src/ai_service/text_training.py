"""Offline, review-gated road-accident text experiments; never activates a model.

Core validation/splitting/metrics use the standard library. ML dependencies are
imported only by the command that needs them. Draft results are diagnostic only.
"""
from __future__ import annotations

import argparse
from collections import Counter, defaultdict
import csv
from datetime import datetime, timezone
import hashlib
from importlib import metadata as package_metadata
import json
import math
from pathlib import Path
import random
import time
import unicodedata

LABELS = ("minor", "serious", "critical", "needs_review")
LANGUAGES = ("en", "tl", "ceb", "mixed")
COLUMNS = ("case_id", "group_id", "language", "text", "label", "review_status", "reviewer", "notes")
BASE_MODEL = "google-bert/bert-base-multilingual-cased"
DEFAULT_DATA = Path(__file__).resolve().parents[2] / "datasets/emergency_text/working/reports.csv"


def normalized_text(text: str) -> str:
    return " ".join(unicodedata.normalize("NFKC", text).casefold().split())


def digest(value: str) -> str:
    return hashlib.sha256(value.encode("utf-8")).hexdigest()


def dataset_fingerprint(rows: list[dict]) -> str:
    canonical = [{key: row[key] for key in COLUMNS} for row in sorted(rows, key=lambda r: r["case_id"])]
    return digest(json.dumps(canonical, ensure_ascii=False, sort_keys=True, separators=(",", ":")))


def load_dataset(path: str | Path) -> list[dict]:
    """Reject invalid approval, ambiguous groups, and exact-text group leakage."""
    with Path(path).open(encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        if reader.fieldnames is None or set(reader.fieldnames) != set(COLUMNS) or len(reader.fieldnames) != len(COLUMNS):
            raise ValueError(f"CSV must contain exactly these columns: {', '.join(COLUMNS)}")
        rows = []
        ids: set[str] = set()
        texts: dict[str, str] = {}
        groups: dict[str, str] = {}
        for line, raw in enumerate(reader, 2):
            if None in raw or any(value is None for value in raw.values()):
                raise ValueError(f"Row {line}: malformed CSV column count")
            row = {key: value.strip() for key, value in raw.items()}
            for key in ("case_id", "group_id", "text"):
                if not row[key]:
                    raise ValueError(f"Row {line}: {key} is required")
            if row["case_id"] in ids:
                raise ValueError(f"Row {line}: duplicate case_id")
            ids.add(row["case_id"])
            if row["language"] not in LANGUAGES or row["label"] not in LABELS:
                raise ValueError(f"Row {line}: unsupported language or label")
            if row["review_status"] not in ("draft", "approved", "rejected"):
                raise ValueError(f"Row {line}: review_status must be draft, approved, or rejected")
            if row["review_status"] == "approved" and not row["reviewer"]:
                raise ValueError(f"Row {line}: approved rows require an accountable reviewer")
            # Rejected rows remain auditable but cannot affect model data or split checks.
            if row["review_status"] != "rejected":
                text_key = normalized_text(row["text"])
                if text_key in texts and texts[text_key] != row["group_id"]:
                    raise ValueError(f"Row {line}: identical normalized text appears in different groups; merge the groups")
                texts[text_key] = row["group_id"]
                if row["group_id"] in groups and groups[row["group_id"]] != row["label"]:
                    raise ValueError(f"Row {line}: translations/paraphrases in one group must share the reviewed label")
                groups[row["group_id"]] = row["label"]
            rows.append(row)
    if not rows:
        raise ValueError("Dataset contains no examples")
    return rows


def select_rows(rows: list[dict], allow_draft: bool = False) -> list[dict]:
    selected = [row for row in rows if row["review_status"] == "approved" or (allow_draft and row["review_status"] == "draft")]
    if not selected:
        raise ValueError("No approved examples available. Review and label data first; --allow-draft is for explicitly unreviewed diagnostics only.")
    return selected


def dataset_summary(rows: list[dict]) -> dict:
    return {
        "rows": len(rows), "groups": len({row["group_id"] for row in rows}),
        "by_label": dict(Counter(row["label"] for row in rows)),
        "by_language": dict(Counter(row["language"] for row in rows)),
        "by_review_status": dict(Counter(row["review_status"] for row in rows)),
        "eligible_approved_rows": sum(row["review_status"] == "approved" for row in rows),
        "dataset_fingerprint": dataset_fingerprint(rows),
    }


def split_groups(rows: list[dict], seed: int = 42, validation_fraction: float = 0.15, test_fraction: float = 0.15) -> dict[str, list[dict]]:
    """Stratify at group level; related translations must be grouped by annotators.

    Uses per-label group quotas. Language balance is reported, not guaranteed.
    """
    if not (0 < validation_fraction < 0.5 and 0 < test_fraction < 0.5 and validation_fraction + test_fraction < 1):
        raise ValueError("Validation and test fractions must be positive, below 0.5, and sum to below 1")
    grouped: dict[str, list[dict]] = defaultdict(list)
    for row in rows:
        grouped[row["group_id"]].append(row)
    rng = random.Random(seed)
    result: dict[str, list[dict]] = {"train": [], "validation": [], "test": []}
    for label in LABELS:
        group_ids = sorted(group for group, items in grouped.items() if items[0]["label"] == label)
        if len(group_ids) < 3:
            raise ValueError(f"Label '{label}' needs at least 3 independent groups for train/validation/test; found {len(group_ids)}")
        rng.shuffle(group_ids)
        n_val = max(1, int(len(group_ids) * validation_fraction))
        n_test = max(1, int(len(group_ids) * test_fraction))
        assignments = {"validation": group_ids[:n_val], "test": group_ids[n_val:n_val + n_test], "train": group_ids[n_val + n_test:]}
        for split, assigned in assignments.items():
            result[split].extend(row for group in assigned for row in grouped[group])
    for items in result.values():
        items.sort(key=lambda row: row["case_id"])
    return result


def _classification_metrics(actual: list[str], predicted: list[str]) -> dict:
    confusion = [[0 for _ in LABELS] for _ in LABELS]
    for truth, guess in zip(actual, predicted):
        confusion[LABELS.index(truth)][LABELS.index(guess)] += 1
    by_class = {}
    for index, label in enumerate(LABELS):
        true_positive = confusion[index][index]
        support = sum(confusion[index])
        predicted_count = sum(row[index] for row in confusion)
        precision = true_positive / predicted_count if predicted_count else 0.0
        recall = true_positive / support if support else 0.0
        by_class[label] = {"precision": precision, "recall": recall, "f1": 2 * precision * recall / (precision + recall) if precision + recall else 0.0, "support": support}
    return {"count": len(actual), "accuracy": sum(a == b for a, b in zip(actual, predicted)) / len(actual) if actual else None,
            "macro_f1": sum(item["f1"] for item in by_class.values()) / len(LABELS) if actual else None, "per_class": by_class,
            "confusion_matrix": {"labels": list(LABELS), "rows_actual_columns_predicted": confusion}}


def prediction_metrics(rows: list[dict], probabilities: list[list[float] | None], review_threshold: float = 0.65) -> dict:
    if not 0 < review_threshold <= 1 or not math.isfinite(review_threshold):
        raise ValueError("review_threshold must be finite, above 0 and at most 1")
    if len(rows) != len(probabilities) or not rows:
        raise ValueError("Predictions must contain exactly one probability vector per nonempty example")
    predicted, routed = [], []
    for probability in probabilities:
        if probability is None:
            predicted.append(None)
            routed.append("needs_review")
            continue
        if len(probability) != len(LABELS) or any(not math.isfinite(float(p)) or not 0 <= p <= 1 for p in probability) or abs(sum(probability) - 1) > 1e-5:
            raise ValueError("Predictions must be finite normalized probabilities for all four labels")
        index = max(range(len(LABELS)), key=lambda i: probability[i])
        predicted.append(LABELS[index])
        routed.append(LABELS[index] if probability[index] >= review_threshold else "needs_review")
    actual = [row["label"] for row in rows]
    rank = {"minor": 0, "serious": 1, "critical": 2}
    urgent = [i for i, value in enumerate(actual) if value in ("serious", "critical")]
    undertriage = sum(routed[i] in rank and rank[routed[i]] < rank[actual[i]] for i in urgent)
    raw_urgent = [i for i in urgent if predicted[i] is not None]
    raw_undertriage = sum(predicted[i] in rank and rank[predicted[i]] < rank[actual[i]] for i in raw_urgent)
    accepted = [i for i, value in enumerate(routed) if value != "needs_review"]
    scored = [i for i, value in enumerate(predicted) if value is not None]
    per_language = {}
    for language in LANGUAGES:
        selected = [i for i, row in enumerate(rows) if row["language"] == language]
        high_risk = [i for i in selected if i in urgent]
        language_undertriage = sum(routed[i] in rank and rank[routed[i]] < rank[actual[i]] for i in high_risk)
        language_metrics = _classification_metrics([actual[i] for i in selected], [routed[i] for i in selected])
        raw_language_indices = [i for i in selected if predicted[i] is not None]
        language_metrics["raw_classifier"] = _classification_metrics(
            [actual[i] for i in raw_language_indices], [predicted[i] for i in raw_language_indices]
        ) if raw_language_indices else {"count": 0, "macro_f1": None}
        language_metrics.update({"high_risk_support": len(high_risk), "high_risk_undertriage_count": language_undertriage,
            "high_risk_undertriage_rate": language_undertriage / len(high_risk) if high_risk else None,
            "high_risk_review_rate": sum(routed[i] == "needs_review" for i in high_risk) / len(high_risk) if high_risk else None,
            "coverage": sum(routed[i] != "needs_review" for i in selected) / len(selected) if selected else None,
            "unscored_review_count": sum(predicted[i] is None for i in selected)})
        per_language[language] = language_metrics
    return {"dataset_fingerprint": dataset_fingerprint(rows), "count": len(rows),
            "raw_classifier": _classification_metrics([actual[i] for i in scored], [predicted[i] for i in scored]), "review_routed": _classification_metrics(actual, routed),
            "per_language": per_language, "unscored_review_count": len(rows) - len(scored),
            "unscored_review_policy": "Transformer inputs beyond max_length receive no prediction and require review",
            "coverage": len(accepted) / len(rows), "abstention_rate": 1 - len(accepted) / len(rows),
            "accepted_accuracy": sum(actual[i] == routed[i] for i in accepted) / len(accepted) if accepted else None,
            "high_risk_support": len(urgent), "high_risk_undertriage_count": undertriage,
            "high_risk_undertriage_rate": undertriage / len(urgent) if urgent else None,
            "raw_high_risk_undertriage_count": raw_undertriage,
            "raw_high_risk_support": len(raw_urgent), "raw_high_risk_undertriage_rate": raw_undertriage / len(raw_urgent) if raw_urgent else None,
            "high_risk_review_rate": sum(routed[i] == "needs_review" for i in urgent) / len(urgent) if urgent else None,
            "review_threshold": review_threshold, "confidence_calibrated": False,
            "review_threshold_status": "provisional", "approved_for_deployment": False}


def _write_json(path: Path, payload: dict) -> None:
    path.write_text(json.dumps(payload, ensure_ascii=False, indent=2, allow_nan=False) + "\n", encoding="utf-8")


def _prepare_output(path: str | Path) -> Path:
    output = Path(path)
    if output.exists() and any(output.iterdir()):
        raise ValueError("Output directory must be new or empty; existing experiments are never overwritten")
    output.mkdir(parents=True, exist_ok=True)
    return output


def _manifest(splits: dict[str, list[dict]]) -> dict:
    return {name: {"case_ids": [row["case_id"] for row in rows], "group_hashes": sorted({digest(row["group_id"]) for row in rows}),
                   "text_hashes": sorted({digest(normalized_text(row["text"])) for row in rows}), "summary": dataset_summary(rows)} for name, rows in splits.items()}


def experiment_splits(rows: list[dict], args: argparse.Namespace) -> dict:
    """Reuse a frozen split only if every row, label and review flag still matches."""
    if not getattr(args, "split_manifest", None):
        return split_groups(rows, args.seed, args.validation_fraction, args.test_fraction)
    saved = json.loads(Path(args.split_manifest).read_text(encoding="utf-8"))
    if set(saved) != {"train", "validation", "test"}:
        raise ValueError("Frozen split manifest must contain train, validation and test")
    by_id = {row["case_id"]: row for row in rows}
    selected, seen_ids, seen_groups, seen_texts = {}, set(), set(), set()
    for name in ("train", "validation", "test"):
        ids = saved[name]["case_ids"]
        if not ids or len(ids) != len(set(ids)) or not set(ids) <= by_id.keys() or seen_ids.intersection(ids):
            raise ValueError("Frozen split contains missing, duplicate or overlapping case IDs")
        items = sorted((by_id[key] for key in ids), key=lambda row: row["case_id"])
        groups = {row["group_id"] for row in items}
        texts = {normalized_text(row["text"]) for row in items}
        if seen_groups.intersection(groups) or seen_texts.intersection(texts):
            raise ValueError("Frozen split leaks groups or normalized text")
        if set(row["label"] for row in items) != set(LABELS):
            raise ValueError("Every frozen split must contain all four labels")
        if _manifest({name: items})[name] != saved[name]:
            raise ValueError("Frozen split fingerprint or review flags changed; create a new version")
        selected[name] = items
        seen_ids.update(ids)
        seen_groups.update(groups)
        seen_texts.update(texts)
    if seen_ids != set(by_id):
        raise ValueError("Frozen splits do not cover the entire selected dataset")
    return selected


def selection_key(metrics: dict, metric: str) -> tuple:
    raw = metrics["raw_classifier"]
    if metric == "critical_recall_then_macro_f1":
        return (raw["per_class"]["critical"]["recall"], raw["macro_f1"])
    if metric == "macro_f1":
        return (raw["macro_f1"],)
    if metric == "balanced_safety":
        critical = raw["per_class"]["critical"]
        serious = raw["per_class"]["serious"]
        return (-metrics["raw_high_risk_undertriage_count"], raw["macro_f1"],
                min(critical["recall"], serious["recall"]), critical["precision"])
    raise ValueError("Unknown checkpoint selection metric")


def weighted_window_loss(logits, targets, class_weights, window_weight_sum: float):
    """Normalize by the WHOLE accumulation window, not each microbatch.

    Backpropagating each returned loss gives the same weighted-mean objective
    as a combined batch (for identical logits); handles partial final windows.
    """
    import torch.nn.functional as functional
    if not math.isfinite(window_weight_sum) or window_weight_sum <= 0:
        raise ValueError("Accumulation window must have a positive finite weight sum")
    return functional.cross_entropy(logits.float(), targets, weight=class_weights,
                                    reduction="sum") / window_weight_sum


def optimizer_step_with_amp_accounting(scaler, optimizer, scheduler) -> dict:
    """Advance the LR schedule only when GradScaler actually updates weights.

    CUDA GradScaler lowers its scale when it skips an optimizer step due to
    non-finite gradients. Keeping the scheduler fixed in that case makes its
    step count correspond to successful parameter updates rather than attempts.
    """
    scale_before = float(scaler.get_scale())
    scaler.step(optimizer)
    scaler.update()
    scale_after = float(scaler.get_scale())
    skipped = scale_after < scale_before
    if not skipped:
        scheduler.step()
    return {
        "optimizer_step_skipped": skipped,
        "scale_before": scale_before,
        "scale_after": scale_after,
        "scheduler_advanced": not skipped,
    }


def trainable_parameter_group_fingerprints(model) -> dict:
    """Hash trainable weights by XLM-R encoder layer for update evidence."""
    import torch

    groups: dict[str, dict] = {}
    for name, parameter in model.named_parameters():
        if not parameter.requires_grad:
            continue
        parts = name.split(".")
        group = parts[0]
        for index, part in enumerate(parts[:-1]):
            if part == "layer" and parts[index + 1].isdigit():
                group = ".".join(parts[:index + 2])
                break
            if part == "embeddings":
                group = ".".join(parts[:index + 1])
                break
        item = groups.setdefault(group, {"sha256": hashlib.sha256(), "parameters": 0, "numel": 0})
        item["sha256"].update(name.encode("utf-8"))
        item["sha256"].update(str(tuple(parameter.shape)).encode("ascii"))
        cpu_parameter = parameter.detach().contiguous().cpu()
        item["sha256"].update(memoryview(cpu_parameter.numpy()).cast("B"))
        item["parameters"] += 1
        item["numel"] += parameter.numel()
    return {
        name: {"sha256": item["sha256"].hexdigest(), "parameters": item["parameters"], "numel": item["numel"]}
        for name, item in sorted(groups.items())
    }


def _metadata(rows: list[dict], args: argparse.Namespace, model_type: str) -> dict:
    fit_all = bool(getattr(args, "fit_all", False))
    versions = {}
    for name in ("torch", "transformers", "scikit-learn"):
        try:
            versions[name] = package_metadata.version(name)
        except package_metadata.PackageNotFoundError:
            pass
    return {"schema_version": 1, "version": datetime.now(timezone.utc).strftime("text-%Y%m%dT%H%M%S%fZ"),
            "model_type": model_type, "base_model": args.base_model if model_type == "transformers" else "tfidf-char-wb-logistic-regression",
            "labels": list(LABELS), "languages": list(LANGUAGES), "scope": "road_vehicle_accidents", "domain": "road_vehicle_accidents",
            "observed_languages": sorted({row["language"] for row in rows}), "language_support_status": "requires_separate_language_evaluation",
            "max_length": getattr(args, "max_length", 128), "review_threshold": args.review_threshold,
            "review_threshold_status": "provisional", "confidence_calibrated": False,
            "approved_for_deployment": False, "deployment_status": "experimental",
            "all_data_reviewed": all(row["review_status"] == "approved" for row in rows), "allow_draft": args.allow_draft,
            "dataset_fingerprint": dataset_fingerprint(rows), "dataset_summary": dataset_summary(rows),
            "seed": args.seed, "validation_fraction": 0.0 if fit_all else args.validation_fraction,
            "test_fraction": 0.0 if fit_all else args.test_fraction,
            "test_evaluated_during_training": getattr(args, "evaluate_test", False),
            "fit_all_data": fit_all,
            "split_source": "full_dataset_fit" if fit_all else "frozen_manifest" if getattr(args, "split_manifest", None) else "group_split",
            "software_versions": versions,
            "limitations": ["No deployment approval is granted by training or metrics", "Confidence and review threshold are not calibrated", "Group IDs require human identification of translations and paraphrases", "Language balance is reported, not guaranteed", "Small or synthetic datasets do not establish clinical or operational safety"]}


def _save_reports(output: Path, splits: dict, metadata: dict, predictor) -> dict:
    _write_json(output / "split_manifest.json", _manifest(splits))
    for split in (("validation", "test") if metadata.get("test_evaluated_during_training", False) else ("validation",)):
        probabilities = predictor([row["text"] for row in splits[split]])
        metrics = prediction_metrics(splits[split], probabilities, metadata["review_threshold"])
        metrics.update({"split": split, "all_data_reviewed": metadata["all_data_reviewed"], "model_version": metadata["version"]})
        _write_json(output / f"{split}_metrics.json", metrics)
        _write_json(output / f"{split}_predictions.json", {
            "split": split, "model_version": metadata["version"], "labels": list(LABELS),
            "dataset_fingerprint": dataset_fingerprint(splits[split]),
            "all_data_reviewed": metadata["all_data_reviewed"],
            "rows": [{"case_id": row["case_id"], "group_id": row["group_id"],
                      "language": row["language"], "label": row["label"], "probabilities": probability}
                     for row, probability in zip(splits[split], probabilities, strict=True)]})
    _write_json(output / "metadata.json", metadata)
    return {"output": str(output.resolve()), "version": metadata["version"], "all_data_reviewed": metadata["all_data_reviewed"], "approved_for_deployment": False}


def _baseline_predictor(payload: dict):
    """Restore only plain numbers and strings, never pickle/code from artifacts."""
    import numpy as np
    from sklearn.feature_extraction.text import TfidfVectorizer

    vectorizer = TfidfVectorizer(analyzer="char_wb", ngram_range=(3, 5), sublinear_tf=True, vocabulary=payload["vocabulary"])
    vectorizer.fit(["_"])
    vectorizer.idf_ = np.asarray(payload["idf"], dtype=float)
    coefficients = np.asarray(payload["coefficients"], dtype=float)
    intercept = np.asarray(payload["intercept"], dtype=float)

    def predict(texts: list[str]) -> list[list[float]]:
        scores = vectorizer.transform(texts) @ coefficients.T + intercept
        scores = np.exp(scores - scores.max(axis=1, keepdims=True))
        return (scores / scores.sum(axis=1, keepdims=True)).tolist()
    return predict


def run_baseline(args: argparse.Namespace) -> dict:
    rows = select_rows(load_dataset(args.data), args.allow_draft)
    splits = experiment_splits(rows, args)
    from sklearn.feature_extraction.text import TfidfVectorizer
    from sklearn.linear_model import LogisticRegression

    output = _prepare_output(args.output)
    vectorizer = TfidfVectorizer(analyzer="char_wb", ngram_range=(3, 5), sublinear_tf=True, max_features=20000)
    features = vectorizer.fit_transform([row["text"] for row in splits["train"]])
    classifier = LogisticRegression(max_iter=1000, class_weight="balanced", random_state=args.seed)
    classifier.fit(features, [LABELS.index(row["label"]) for row in splits["train"]])
    payload = {"schema_version": 1, "labels": list(LABELS), "vocabulary": {key: int(value) for key, value in vectorizer.vocabulary_.items()},
               "idf": vectorizer.idf_.tolist(), "coefficients": classifier.coef_.tolist(), "intercept": classifier.intercept_.tolist()}
    _write_json(output / "baseline.json", payload)
    return _save_reports(output, splits, _metadata(rows, args, "tfidf_logistic"), _baseline_predictor(payload))


def _transformer_predictor(model, tokenizer, device, max_length: int, batch_size: int):
    import torch

    def predict(texts: list[str]) -> list[list[float] | None]:
        model.eval()
        result = [None] * len(texts)
        eligible = [i for i, text in enumerate(texts) if len(tokenizer.encode(text, truncation=False)) <= max_length]
        with torch.inference_mode():
            for start in range(0, len(eligible), batch_size):
                indices = eligible[start:start + batch_size]
                encoded = tokenizer([texts[i] for i in indices], padding=True, truncation=False, return_tensors="pt")
                logits = model(**{key: value.to(device) for key, value in encoded.items()}).logits
                for index, probability in zip(indices, torch.softmax(logits.float(), dim=-1).cpu().tolist()):
                    result[index] = probability
        return result
    return predict


def run_train(args: argparse.Namespace) -> dict:
    started = time.perf_counter()
    rows = select_rows(load_dataset(args.data), args.allow_draft)
    fit_all = bool(getattr(args, "fit_all", False))
    if fit_all:
        if getattr(args, "split_manifest", None) or getattr(args, "evaluate_test", False):
            raise ValueError("--fit-all cannot use a split manifest or test evaluation; it trains every selected row and reports no evaluation metrics")
        if getattr(args, "early_stopping_patience", 0) != 0:
            raise ValueError("--fit-all requires --early-stopping-patience 0 and a fixed epoch count selected in a separate experiment")
        splits = {"train": rows, "validation": [], "test": []}
    else:
        splits = experiment_splits(rows, args)
    if min(args.epochs, args.batch_size, args.gradient_accumulation, args.threads) < 1 or not 16 <= args.max_length <= 512:
        raise ValueError("Positive epochs/batch/accumulation/threads and max_length 16..512 are required")
    patience = getattr(args, "early_stopping_patience", 2)
    if patience < 0:
        raise ValueError("early-stopping-patience cannot be negative")
    metric = getattr(args, "selection_metric", "critical_recall_then_macro_f1")
    import torch
    from transformers import AutoModelForSequenceClassification, AutoTokenizer, set_seed

    output = _prepare_output(args.output)
    set_seed(args.seed)
    torch.set_num_threads(args.threads)
    torch.use_deterministic_algorithms(True, warn_only=True)
    device = torch.device("cuda" if args.device == "auto" and torch.cuda.is_available() else "cpu" if args.device == "auto" else args.device)
    print(json.dumps({"event": "loading_model", "device": str(device), "epochs": args.epochs, "full_backbone": args.unfreeze_backbone}), flush=True)
    tokenizer = AutoTokenizer.from_pretrained(args.base_model, revision=args.revision, trust_remote_code=False)
    model = AutoModelForSequenceClassification.from_pretrained(args.base_model, revision=args.revision, num_labels=len(LABELS), id2label=dict(enumerate(LABELS)), label2id={value: index for index, value in enumerate(LABELS)}, trust_remote_code=False)
    if args.max_length > min(getattr(model.config, "max_position_embeddings", 512), tokenizer.model_max_length):
        raise ValueError("max_length exceeds the base model or tokenizer context limit")
    if not args.unfreeze_backbone:
        for parameter in model.base_model.parameters():
            parameter.requires_grad = False
    if getattr(args, "gradient_checkpointing", False):
        model.gradient_checkpointing_enable(gradient_checkpointing_kwargs={"use_reentrant": False})
        if hasattr(model.config, "use_cache"):
            model.config.use_cache = False
    model.to(device)
    initial_parameter_group_fingerprints = trainable_parameter_group_fingerprints(model)
    learning_rate = args.learning_rate if args.learning_rate is not None else (2e-5 if args.unfreeze_backbone else 1e-3)
    if not math.isfinite(learning_rate) or learning_rate <= 0:
        raise ValueError("learning_rate must be finite and positive")
    trainable = (parameter for parameter in model.parameters() if parameter.requires_grad)
    optimizer_name = getattr(args, "optimizer", "adamw")
    if optimizer_name == "adamw":
        optimizer = torch.optim.AdamW(trainable, lr=learning_rate, weight_decay=0.01, foreach=False)
    elif optimizer_name == "adafactor":
        from transformers.optimization import Adafactor
        optimizer = Adafactor(trainable, lr=learning_rate, scale_parameter=False,
                              relative_step=False, warmup_init=False, weight_decay=0.0)
    else:
        raise ValueError("optimizer must be adamw or adafactor")
    warmup_ratio = getattr(args, "warmup_ratio", 0.05)
    if not math.isfinite(warmup_ratio) or not 0 <= warmup_ratio < 1:
        raise ValueError("warmup_ratio must be finite and in [0, 1)")
    optimizer_steps_per_epoch = math.ceil(math.ceil(len(splits["train"]) / args.batch_size) / args.gradient_accumulation)
    total_optimizer_steps = optimizer_steps_per_epoch * args.epochs
    warmup_steps = math.ceil(total_optimizer_steps * warmup_ratio)
    def learning_rate_scale(step: int) -> float:
        if warmup_steps and step < warmup_steps:
            return min(1.0, (step + 1) / warmup_steps)
        return max(0.0, (total_optimizer_steps - step) / max(1, total_optimizer_steps - warmup_steps))
    scheduler = torch.optim.lr_scheduler.LambdaLR(optimizer, learning_rate_scale)
    counts = Counter(row["label"] for row in splits["train"])
    class_weights = torch.tensor([len(splits["train"]) / (len(LABELS) * counts[label]) for label in LABELS], device=device)
    weight_values = class_weights.detach().cpu().tolist()
    scaler = torch.amp.GradScaler("cuda", enabled=device.type == "cuda")
    metadata = _metadata(rows, args, "transformers")
    metadata.update({"backbone_frozen": not args.unfreeze_backbone, "learning_rate": learning_rate, "epochs": args.epochs, "batch_size": args.batch_size, "gradient_accumulation": args.gradient_accumulation, "device": str(device), "base_model_revision": getattr(model.config, "_commit_hash", None), "requested_revision": args.revision, "optimizer": optimizer_name, "weight_decay": 0.01 if optimizer_name == "adamw" else 0.0, "gradient_checkpointing": bool(getattr(args, "gradient_checkpointing", False)), "precision": "cuda_fp16_autocast" if device.type == "cuda" else "fp32", "scheduler": "linear_warmup_decay", "warmup_ratio": warmup_ratio, "warmup_steps": warmup_steps, "planned_optimizer_steps": total_optimizer_steps})
    metadata["total_parameters"] = sum(parameter.numel() for parameter in model.parameters())
    metadata["trainable_parameters"] = sum(parameter.numel() for parameter in model.parameters() if parameter.requires_grad)
    metadata.update({"loss_normalization": "weighted_sum_over_accumulation_window_weight_sum",
                     "class_weights": dict(zip(LABELS, weight_values)), "selection_metric": metric,
                     "early_stopping_patience": patience})
    # Long descriptions need separate review: their tail is not seen by the model.
    metadata["truncated_rows_by_split"] = {name: sum(len(tokenizer.encode(row["text"], truncation=False)) > args.max_length for row in items) for name, items in splits.items()}
    if metadata["all_data_reviewed"] and metadata["truncated_rows_by_split"]["train"]:
        raise ValueError("Reviewed training data exceeds max_length. Increase --max-length within model limits or have complete shorter examples reviewed; do not silently discard severity evidence.")
    if not fit_all and metadata["truncated_rows_by_split"]["validation"] == len(splits["validation"]):
        raise ValueError("No validation example fits max_length; cannot select a checkpoint from unscored data")
    history, best_score, stale_epochs = [], None, 0
    predictor = _transformer_predictor(model, tokenizer, device, args.max_length, args.batch_size)
    if device.type == "cuda":
        torch.cuda.reset_peak_memory_stats(device)
    print(json.dumps({"event": "training_started", "train_rows": len(splits["train"]), "validation_rows": len(splits["validation"]), "test_rows": len(splits["test"]), "fit_all_data": fit_all, "trainable_parameters": metadata["trainable_parameters"], "optimizer": optimizer_name, "gradient_checkpointing": metadata["gradient_checkpointing"], "truncated_rows_by_split": metadata["truncated_rows_by_split"]}), flush=True)
    rng = random.Random(args.seed)
    attempted_optimizer_steps = 0
    successful_optimizer_steps = 0
    skipped_optimizer_steps = 0
    first_successful_gradient_evidence = None
    trainable_backbone_parameters = [parameter for parameter in model.base_model.parameters() if parameter.requires_grad]
    for epoch in range(args.epochs):
        ordered = list(splits["train"])
        rng.shuffle(ordered)
        batches = [ordered[start:start + args.batch_size] for start in range(0, len(ordered), args.batch_size)]
        model.train()
        if not args.unfreeze_backbone:
            model.base_model.eval()
        optimizer.zero_grad(set_to_none=True)
        weighted_objective_numerator = 0.0
        epoch_attempted_start = attempted_optimizer_steps
        epoch_successful_start = successful_optimizer_steps
        epoch_skipped_start = skipped_optimizer_steps
        for index, batch in enumerate(batches):
            encoded = tokenizer([row["text"] for row in batch], padding=True, truncation=True, max_length=args.max_length, return_tensors="pt")
            targets = torch.tensor([LABELS.index(row["label"]) for row in batch], device=device)
            window_start = index // args.gradient_accumulation * args.gradient_accumulation
            window_size = min(args.gradient_accumulation, len(batches) - window_start)
            if index == window_start:
                window_weight_sum = sum(weight_values[LABELS.index(row["label"])]
                                        for items in batches[window_start:window_start + window_size] for row in items)
            with torch.autocast(device_type=device.type, enabled=device.type == "cuda"):
                logits = model(**{key: value.to(device) for key, value in encoded.items()}).logits
                loss = weighted_window_loss(logits, targets, class_weights, window_weight_sum)
            if not torch.isfinite(loss):
                raise ValueError("Training produced non-finite loss; no release is approved")
            weighted_objective_numerator += loss.item() * window_weight_sum
            scaler.scale(loss).backward()
            if (index + 1) % args.gradient_accumulation == 0 or index + 1 == len(batches):
                scaler.unscale_(optimizer)
                gradient_norm = torch.nn.utils.clip_grad_norm_(model.parameters(), 1.0)
                if not torch.isfinite(gradient_norm):
                    # Let GradScaler's recorded inf/nan check skip this update.
                    pass
                gradient_norm_value = float(gradient_norm.detach().float().cpu())
                grad_parameter_count = sum(parameter.grad is not None for parameter in model.parameters())
                backbone_grad_parameter_count = sum(parameter.grad is not None for parameter in trainable_backbone_parameters)
                step_evidence = optimizer_step_with_amp_accounting(scaler, optimizer, scheduler)
                attempted_optimizer_steps += 1
                if step_evidence["optimizer_step_skipped"]:
                    skipped_optimizer_steps += 1
                else:
                    successful_optimizer_steps += 1
                    if first_successful_gradient_evidence is None:
                        first_successful_gradient_evidence = {
                            "total_grad_parameter_count": grad_parameter_count,
                            "backbone_grad_parameter_count": backbone_grad_parameter_count,
                            "global_gradient_norm_before_clip": gradient_norm_value,
                            "gradient_norm_finite_and_nonzero": math.isfinite(gradient_norm_value) and gradient_norm_value > 0,
                        }
                optimizer.zero_grad(set_to_none=True)
            if (index + 1) % 50 == 0 or index + 1 == len(batches):
                print(json.dumps({"event": "training_progress", "epoch": epoch + 1, "batch": index + 1, "batches": len(batches), "weighted_objective_numerator": weighted_objective_numerator, "attempted_optimizer_steps": attempted_optimizer_steps, "successful_optimizer_steps": successful_optimizer_steps, "skipped_optimizer_steps": skipped_optimizer_steps, "learning_rate": optimizer.param_groups[0]["lr"], "elapsed_seconds": round(time.perf_counter() - started, 2)}), flush=True)
        validation = None if fit_all else prediction_metrics(splits["validation"], predictor([row["text"] for row in splits["validation"]]), args.review_threshold)
        score = (epoch + 1,) if fit_all else selection_key(validation, metric)
        training_weight_total = sum(weight_values[LABELS.index(row["label"])] for row in ordered)
        history.append({"epoch": epoch + 1, "weighted_training_objective": weighted_objective_numerator / training_weight_total,
                        "optimizer_steps_attempted": attempted_optimizer_steps - epoch_attempted_start,
                        "optimizer_steps_succeeded": successful_optimizer_steps - epoch_successful_start,
                        "optimizer_steps_skipped_amp": skipped_optimizer_steps - epoch_skipped_start,
                        "validation_macro_f1": None if fit_all else validation["raw_classifier"]["macro_f1"],
                        "validation_critical_recall": None if fit_all else validation["raw_classifier"]["per_class"]["critical"]["recall"],
                        "validation_critical_precision": None if fit_all else validation["raw_classifier"]["per_class"]["critical"]["precision"],
                        "validation_serious_recall": None if fit_all else validation["raw_classifier"]["per_class"]["serious"]["recall"],
                        "validation_raw_high_risk_undertriage_count": None if fit_all else validation["raw_high_risk_undertriage_count"],
                        "learning_rate_after_epoch": optimizer.param_groups[0]["lr"]})
        if fit_all or best_score is None or score > best_score:
            best_score = score
            stale_epochs = 0
            metadata["best_epoch"] = epoch + 1
            model.save_pretrained(output, safe_serialization=True)
            tokenizer.save_pretrained(output)
        else:
            stale_epochs += 1
        print(json.dumps(history[-1]), flush=True)
        if patience and stale_epochs >= patience:
            print(json.dumps({"event": "early_stopping", "epoch": epoch + 1, "patience": patience}), flush=True)
            break
    # Release optimizer and old model before loading the validation-selected checkpoint.
    del predictor, optimizer, model, logits, loss
    if successful_optimizer_steps == 0:
        raise RuntimeError("No optimizer update succeeded; refusing to save a trainable checkpoint")
    if device.type == "cuda":
        torch.cuda.empty_cache()
    selected_model = AutoModelForSequenceClassification.from_pretrained(output, local_files_only=True, trust_remote_code=False).to(device)
    selected_parameter_group_fingerprints = trainable_parameter_group_fingerprints(selected_model)
    parameter_group_updates = {
        name: {
            "initial_sha256": initial_parameter_group_fingerprints[name]["sha256"],
            "selected_sha256": selected_parameter_group_fingerprints[name]["sha256"],
            "changed": initial_parameter_group_fingerprints[name]["sha256"] != selected_parameter_group_fingerprints[name]["sha256"],
            "parameters": selected_parameter_group_fingerprints[name]["parameters"],
            "numel": selected_parameter_group_fingerprints[name]["numel"],
        }
        for name in initial_parameter_group_fingerprints
    }
    metadata["optimizer_step_accounting"] = {
        "attempted": attempted_optimizer_steps,
        "successful": successful_optimizer_steps,
        "skipped_amp": skipped_optimizer_steps,
        "scheduler_steps": successful_optimizer_steps,
        "first_successful_gradient_evidence": first_successful_gradient_evidence,
        "scheduler_policy": "advance only when GradScaler scale does not decrease after optimizer step",
    }
    metadata["parameter_group_updates"] = parameter_group_updates
    metadata["parameter_update_verification"] = {
        "changed_groups": sum(item["changed"] for item in parameter_group_updates.values()),
        "total_trainable_groups": len(parameter_group_updates),
        "all_encoder_groups_changed": all(item["changed"] for name, item in parameter_group_updates.items() if "encoder.layer." in name),
        "all_parameters_trainable": metadata["trainable_parameters"] == metadata["total_parameters"],
    }
    metadata["epochs_completed"] = len(history)
    _write_json(output / "training_history.json", {"epochs": history, "selection_metric": metric})
    metadata["training_and_selection_seconds"] = round(time.perf_counter() - started, 2)
    if device.type == "cuda":
        metadata["peak_cuda_memory_allocated_bytes"] = torch.cuda.max_memory_allocated(device)
        metadata["peak_cuda_memory_reserved_bytes"] = torch.cuda.max_memory_reserved(device)
    if fit_all:
        training_manifest = {
            "split": "train_all_selected_rows",
            "dataset_fingerprint": dataset_fingerprint(rows),
            "summary": dataset_summary(rows),
            "case_ids": [row["case_id"] for row in rows],
            "group_hashes": sorted({digest(row["group_id"]) for row in rows}),
            "text_hashes": sorted({digest(normalized_text(row["text"])) for row in rows}),
            "evaluation_performed": False,
            "deployment_approved": False,
        }
        _write_json(output / "training_manifest.json", training_manifest)
        _write_json(output / "metadata.json", metadata)
        return {"output": str(output.resolve()), "version": metadata["version"],
                "fit_all_data": True, "training_rows": len(rows),
                "evaluation_performed": False, "approved_for_deployment": False}
    return _save_reports(output, splits, metadata, _transformer_predictor(selected_model, tokenizer, device, args.max_length, args.batch_size))


def evaluation_rows(rows: list[dict], metadata: dict, manifest: dict, split: str) -> list[dict]:
    if split == "test":
        if dataset_fingerprint(rows) != metadata["dataset_fingerprint"]:
            raise ValueError("Saved test evaluation requires the unchanged source dataset and matching review flags; use --split external for new held-out examples")
        test_ids = set(manifest["test"]["case_ids"])
        selected = [row for row in rows if row["case_id"] in test_ids]
        if len(selected) != len(test_ids):
            raise ValueError("Saved held-out test IDs do not match dataset")
        return sorted(selected, key=lambda row: row["case_id"])
    forbidden_groups = set(manifest["train"]["group_hashes"]) | set(manifest["validation"]["group_hashes"])
    forbidden_texts = set(manifest["train"]["text_hashes"]) | set(manifest["validation"]["text_hashes"])
    if any(digest(row["group_id"]) in forbidden_groups or digest(normalized_text(row["text"])) in forbidden_texts for row in rows):
        raise ValueError("External held-out dataset overlaps training/validation groups or normalized text")
    return rows


def run_evaluate(args: argparse.Namespace) -> dict:
    model_path = Path(args.model)
    metadata = json.loads((model_path / "metadata.json").read_text(encoding="utf-8"))
    manifest = json.loads((model_path / "split_manifest.json").read_text(encoding="utf-8"))
    if metadata.get("schema_version") != 1 or metadata.get("labels") != list(LABELS):
        raise ValueError("Model metadata schema or label order is incompatible")
    rows = evaluation_rows(select_rows(load_dataset(args.data), args.allow_draft), metadata, manifest, args.split)
    if metadata["model_type"] == "tfidf_logistic":
        predictor = _baseline_predictor(json.loads((model_path / "baseline.json").read_text(encoding="utf-8")))
    elif metadata["model_type"] == "transformers":
        import torch
        from transformers import AutoModelForSequenceClassification, AutoTokenizer
        device = torch.device("cuda" if args.device == "auto" and torch.cuda.is_available() else "cpu" if args.device == "auto" else args.device)
        tokenizer = AutoTokenizer.from_pretrained(model_path, local_files_only=True, trust_remote_code=False)
        model = AutoModelForSequenceClassification.from_pretrained(model_path, local_files_only=True, trust_remote_code=False).to(device)
        predictor = _transformer_predictor(model, tokenizer, device, metadata["max_length"], args.batch_size)
    else:
        raise ValueError("Unsupported model_type")
    probabilities = predictor([row["text"] for row in rows])
    metrics = prediction_metrics(rows, probabilities, metadata["review_threshold"])
    metrics.update({"split": args.split, "model_version": metadata["version"], "all_data_reviewed": all(row["review_status"] == "approved" for row in rows)})
    output = _prepare_output(args.output)
    _write_json(output / "evaluation_metrics.json", metrics)
    _write_json(output / "evaluation_predictions.json", {
        "split": args.split, "model_version": metadata["version"], "labels": list(LABELS),
        "dataset_fingerprint": dataset_fingerprint(rows),
        "all_data_reviewed": all(row["review_status"] == "approved" for row in rows),
        "rows": [{"case_id": row["case_id"], "group_id": row["group_id"],
                  "language": row["language"], "label": row["label"],
                  "probabilities": probability}
                 for row, probability in zip(rows, probabilities, strict=True)]})
    return {"output": str(output.resolve()), "count": len(rows), "approved_for_deployment": False}


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Review-gated multilingual road-accident text experiments. No command activates a live model.")
    subparsers = parser.add_subparsers(dest="command", required=True)
    validate = subparsers.add_parser("validate", help="Check CSV schema, review status and grouping; no ML dependencies/download")
    validate.add_argument("--data", default=str(DEFAULT_DATA))
    for command in ("baseline", "train"):
        sub = subparsers.add_parser(command, help="Run an offline experiment; training requires reviewed examples by default")
        sub.add_argument("--data", default=str(DEFAULT_DATA))
        sub.add_argument("--output", required=True, help="New or empty experiment directory")
        sub.add_argument("--allow-draft", action="store_true", help="EXPERIMENTAL: include unreviewed draft examples; never deployment approval")
        sub.add_argument("--seed", type=int, default=42)
        sub.add_argument("--validation-fraction", type=float, default=0.15)
        sub.add_argument("--test-fraction", type=float, default=0.15)
        sub.add_argument("--split-manifest", help="Frozen split manifest; rejects changed rows or split leakage")
        sub.add_argument("--evaluate-test", action="store_true", help="Explicit final/diagnostic evaluation; omit during tuning")
        sub.add_argument("--review-threshold", type=float, default=0.65, help="Provisional score cutoff; not calibrated confidence")
        if command == "train":
            sub.add_argument("--base-model", default=BASE_MODEL)
            sub.add_argument("--revision", default=None, help="Optional Hugging Face commit SHA for an exactly pinned base model")
            sub.add_argument("--epochs", type=int, default=3)
            sub.add_argument("--early-stopping-patience", type=int, default=2, help="Stop after this many non-improving epochs; 0 disables")
            sub.add_argument("--selection-metric", choices=("critical_recall_then_macro_f1", "macro_f1", "balanced_safety"), default="critical_recall_then_macro_f1")
            sub.add_argument("--batch-size", type=int, default=2)
            sub.add_argument("--gradient-accumulation", type=int, default=8)
            sub.add_argument("--max-length", type=int, default=128)
            sub.add_argument("--unfreeze-backbone", action="store_true", help="Full fine-tuning; default trains only the classification head to reduce memory")
            sub.add_argument("--gradient-checkpointing", action="store_true", help="Trade compute for lower activation memory during full-backbone training")
            sub.add_argument("--fit-all", action="store_true", help="FINAL FIT ONLY: train every selected row for a fixed epoch count; no validation/test metrics are produced")
            sub.add_argument("--optimizer", choices=("adamw", "adafactor"), default="adamw", help="Adafactor is available for memory-constrained full-backbone experiments")
            sub.add_argument("--warmup-ratio", type=float, default=0.05, help="Linear scheduler warmup fraction; 0 disables warmup")
            sub.add_argument("--learning-rate", type=float)
            sub.add_argument("--threads", type=int, default=2)
            sub.add_argument("--device", choices=("auto", "cpu", "cuda"), default="auto")
    evaluate = subparsers.add_parser("evaluate", help="Evaluate saved test IDs or a separate, nonoverlapping external test CSV")
    evaluate.add_argument("--data", required=True)
    evaluate.add_argument("--model", required=True)
    evaluate.add_argument("--output", required=True)
    evaluate.add_argument("--split", choices=("test", "external"), default="test")
    evaluate.add_argument("--allow-draft", action="store_true")
    evaluate.add_argument("--batch-size", type=int, default=2)
    evaluate.add_argument("--device", choices=("auto", "cpu", "cuda"), default="auto")
    return parser


def main(argv: list[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)
    try:
        if hasattr(args, "review_threshold") and (not math.isfinite(args.review_threshold) or not 0 < args.review_threshold <= 1):
            raise ValueError("review-threshold must be above 0 and at most 1")
        if hasattr(args, "batch_size") and args.batch_size < 1:
            raise ValueError("batch-size must be positive")
        if args.command == "validate":
            result = dataset_summary(load_dataset(args.data))
            result["status"] = "schema_valid_not_review_approval"
        else:
            result = {"baseline": run_baseline, "train": run_train, "evaluate": run_evaluate}[args.command](args)
        print(json.dumps(result, ensure_ascii=False, indent=2, allow_nan=False))
        return 0
    except (ValueError, OSError, ImportError, KeyError) as error:
        parser.exit(2, f"error: {error}\n")


if __name__ == "__main__":
    raise SystemExit(main())
