"""Run an isolated localhost-only text AI test API, without image routes."""

from __future__ import annotations

import os
from pathlib import Path


SERVICE_ROOT = Path(__file__).resolve().parent
LEGACY_CHECKPOINT = "artifacts/checkpoints/full_pool_20261011/xlmr_all_rows_final_fit"


def checkpoint_configuration(environ=None, service_root: Path | None = None) -> tuple[Path, bool, str]:
    """Resolve a Lab-only checkpoint without loading model or GPU libraries.

    An explicit path must stay inside this checkout's ai_service directory,
    including after resolving directory links. Unset preserves the old CUDA
    checkpoint selection for existing full-training environments.
    """
    environ = os.environ if environ is None else environ
    root = (SERVICE_ROOT if service_root is None else service_root).resolve()
    raw = environ.get("AI_TEXT_TEST_MODEL_DIR", "").strip()
    if not raw:
        return root / LEGACY_CHECKPOINT, False, "cuda"
    path = Path(raw)
    path = (path if path.is_absolute() else root / path).resolve()
    try:
        path.relative_to(root)
    except ValueError as exc:
        raise ValueError("AI_TEXT_TEST_MODEL_DIR must stay inside this repository's ai_service directory.") from exc
    if not path.is_dir():
        raise FileNotFoundError(f"Configured local AI Lab checkpoint directory does not exist: {path}")
    if not (path / "metadata.json").is_file():
        raise FileNotFoundError(f"Configured local AI Lab checkpoint has no metadata.json: {path}")
    device = environ.get("AI_TEXT_TEST_DEVICE", "cpu").strip().lower()
    if device not in {"cpu", "cuda"}:
        raise ValueError("AI_TEXT_TEST_DEVICE must be cpu or cuda.")
    return path, True, device


CHECKPOINT, EXPLICIT_TEST_MODEL, TEXT_DEVICE = checkpoint_configuration()

# This process is deliberately separate from the normal 8100 service. It exposes
# only the text router, and is intended for local evaluation of the experimental
# checkpoints. These overrides affect only this separate process. It never
# changes active_config.txt, the live 8100 service, or report records.
runtime_settings = {
        "AI_OPERATION_MODE": "AI_ENABLED",
        "AI_TEXT_ENABLED": "true",
        "AI_TEXT_ALLOW_EXPERIMENTAL": "true",
        "AI_TEXT_MODEL_DIR": str(CHECKPOINT),
        "AI_TEXT_DEVICE": TEXT_DEVICE,
}
if EXPLICIT_TEST_MODEL:
    # Lab policy is separate from the inherited live-service rules setting.
    runtime_settings["AI_TEXT_RULES_ENABLED"] = os.getenv("AI_TEXT_TEST_RULES_ENABLED", "true")
os.environ.update(runtime_settings)

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
