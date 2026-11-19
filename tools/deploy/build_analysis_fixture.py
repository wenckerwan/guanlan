"""Build synthetic article data for the disposable release gateway."""

import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
from tools.ingest.dataset_manifest import build_manifest, write_manifest


def main(scratch: Path) -> None:
    storage = scratch / "storage"
    dataset = storage / "dataset"
    release = "2012-2026-649d1f95"
    retired = ["2012-2026-45208c3f", "2012-2026-v2-4f5624ad"]
    rows = []
    for i, slug in enumerate([*map(lambda n: f"fixture-{n}", range(10)), release, *retired]):
        title = f"Fixture article {i}"
        rows.append({
            "slug": slug, "title": title, "release": slug == release,
            "category": "选择题规律" if i < 3 or slug == release else "Fixture",
            "priority": "A", "summary": "Fixture", "html": "<p>Fixture body</p>",
            "outline": [{"level": 2, "title": f"Section {n}"} for n in range(37 if slug == release else 1)],
            "source_file": f"fixture-{i}.md", "word_count": 12, "sort_order": i,
        })
    legacy = [{**row, "outline": [{"level": 1, "title": row["title"]}, *row["outline"]]} for row in rows]
    (scratch / "legacy-analysis.json").write_text(json.dumps(legacy), encoding="utf-8")
    (dataset / "analysis_articles.json").write_text(json.dumps(rows[:-2]), encoding="utf-8")
    source = storage / "import-manifest.json"
    write_manifest(storage / "dataset-manifest.json", build_manifest(dataset, source))


if __name__ == "__main__":
    main(Path(sys.argv[1]))
