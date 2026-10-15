"""Run an isolated localhost-only text AI test API, without image routes."""

from __future__ import annotations

import os
from pathlib import Path


SERVICE_ROOT = Path(__file__).resolve().parent
CHECKPOINT = SERVICE_ROOT / "artifacts/checkpoints/full_pool_20261011/xlmr_all_rows_final_fit"

# This process is deliberately separate from the normal 8100 service. It exposes
# only the text router, and is intended for local evaluation of the experimental
# all-rows checkpoint. It never changes active_config.txt or report records.
os.environ.update(
    {
        "AI_OPERATION_MODE": "AI_ENABLED",
        "AI_TEXT_ENABLED": "true",
        "AI_TEXT_ALLOW_EXPERIMENTAL": "true",
        "AI_TEXT_MODEL_DIR": str(CHECKPOINT),
        "AI_TEXT_DEVICE": "cuda",
    }
)

from fastapi import FastAPI  # noqa: E402

from src.ai_service.text_api import router  # noqa: E402


app = FastAPI(
    title="Local Experimental Text Severity Test API",
    description="Local-only text-description classifier. No image prediction routes are exposed.",
    version="experimental-local",
)
app.include_router(router)


def main() -> None:
    if not CHECKPOINT.is_dir():
        raise FileNotFoundError(f"Experimental text checkpoint not found: {CHECKPOINT}")

    import uvicorn

    uvicorn.run(app, host="127.0.0.1", port=8101, reload=False)


if __name__ == "__main__":
    main()
