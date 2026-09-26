"""Build an auditable, content-only manifest for the local study assets."""

from __future__ import annotations

import hashlib
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SOURCE_ROOT = ROOT / "storage" / "raw"
OUTPUT = ROOT / "storage" / "import-manifest.json"
EXCLUDED_DIRS = {"docs", ".git", "node_modules", ".nuxt", ".superpowers"}
STUDY_EXTENSIONS = {".pdf", ".doc", ".docx", ".md", ".csv", ".json", ".png"}


def digest(path: Path) -> str:
    value = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            value.update(chunk)
    return value.hexdigest()


def build_manifest(source_root: Path = SOURCE_ROOT) -> dict:
    grouped: dict[str, dict] = {}
    excluded = []
    for path in source_root.rglob("*"):
        if not path.is_file():
            continue
        relative = path.relative_to(source_root)
        if any(part in EXCLUDED_DIRS for part in relative.parts):
            excluded.append(str(relative))
            continue
        if path.suffix.lower() not in STUDY_EXTENSIONS:
            continue
        file_hash = digest(path)
        entry = grouped.setdefault(file_hash, {"sha256": file_hash, "bytes": path.stat().st_size, "paths": []})
        entry["paths"].append(str(relative))
    assets = sorted(grouped.values(), key=lambda item: item["paths"][0])
    return {
        "generated_at": "2026-09-07",
        "asset_count": sum(len(item["paths"]) for item in assets),
        "logical_document_count": len(assets),
        "duplicate_groups": sum(1 for item in assets if len(item["paths"]) > 1),
        "assets": assets,
        "excluded_technical_files": excluded,
    }


if __name__ == "__main__":
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT.write_text(json.dumps(build_manifest(), ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"wrote {OUTPUT}")
