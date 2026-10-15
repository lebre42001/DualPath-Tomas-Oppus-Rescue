"""YAML-backed configuration loading for the severity service."""

from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path

import yaml


@dataclass(frozen=True)
class ExperimentSettings:
    name: str
    municipality: str
    province: str
    country: str
    labels: tuple[str, ...]
    source_domains: tuple[str, ...]


@dataclass(frozen=True)
class DatasetSettings:
    root: Path
    train_manifest: Path
    val_manifest: Path
    test_manifest: Path
    image_size: int


@dataclass(frozen=True)
class TrainingSettings:
    architecture: str
    pretrained: bool
    batch_size: int
    epochs: int
    learning_rate: float
    weight_decay: float
    num_workers: int
    seed: int


@dataclass(frozen=True)
class InferenceSettings:
    low_confidence_threshold: float
    responder_review_action: str
    reject_label: str | None = None
    reject_threshold: float | None = None


@dataclass(frozen=True)
class OutputSettings:
    checkpoints_dir: Path
    reports_dir: Path
    best_checkpoint_name: str
    metrics_report_name: str

    @property
    def best_checkpoint_path(self) -> Path:
        return self.checkpoints_dir / self.best_checkpoint_name

    @property
    def metrics_report_path(self) -> Path:
        return self.reports_dir / self.metrics_report_name


@dataclass(frozen=True)
class AppConfig:
    project_root: Path
    config_path: Path
    experiment: ExperimentSettings
    dataset: DatasetSettings
    training: TrainingSettings
    inference: InferenceSettings
    outputs: OutputSettings


def _resolve_path(project_root: Path, relative_path: str) -> Path:
    return (project_root / relative_path).resolve()


def load_config(config_path: str | Path) -> AppConfig:
    config_file = Path(config_path).resolve()
    project_root = config_file.parent.parent
    raw = yaml.safe_load(config_file.read_text(encoding="utf-8"))

    experiment = ExperimentSettings(
        name=raw["experiment"]["name"],
        municipality=raw["experiment"]["municipality"],
        province=raw["experiment"]["province"],
        country=raw["experiment"]["country"],
        labels=tuple(raw["experiment"]["labels"]),
        source_domains=tuple(raw["experiment"]["source_domains"]),
    )

    dataset_root = _resolve_path(project_root, raw["dataset"]["root"])
    dataset = DatasetSettings(
        root=dataset_root,
        train_manifest=(dataset_root / raw["dataset"]["manifests"]["train"]).resolve(),
        val_manifest=(dataset_root / raw["dataset"]["manifests"]["val"]).resolve(),
        test_manifest=(dataset_root / raw["dataset"]["manifests"]["test"]).resolve(),
        image_size=int(raw["dataset"]["image_size"]),
    )

    training = TrainingSettings(
        architecture=raw["training"]["architecture"],
        pretrained=bool(raw["training"]["pretrained"]),
        batch_size=int(raw["training"]["batch_size"]),
        epochs=int(raw["training"]["epochs"]),
        learning_rate=float(raw["training"]["learning_rate"]),
        weight_decay=float(raw["training"]["weight_decay"]),
        num_workers=int(raw["training"]["num_workers"]),
        seed=int(raw["training"]["seed"]),
    )

    inference_raw = raw["inference"]
    reject_threshold = inference_raw.get("reject_threshold")
    inference = InferenceSettings(
        low_confidence_threshold=float(inference_raw["low_confidence_threshold"]),
        responder_review_action=inference_raw["responder_review_action"],
        reject_label=inference_raw.get("reject_label"),
        reject_threshold=float(reject_threshold) if reject_threshold is not None else None,
    )

    outputs = OutputSettings(
        checkpoints_dir=_resolve_path(project_root, raw["outputs"]["checkpoints_dir"]),
        reports_dir=_resolve_path(project_root, raw["outputs"]["reports_dir"]),
        best_checkpoint_name=raw["outputs"]["best_checkpoint_name"],
        metrics_report_name=raw["outputs"]["metrics_report_name"],
    )

    return AppConfig(
        project_root=project_root,
        config_path=config_file,
        experiment=experiment,
        dataset=dataset,
        training=training,
        inference=inference,
        outputs=outputs,
    )
