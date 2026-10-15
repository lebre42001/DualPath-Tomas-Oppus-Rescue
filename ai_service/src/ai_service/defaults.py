from __future__ import annotations

import os
from pathlib import Path


DEFAULT_CONFIG = "configs/bontoc_southern_leyte_production_candidate_external.yaml"
DEFAULT_RELEVANCE_CONFIG = "configs/bontoc_southern_leyte_photo_relevance.yaml"
ACTIVE_CONFIG_POINTER = "active_config.txt"
ACTIVE_RELEVANCE_CONFIG_POINTER = "active_photo_relevance_config.txt"
PROJECT_ROOT = Path(__file__).resolve().parents[2]


def resolve_active_config_path() -> str:
    env_override = os.getenv("AI_SERVICE_CONFIG", "").strip()
    if env_override:
        return env_override

    pointer_path = PROJECT_ROOT / ACTIVE_CONFIG_POINTER
    if pointer_path.exists():
        pointed = pointer_path.read_text(encoding="utf-8").strip()
        if pointed:
            return pointed

    return DEFAULT_CONFIG


def resolve_photo_relevance_config_path() -> str:
    env_override = os.getenv("AI_PHOTO_RELEVANCE_CONFIG", "").strip()
    if env_override:
        return env_override

    pointer_path = PROJECT_ROOT / ACTIVE_RELEVANCE_CONFIG_POINTER
    if pointer_path.exists():
        pointed = pointer_path.read_text(encoding="utf-8").strip()
        if pointed:
            return pointed

    return DEFAULT_RELEVANCE_CONFIG
