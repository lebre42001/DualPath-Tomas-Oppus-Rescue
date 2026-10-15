"""Convenience entry point for launching the FastAPI service."""

from __future__ import annotations

import os

import uvicorn


def main() -> None:
    host = os.getenv("AI_SERVICE_HOST", "0.0.0.0")
    port = int(os.getenv("PORT", os.getenv("AI_SERVICE_PORT", "8100")))

    uvicorn.run("src.ai_service.api:app", host=host, port=port, reload=False)


if __name__ == "__main__":
    main()
