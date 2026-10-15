"""Startup checkpoint download helpers for hosted AI deployments."""

from __future__ import annotations

import hashlib
import os
import shutil
import sys
import tempfile
import urllib.request
from dataclasses import dataclass
from pathlib import Path

from .config import AppConfig, load_config
from .defaults import resolve_active_config_path, resolve_photo_relevance_config_path


MIN_CHECKPOINT_BYTES = 1024 * 1024


@dataclass(frozen=True)
class CheckpointTarget:
    name: str
    config_path: str
    url_env: str
    sha_env: str


def _truthy(value: str | None, default: bool = False) -> bool:
    if value is None:
        return default

    return value.strip().lower() in {"1", "true", "yes", "on"}


def _sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)

    return digest.hexdigest()


def is_usable_checkpoint(path: Path) -> bool:
    """Return false for missing files and Git LFS pointer placeholders."""

    if not path.exists() or path.stat().st_size < MIN_CHECKPOINT_BYTES:
        return False

    try:
        with path.open("rb") as handle:
            prefix = handle.read(128)
    except OSError:
        return False

    return not prefix.startswith(b"version https://git-lfs.github.com/spec/")


def _copy_or_download(url: str, destination: Path, timeout: int) -> None:
    destination.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.NamedTemporaryFile(delete=False, dir=destination.parent, suffix=".download") as temp_file:
        temp_path = Path(temp_file.name)

    try:
        if url.startswith("file://"):
            source = Path(url.removeprefix("file://")).expanduser().resolve()
            shutil.copyfile(source, temp_path)
        else:
            request = urllib.request.Request(url, headers={"User-Agent": "BontocRescueAI/0.1"})
            with urllib.request.urlopen(request, timeout=timeout) as response, temp_path.open("wb") as handle:
                shutil.copyfileobj(response, handle)

        temp_path.replace(destination)
    except Exception:
        temp_path.unlink(missing_ok=True)
        raise


def _sync_target(target: CheckpointTarget, force_download: bool, timeout: int) -> dict[str, object]:
    config: AppConfig = load_config(target.config_path)
    destination = config.outputs.best_checkpoint_path
    url = os.getenv(target.url_env, "").strip()
    expected_sha = os.getenv(target.sha_env, "").strip().lower()
    already_ready = is_usable_checkpoint(destination)

    result: dict[str, object] = {
        "name": target.name,
        "path": str(destination),
        "already_ready": already_ready,
        "downloaded": False,
    }

    if already_ready and not force_download:
        return result

    if not url:
        result["skipped"] = f"{target.url_env} is not set"
        return result

    _copy_or_download(url, destination, timeout)

    if not is_usable_checkpoint(destination):
        raise RuntimeError(f"Downloaded checkpoint for {target.name} is missing or too small: {destination}")

    actual_sha = _sha256(destination)
    result["sha256"] = actual_sha
    if expected_sha and actual_sha.lower() != expected_sha:
        destination.unlink(missing_ok=True)
        raise RuntimeError(
            f"Downloaded checkpoint for {target.name} failed SHA256 verification. "
            f"Expected {expected_sha}, got {actual_sha}."
        )

    result["downloaded"] = True
    return result


def sync_checkpoints_from_env() -> list[dict[str, object]]:
    """Download configured checkpoints before FastAPI imports the predictors."""

    if not _truthy(os.getenv("AI_CHECKPOINT_SYNC_ENABLED"), default=True):
        return []

    force_download = _truthy(os.getenv("AI_CHECKPOINT_FORCE_DOWNLOAD"), default=False)
    timeout = int(os.getenv("AI_CHECKPOINT_DOWNLOAD_TIMEOUT", "120"))
    targets = [
        CheckpointTarget(
            name="severity",
            config_path=resolve_active_config_path(),
            url_env="AI_SEVERITY_CHECKPOINT_URL",
            sha_env="AI_SEVERITY_CHECKPOINT_SHA256",
        ),
        CheckpointTarget(
            name="photo_relevance",
            config_path=resolve_photo_relevance_config_path(),
            url_env="AI_PHOTO_RELEVANCE_CHECKPOINT_URL",
            sha_env="AI_PHOTO_RELEVANCE_CHECKPOINT_SHA256",
        ),
    ]

    results = []
    for target in targets:
        try:
            result = _sync_target(target, force_download=force_download, timeout=timeout)
            print(f"[checkpoint-sync] {target.name}: {result}", file=sys.stderr, flush=True)
            results.append(result)
        except Exception as exc:
            print(f"[checkpoint-sync] {target.name} failed: {exc}", file=sys.stderr, flush=True)
            if _truthy(os.getenv("AI_CHECKPOINT_SYNC_REQUIRED"), default=False):
                raise
            results.append({"name": target.name, "error": str(exc)})

    return results
