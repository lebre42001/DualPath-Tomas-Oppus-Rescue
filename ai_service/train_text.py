"""Separate text experimentation CLI; does not modify active image/AI configs."""
from src.ai_service.text_training import main


if __name__ == "__main__":
    raise SystemExit(main())
