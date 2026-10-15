"""Convenience entry point for launching the FastAPI service."""

from __future__ import annotations

import os

import uvicorn

from src.ai_service.checkpoint_sync import sync_checkpoints_from_env
from src.ai_service.operation_mode import ai_enabled, operation_mode



def main() -> None:
    host = os.getenv("AI_SERVICE_HOST", "0.0.0.0")
    port = int(os.getenv("PORT", os.getenv("AI_SERVICE_PORT", "8100")))

    if ai_enabled():
        sync_checkpoints_from_env()
    else:
        print(f"[ai-service] operation mode {operation_mode()}; checkpoint sync skipped", flush=True)

    uvicorn.run("src.ai_service.api:app", host=host, port=port, reload=False)


if __name__ == "__main__":
    main()
