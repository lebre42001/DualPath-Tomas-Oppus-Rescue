"""Shared label helpers for the accident severity service."""

from __future__ import annotations

SEVERITY_LABELS = ("minor", "serious", "fatal")
RELEVANCE_LABELS = ("related", "unrelated")
SUPPORTED_MANIFEST_LABELS = SEVERITY_LABELS + RELEVANCE_LABELS
SOURCE_DOMAINS = ("road_accident", "general_accident", "emergency_scene", "ui_reference", "public_non_incident_reference")

DISPLAY_LABELS = {
    "minor": "Minor",
    "serious": "Serious",
    "fatal": "Fatal",
    "related": "Related",
    "unrelated": "Unrelated",
}


def normalize_manifest_label(label: str) -> str:
    normalized = label.strip().lower()
    if normalized not in SUPPORTED_MANIFEST_LABELS:
        raise ValueError(f"Unsupported manifest label: {label!r}")
    return normalized


def normalize_source_domain(domain: str) -> str:
    normalized = domain.strip().lower()
    if normalized not in SOURCE_DOMAINS:
        raise ValueError(f"Unsupported source domain: {domain!r}")
    return normalized
