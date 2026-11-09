"""Incident registry and versioned features shared by training and inference.

The selected category is explicit context, never a label inferred from the text.
Legacy artifacts remain description-only and road-only until retrained.
"""
from __future__ import annotations

import re

INCIDENT_TYPES = (
    "road_accident", "fire_incident", "flood_landslide",
    "medical_emergency", "security_incident", "other",
)
LEGACY_INPUT_FORMAT = "description_v1"
CATEGORY_INPUT_FORMAT = "incident_type_description_v1"
INPUT_FORMATS = (LEGACY_INPUT_FORMAT, CATEGORY_INPUT_FORMAT)

_ALIASES = {
    "road": "road_accident", "vehicle_accident": "road_accident",
    "road_vehicle_accident": "road_accident",
    "fire": "fire_incident",
    "flood": "flood_landslide", "landslide": "flood_landslide",
    "medical": "medical_emergency", "security": "security_incident",
}


def normalize_incident_type(value: str | None = None) -> str:
    """Default omitted legacy categories to road; reject unknown selections."""
    if value is None or value == "":
        return "road_accident"
    if not isinstance(value, str):
        raise ValueError("Incident type must be a supported category string.")
    key = re.sub(r"[\s_/-]+", "_", value.strip().casefold()).strip("_")
    key = _ALIASES.get(key, key)
    if key not in INCIDENT_TYPES:
        raise ValueError("Unsupported incident type.")
    return key


def model_input(description: str, incident_type: str | None = None,
                input_format: str = CATEGORY_INPUT_FORMAT) -> str:
    """Keep feature construction identical in candidate training and serving."""
    category = normalize_incident_type(incident_type)
    if input_format == LEGACY_INPUT_FORMAT:
        return description
    if input_format != CATEGORY_INPUT_FORMAT:
        raise ValueError("Unsupported text model input format.")
    return f"[incident_type={category}]\nDescription: {description}"


def model_description(text: str, input_format: str) -> str:
    """Recover description evidence when checking a formatted TF-IDF input.

    Known category tokens alone must not turn an unknown description into a score.
    """
    if input_format == LEGACY_INPUT_FORMAT:
        return text
    header, separator, description = text.partition("\nDescription: ")
    if (input_format != CATEGORY_INPUT_FORMAT or not separator
            or header not in {f"[incident_type={category}]" for category in INCIDENT_TYPES}):
        raise ValueError("Text features do not match the declared input format.")
    return description


def metadata_contract(metadata: dict) -> tuple[str, list[str]]:
    """Validate declared capabilities without granting unseen category support."""
    input_format = metadata.get("input_format", LEGACY_INPUT_FORMAT)
    if input_format not in INPUT_FORMATS:
        raise ValueError("Unsupported text model input format.")
    supported = metadata.get("supported_incident_types", ["road_accident"])
    if (not isinstance(supported, list) or not supported
            or any(not isinstance(value, str) or value not in INCIDENT_TYPES for value in supported)
            or len(supported) != len(set(supported))):
        raise ValueError("Text model supported incident types are invalid.")
    if input_format == LEGACY_INPUT_FORMAT and supported != ["road_accident"]:
        raise ValueError("Legacy description-only artifacts are restricted to road accidents.")
    if input_format == CATEGORY_INPUT_FORMAT and "supported_incident_types" not in metadata:
        raise ValueError("Category-aware artifacts must declare trained incident types.")
    return input_format, supported
