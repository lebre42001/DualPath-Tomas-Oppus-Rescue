"""Fail-closed, service-wide inference mode switch."""

from __future__ import annotations

import os


MANUAL_ONLY = "MANUAL_ONLY"
AI_ENABLED = "AI_ENABLED"


def operation_mode() -> str:
    """Enable model work only for the explicit AI_ENABLED opt-in value."""
    value = os.getenv("AI_OPERATION_MODE", MANUAL_ONLY).strip().upper()
    return AI_ENABLED if value == AI_ENABLED else MANUAL_ONLY


def ai_enabled() -> bool:
    return operation_mode() == AI_ENABLED
