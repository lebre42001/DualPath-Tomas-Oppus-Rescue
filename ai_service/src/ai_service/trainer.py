"""Training loop for the accident severity baseline model."""

from __future__ import annotations

import json
import random
from collections import Counter
from dataclasses import asdict
from pathlib import Path

import torch
from torch import nn
from torch.optim import AdamW
from torch.utils.data import DataLoader

from .config import AppConfig, load_config
from .dataset import SeverityManifestDataset, read_manifest
from .model import build_model



def set_seed(seed: int) -> None:
    random.seed(seed)
    torch.manual_seed(seed)
    if torch.cuda.is_available():
        torch.cuda.manual_seed_all(seed)



def _extract_targets(records: list) -> list[str]:
    return [record.severity_label for record in records]



def _build_class_weights(labels: tuple[str, ...], targets: list[str]) -> torch.Tensor:
    counts = Counter(targets)
    total = sum(counts.values()) or 1
    weights = []
    for label in labels:
        label_count = counts.get(label, 1)
        weights.append(total / label_count)
    return torch.tensor(weights, dtype=torch.float32)


def _label_counts(labels: tuple[str, ...], targets: list[str]) -> dict[str, int]:
    counts = Counter(targets)
    return {label: int(counts.get(label, 0)) for label in labels}



def _build_classification_breakdown(
    labels: tuple[str, ...],
    true_indices: list[int],
    predicted_indices: list[int],
) -> dict[str, object]:
    confusion_matrix = {
        true_label: {predicted_label: 0 for predicted_label in labels}
        for true_label in labels
    }

    for true_index, predicted_index in zip(true_indices, predicted_indices):
        confusion_matrix[labels[true_index]][labels[predicted_index]] += 1

    per_class: dict[str, dict[str, float | int]] = {}
    precision_values: list[float] = []
    recall_values: list[float] = []
    f1_values: list[float] = []

    for label in labels:
        true_positive = confusion_matrix[label][label]
        support = sum(confusion_matrix[label].values())
        predicted_total = sum(confusion_matrix[true_label][label] for true_label in labels)
        precision = true_positive / predicted_total if predicted_total else 0.0
        recall = true_positive / support if support else 0.0
        f1 = (2 * precision * recall / (precision + recall)) if (precision + recall) else 0.0

        precision_values.append(precision)
        recall_values.append(recall)
        f1_values.append(f1)

        per_class[label] = {
            "support": support,
            "correct": true_positive,
            "accuracy": recall,
            "precision": precision,
            "recall": recall,
            "f1": f1,
        }

    label_count = len(labels) or 1
    return {
        "per_class": per_class,
        "confusion_matrix": confusion_matrix,
        "macro_precision": sum(precision_values) / label_count,
        "macro_recall": sum(recall_values) / label_count,
        "macro_f1": sum(f1_values) / label_count,
    }


def _evaluate(
    model: nn.Module,
    loader: DataLoader,
    criterion: nn.Module,
    device: torch.device,
    labels: tuple[str, ...] | None = None,
) -> dict[str, object]:
    model.eval()
    total_loss = 0.0
    total_correct = 0
    total_samples = 0
    true_indices: list[int] = []
    predicted_indices: list[int] = []

    with torch.no_grad():
        for images, targets, _ in loader:
            images = images.to(device)
            targets = targets.to(device)

            logits = model(images)
            loss = criterion(logits, targets)

            total_loss += loss.item() * targets.size(0)
            predictions = torch.argmax(logits, dim=1)
            total_correct += (predictions == targets).sum().item()
            total_samples += targets.size(0)
            true_indices.extend(int(value) for value in targets.cpu().tolist())
            predicted_indices.extend(int(value) for value in predictions.cpu().tolist())

    if total_samples == 0:
        return {"loss": 0.0, "accuracy": 0.0}

    metrics: dict[str, object] = {
        "loss": total_loss / total_samples,
        "accuracy": total_correct / total_samples,
    }

    if labels is not None:
        metrics.update(
            _build_classification_breakdown(
                labels=labels,
                true_indices=true_indices,
                predicted_indices=predicted_indices,
            )
        )

    return metrics



def train_from_config(config_path: str | Path) -> dict[str, object]:
    config = load_config(config_path)
    return train(config)



def train(config: AppConfig) -> dict[str, object]:
    set_seed(config.training.seed)

    label_to_index = {label: index for index, label in enumerate(config.experiment.labels)}
    device = torch.device("cuda" if torch.cuda.is_available() else "cpu")

    train_records = read_manifest(config.dataset.train_manifest)
    val_records = read_manifest(config.dataset.val_manifest)
    test_records = read_manifest(config.dataset.test_manifest)

    if not train_records:
        raise ValueError("Training manifest is empty. Add curated training images first.")
    if not val_records:
        raise ValueError("Validation manifest is empty. Add curated validation images first.")
    if not test_records:
        raise ValueError("Test manifest is empty. Add curated test images first.")

    train_dataset = SeverityManifestDataset(
        dataset_root=config.dataset.root,
        records=train_records,
        label_to_index=label_to_index,
        image_size=config.dataset.image_size,
        training=True,
    )
    val_dataset = SeverityManifestDataset(
        dataset_root=config.dataset.root,
        records=val_records,
        label_to_index=label_to_index,
        image_size=config.dataset.image_size,
        training=False,
    )
    test_dataset = SeverityManifestDataset(
        dataset_root=config.dataset.root,
        records=test_records,
        label_to_index=label_to_index,
        image_size=config.dataset.image_size,
        training=False,
    )

    train_loader = DataLoader(
        train_dataset,
        batch_size=config.training.batch_size,
        shuffle=True,
        num_workers=config.training.num_workers,
    )
    val_loader = DataLoader(
        val_dataset,
        batch_size=config.training.batch_size,
        shuffle=False,
        num_workers=config.training.num_workers,
    )
    test_loader = DataLoader(
        test_dataset,
        batch_size=config.training.batch_size,
        shuffle=False,
        num_workers=config.training.num_workers,
    )

    model = build_model(
        num_classes=len(config.experiment.labels),
        pretrained=config.training.pretrained,
        architecture=config.training.architecture,
    ).to(device)

    class_weights = _build_class_weights(config.experiment.labels, _extract_targets(train_records)).to(device)
    criterion = nn.CrossEntropyLoss(weight=class_weights)
    optimizer = AdamW(
        model.parameters(),
        lr=config.training.learning_rate,
        weight_decay=config.training.weight_decay,
    )

    config.outputs.checkpoints_dir.mkdir(parents=True, exist_ok=True)
    config.outputs.reports_dir.mkdir(parents=True, exist_ok=True)

    best_val_accuracy = -1.0
    history: list[dict[str, float | int]] = []

    for epoch in range(1, config.training.epochs + 1):
        model.train()
        running_loss = 0.0
        running_correct = 0
        running_samples = 0

        for images, targets, _ in train_loader:
            images = images.to(device)
            targets = targets.to(device)

            optimizer.zero_grad()
            logits = model(images)
            loss = criterion(logits, targets)
            loss.backward()
            optimizer.step()

            running_loss += loss.item() * targets.size(0)
            predictions = torch.argmax(logits, dim=1)
            running_correct += (predictions == targets).sum().item()
            running_samples += targets.size(0)

        train_metrics = {
            "loss": (running_loss / running_samples) if running_samples else 0.0,
            "accuracy": (running_correct / running_samples) if running_samples else 0.0,
        }
        val_metrics = _evaluate(
            model=model,
            loader=val_loader,
            criterion=criterion,
            device=device,
            labels=config.experiment.labels,
        )

        history.append(
            {
                "epoch": epoch,
                "train_loss": train_metrics["loss"],
                "train_accuracy": train_metrics["accuracy"],
                "val_loss": val_metrics["loss"],
                "val_accuracy": val_metrics["accuracy"],
            }
        )

        val_accuracy = float(val_metrics["accuracy"])
        if val_accuracy >= best_val_accuracy:
            best_val_accuracy = val_accuracy
            torch.save(
                {
                    "model_state_dict": model.state_dict(),
                    "labels": list(config.experiment.labels),
                    "source_domains": list(config.experiment.source_domains),
                    "config": {
                        "experiment": asdict(config.experiment),
                        "training": asdict(config.training),
                        "inference": asdict(config.inference),
                    },
                },
                config.outputs.best_checkpoint_path,
            )

    best_checkpoint = torch.load(config.outputs.best_checkpoint_path, map_location=device, weights_only=False)
    model.load_state_dict(best_checkpoint["model_state_dict"])
    test_metrics = _evaluate(
        model=model,
        loader=test_loader,
        criterion=criterion,
        device=device,
        labels=config.experiment.labels,
    )

    train_label_counts = _label_counts(config.experiment.labels, _extract_targets(train_records))
    val_label_counts = _label_counts(config.experiment.labels, _extract_targets(val_records))
    test_label_counts = _label_counts(config.experiment.labels, _extract_targets(test_records))

    report = {
        "experiment_name": config.experiment.name,
        "location_focus": f"{config.experiment.municipality}, {config.experiment.province}, {config.experiment.country}",
        "labels": list(config.experiment.labels),
        "source_domains": list(config.experiment.source_domains),
        "dataset_sizes": {
            "train": len(train_records),
            "val": len(val_records),
            "test": len(test_records),
        },
        "label_distribution": {
            "train": train_label_counts,
            "val": val_label_counts,
            "test": test_label_counts,
        },
        "missing_training_labels": [label for label, count in train_label_counts.items() if count == 0],
        "best_checkpoint_path": str(config.outputs.best_checkpoint_path),
        "best_val_accuracy": best_val_accuracy,
        "test_metrics": test_metrics,
        "history": history,
        "responder_review_action": config.inference.responder_review_action,
    }
    config.outputs.metrics_report_path.write_text(json.dumps(report, indent=2), encoding="utf-8")
    return report
