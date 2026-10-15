"""Download Wikimedia Commons negative examples for photo relevance training.

The downloaded images are intentionally stored under the ignored dataset image
tree. The CSV manifests and source/license metadata remain tracked so the
acquisition can be audited and reproduced.
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import re
import time
import urllib.parse
import urllib.request
from dataclasses import dataclass
from datetime import datetime, timezone
from io import BytesIO
from pathlib import Path
from typing import Iterable

from PIL import Image


PROJECT_ROOT = Path(__file__).resolve().parents[1]
DATASET_ROOT = PROJECT_ROOT / "datasets" / "bontoc_southern_leyte"
METADATA_DIR = DATASET_ROOT / "metadata"
OUTPUT_ROOT = DATASET_ROOT / "curated" / "wikimedia_relevance_negatives_2026_05_13" / "images"
SOURCE_CSV = METADATA_DIR / "wikimedia_relevance_negative_sources_2026_05_13.csv"
MANIFESTS = {
    "train": METADATA_DIR / "train_photo_relevance.csv",
    "val": METADATA_DIR / "val_photo_relevance.csv",
    "test": METADATA_DIR / "test_photo_relevance.csv",
}
USER_AGENT = "BontocRescueDatasetBuilder/0.1 (local capstone research)"
REVIEW_STATUS = "relevance_negative_wikimedia_2026_05_13"
MANIFEST_FIELDS = [
    "image_relative_path",
    "severity_label",
    "source_domain",
    "municipality",
    "province",
    "barangay",
    "latitude",
    "longitude",
    "incident_type",
    "description",
    "weather",
    "review_status",
]
SOURCE_FIELDS = [
    "image_relative_path",
    "split",
    "category",
    "commons_title",
    "commons_page_url",
    "download_url",
    "license_short_name",
    "usage_terms",
    "artist",
    "credit",
    "downloaded_at",
]


@dataclass(frozen=True)
class CategoryPlan:
    title: str
    description: str


CATEGORY_PLANS = [
    CategoryPlan("Category:Food", "food image"),
    CategoryPlan("Category:Meals", "meal or food image"),
    CategoryPlan("Category:Cats", "pet cat image"),
    CategoryPlan("Category:Dogs", "pet dog image"),
    CategoryPlan("Category:Bedrooms", "bedroom or indoor room image"),
    CategoryPlan("Category:Living rooms", "living room or indoor room image"),
    CategoryPlan("Category:Rooms", "indoor room image"),
    CategoryPlan("Category:Documents", "document or paper image"),
    CategoryPlan("Category:Receipts", "receipt or document image"),
    CategoryPlan("Category:Roads in the Philippines", "normal Philippine road image with no emergency scene"),
]


def strip_html(value: object) -> str:
    text = str(value or "")
    text = re.sub(r"<[^>]+>", "", text)
    return " ".join(text.split())


def extmetadata_value(metadata: dict[str, object], key: str) -> str:
    value = metadata.get(key)
    if isinstance(value, dict):
        return strip_html(value.get("value", ""))
    return ""


def commons_api(params: dict[str, object]) -> dict[str, object]:
    encoded = urllib.parse.urlencode(params)
    request = urllib.request.Request(
        "https://commons.wikimedia.org/w/api.php?" + encoded,
        headers={"User-Agent": USER_AGENT},
    )
    with urllib.request.urlopen(request, timeout=35) as response:
        return json.loads(response.read().decode("utf-8"))


def category_files(category: str, limit: int) -> Iterable[dict[str, object]]:
    remaining = limit
    continuation: dict[str, object] = {}

    while remaining > 0:
        batch_limit = min(50, remaining)
        params: dict[str, object] = {
            "action": "query",
            "generator": "categorymembers",
            "gcmtitle": category,
            "gcmtype": "file",
            "gcmlimit": batch_limit,
            "prop": "imageinfo",
            "iiprop": "url|mime|extmetadata",
            "iiurlwidth": 640,
            "format": "json",
            "formatversion": "2",
        }
        params.update(continuation)
        data = commons_api(params)
        pages = data.get("query", {}).get("pages", [])
        if not isinstance(pages, list) or not pages:
            return

        yielded = 0
        for page in pages:
            if isinstance(page, dict):
                yielded += 1
                yield page

        remaining -= yielded
        if "continue" not in data:
            return

        continuation = data["continue"]
        time.sleep(0.2)


def is_supported_image(page: dict[str, object]) -> bool:
    title = str(page.get("title", ""))
    image_info = (page.get("imageinfo") or [{}])[0]
    mime = str(image_info.get("mime", ""))
    lower_title = title.lower()

    if not mime.startswith("image/"):
        return False

    return not lower_title.endswith((".svg", ".gif", ".pdf", ".webm", ".tif", ".tiff"))


def stable_name(title: str, ordinal: int) -> str:
    digest = hashlib.sha1(title.encode("utf-8")).hexdigest()[:10]
    return f"wikimedia_unrelated_{ordinal:04d}_{digest}.jpg"


def split_for_ordinal(ordinal: int) -> str:
    bucket = ordinal % 20
    if bucket < 14:
        return "train"
    if bucket < 17:
        return "val"
    return "test"


def source_records() -> list[dict[str, str]]:
    if not SOURCE_CSV.exists():
        return []

    with SOURCE_CSV.open("r", encoding="utf-8-sig", newline="") as handle:
        return list(csv.DictReader(handle))


def existing_source_titles() -> set[str]:
    return {
        row.get("commons_title", "")
        for row in source_records()
        if row.get("commons_title")
    }


def manifest_rows(path: Path) -> list[dict[str, str]]:
    if not path.exists():
        return []

    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        return list(csv.DictReader(handle))


def write_manifest(path: Path, rows: list[dict[str, str]]) -> None:
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=MANIFEST_FIELDS)
        writer.writeheader()
        writer.writerows(rows)


def write_sources(rows: list[dict[str, str]]) -> None:
    with SOURCE_CSV.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=SOURCE_FIELDS)
        writer.writeheader()
        writer.writerows(rows)


def download_as_jpeg(url: str, destination: Path) -> bool:
    request = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
    with urllib.request.urlopen(request, timeout=45) as response:
        payload = response.read()

    with Image.open(BytesIO(payload)) as image:
        image = image.convert("RGB")
        if min(image.size) < 180:
            return False
        destination.parent.mkdir(parents=True, exist_ok=True)
        image.thumbnail((768, 768))
        image.save(destination, "JPEG", quality=88, optimize=True)

    return True


def build_rows(page: dict[str, object], plan: CategoryPlan, split: str, relative_path: str, download_url: str) -> tuple[dict[str, str], dict[str, str]]:
    title = str(page.get("title", ""))
    image_info = (page.get("imageinfo") or [{}])[0]
    metadata = image_info.get("extmetadata") if isinstance(image_info, dict) else {}
    metadata = metadata if isinstance(metadata, dict) else {}
    page_url = "https://commons.wikimedia.org/wiki/" + urllib.parse.quote(title.replace(" ", "_"), safe="/:_")
    description = (
        f"Public Wikimedia Commons non-incident {plan.description} used as an unrelated "
        "dummy-photo negative example for photo relevance rejection training."
    )

    manifest_row = {
        "image_relative_path": relative_path,
        "severity_label": "unrelated",
        "source_domain": "public_non_incident_reference",
        "municipality": "",
        "province": "",
        "barangay": "",
        "latitude": "",
        "longitude": "",
        "incident_type": "non_incident_reference",
        "description": description,
        "weather": "n/a",
        "review_status": REVIEW_STATUS,
    }
    source_row = {
        "image_relative_path": relative_path,
        "split": split,
        "category": plan.title,
        "commons_title": title,
        "commons_page_url": page_url,
        "download_url": download_url,
        "license_short_name": extmetadata_value(metadata, "LicenseShortName"),
        "usage_terms": extmetadata_value(metadata, "UsageTerms"),
        "artist": extmetadata_value(metadata, "Artist"),
        "credit": extmetadata_value(metadata, "Credit"),
        "downloaded_at": datetime.now(timezone.utc).isoformat(),
    }
    return manifest_row, source_row


def acquire(max_images: int, per_category: int, dry_run: bool) -> dict[str, object]:
    existing_titles = existing_source_titles()
    source_rows = source_records()
    manifests = {split: manifest_rows(path) for split, path in MANIFESTS.items()}
    existing_manifest_paths = {
        row.get("image_relative_path", "")
        for rows in manifests.values()
        for row in rows
    }
    added = 0
    skipped = 0

    for plan in CATEGORY_PLANS:
        if added >= max_images:
            break

        category_added = 0
        for page in category_files(plan.title, per_category * 4):
            if added >= max_images or category_added >= per_category:
                break

            title = str(page.get("title", ""))
            if not title or title in existing_titles or not is_supported_image(page):
                skipped += 1
                continue

            image_info = (page.get("imageinfo") or [{}])[0]
            download_url = str(image_info.get("thumburl") or image_info.get("url") or "")
            if not download_url:
                skipped += 1
                continue

            ordinal = len(source_rows) + added + 1
            split = split_for_ordinal(ordinal)
            filename = stable_name(title, ordinal)
            relative_path = f"curated/wikimedia_relevance_negatives_2026_05_13/images/{split}/unrelated/{filename}"
            destination = DATASET_ROOT / relative_path
            if relative_path in existing_manifest_paths:
                skipped += 1
                continue

            if not dry_run:
                try:
                    if not download_as_jpeg(download_url, destination):
                        skipped += 1
                        continue
                except Exception:
                    skipped += 1
                    continue

            manifest_row, source_row = build_rows(page, plan, split, relative_path, download_url)
            manifests[split].append(manifest_row)
            source_rows.append(source_row)
            existing_titles.add(title)
            existing_manifest_paths.add(relative_path)
            added += 1
            category_added += 1
            time.sleep(0.1)

    if not dry_run:
        for split, rows in manifests.items():
            write_manifest(MANIFESTS[split], rows)
        write_sources(source_rows)

    return {
        "added": added,
        "skipped": skipped,
        "dry_run": dry_run,
        "source_csv": str(SOURCE_CSV),
        "output_root": str(OUTPUT_ROOT),
        "manifest_sizes": {split: len(rows) for split, rows in manifests.items()},
    }


def main() -> None:
    parser = argparse.ArgumentParser(description="Acquire public Wikimedia negative examples for photo relevance training.")
    parser.add_argument("--max-images", type=int, default=180)
    parser.add_argument("--per-category", type=int, default=22)
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()

    report = acquire(max_images=args.max_images, per_category=args.per_category, dry_run=args.dry_run)
    print(json.dumps(report, indent=2))


if __name__ == "__main__":
    main()
