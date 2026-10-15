"""Create counted synthetic review data; never approve or activate a model."""
from src.ai_service.text_synthetic_pack import main


if __name__ == "__main__":
    raise SystemExit(main())
