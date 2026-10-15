"""Generate or check severity browsing folders; never train or approve a model."""
from src.ai_service.text_dataset_views import main


if __name__ == "__main__":
    raise SystemExit(main())
