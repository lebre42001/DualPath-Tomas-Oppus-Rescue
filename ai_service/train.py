"""CLI entry point for model training."""

from __future__ import annotations

import argparse
import json

from src.ai_service.defaults import resolve_active_config_path
from src.ai_service.trainer import train_from_config



def main() -> None:
    parser = argparse.ArgumentParser(description="Train the Bontoc accident severity model.")
    parser.add_argument(
        "--config",
        default=resolve_active_config_path(),
        help="Path to the YAML configuration file.",
    )
    args = parser.parse_args()

    report = train_from_config(args.config)
    print(json.dumps(report, indent=2))


if __name__ == "__main__":
    main()
