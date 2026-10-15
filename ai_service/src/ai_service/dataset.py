"""Dataset helpers built around manifest CSV files."""

from __future__ import annotations

import csv
from dataclasses import dataclass
from pathlib import Path

from PIL import Image
from torch.utils.data import Dataset
from torchvision import transforms

from .labels import normalize_manifest_label, normalize_source_domain


@dataclass(frozen=True)
class ManifestRecord:
    image_relative_path: str
    severity_label: str
    source_domain: str
    municipality: str
    province: str
    barangay: str
    latitude: str
    longitude: str
    incident_type: str
    description: str
    weather: str
    review_status: str


def read_manifest(manifest_path: Path) -> list[ManifestRecord]:
    records: list[ManifestRecord] = []
    with manifest_path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        for row in reader:
            if not row.get("image_relative_path"):
                continue
            records.append(
                ManifestRecord(
                    image_relative_path=row["image_relative_path"].strip(),
                    severity_label=normalize_manifest_label(row["severity_label"]),
                    source_domain=normalize_source_domain(row["source_domain"]),
                    municipality=row.get("municipality", "").strip(),
                    province=row.get("province", "").strip(),
                    barangay=row.get("barangay", "").strip(),
                    latitude=row.get("latitude", "").strip(),
                    longitude=row.get("longitude", "").strip(),
                    incident_type=row.get("incident_type", "").strip(),
                    description=row.get("description", "").strip(),
                    weather=row.get("weather", "").strip(),
                    review_status=row.get("review_status", "").strip(),
                )
            )
    return records


def build_transforms(image_size: int, training: bool):
    if training:
        return transforms.Compose(
            [
                transforms.Resize((image_size + 24, image_size + 24)),
                transforms.RandomResizedCrop(image_size, scale=(0.85, 1.0)),
                transforms.RandomHorizontalFlip(),
                transforms.ColorJitter(brightness=0.12, contrast=0.12, saturation=0.08),
                transforms.ToTensor(),
                transforms.Normalize(mean=(0.485, 0.456, 0.406), std=(0.229, 0.224, 0.225)),
            ]
        )

    return transforms.Compose(
        [
            transforms.Resize((image_size, image_size)),
            transforms.ToTensor(),
            transforms.Normalize(mean=(0.485, 0.456, 0.406), std=(0.229, 0.224, 0.225)),
        ]
    )


class SeverityManifestDataset(Dataset):
    def __init__(
        self,
        dataset_root: Path,
        records: list[ManifestRecord],
        label_to_index: dict[str, int],
        image_size: int,
        training: bool,
    ) -> None:
        self.dataset_root = dataset_root
        self.records = records
        self.label_to_index = label_to_index
        self.transform = build_transforms(image_size=image_size, training=training)

    def __len__(self) -> int:
        return len(self.records)

    def __getitem__(self, index: int):
        record = self.records[index]
        image_path = (self.dataset_root / record.image_relative_path).resolve()
        if not image_path.exists():
            raise FileNotFoundError(f"Image referenced in manifest was not found: {image_path}")

        image = Image.open(image_path).convert("RGB")
        tensor = self.transform(image)
        label_index = self.label_to_index[record.severity_label]

        metadata = {
            "image_relative_path": record.image_relative_path,
            "source_domain": record.source_domain,
            "municipality": record.municipality,
            "province": record.province,
            "barangay": record.barangay,
            "incident_type": record.incident_type,
        }
        return tensor, label_index, metadata
