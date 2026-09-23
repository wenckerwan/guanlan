"""Build a deterministic manifest for generated study datasets."""

from __future__ import annotations

import hashlib
import json
import tempfile
from pathlib import Path


REQUIRED_DATASETS = (
    "analysis_articles.json",
    "hotspots.json",
    "mistakes.json",
    "mocks.json",
    "papers.json",
    "predictions.json",
    "questions.json",
    "stats.json",
)


def digest(path: Path) -> str:
    value = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            value.update(chunk)
    return value.hexdigest()


def describe_payload(payload: object) -> tuple[int, dict[str, int]]:
    if isinstance(payload, list):
        return len(payload), {}
    if isinstance(payload, dict):
        groups = {
            key: len(value)
            for key, value in sorted(payload.items())
            if isinstance(value, list)
        }
        return sum(groups.values()), groups
    raise ValueError("dataset root must be a list or object")


def build_manifest(dataset_dir: Path, source_manifest_path: Path) -> dict:
    source = json.loads(source_manifest_path.read_text(encoding="utf-8"))
    datasets = {}
    total_items = 0
    total_bytes = 0

    for name in REQUIRED_DATASETS:
        path = dataset_dir / name
        payload = json.loads(path.read_text(encoding="utf-8"))
        items, groups = describe_payload(payload)
        size = path.stat().st_size
        datasets[name] = {
            "bytes": size,
            "groups": groups,
            "items": items,
            "sha256": digest(path),
        }
        total_items += items
        total_bytes += size

    return {
        "datasets": datasets,
        "schema_version": 1,
        "source_manifest": {
            "asset_count": source["asset_count"],
            "logical_document_count": source["logical_document_count"],
        },
        "totals": {
            "bytes": total_bytes,
            "files": len(REQUIRED_DATASETS),
            "items": total_items,
        },
    }


def write_manifest(output: Path, manifest: dict) -> None:
    output.parent.mkdir(parents=True, exist_ok=True)
    temporary_path = None
    try:
        with tempfile.NamedTemporaryFile(
            mode="w",
            encoding="utf-8",
            dir=output.parent,
            prefix=f".{output.name}.",
            suffix=".tmp",
            delete=False,
        ) as handle:
            temporary_path = Path(handle.name)
            json.dump(manifest, handle, ensure_ascii=False, indent=2, sort_keys=True)
            handle.write("\n")
        temporary_path.replace(output)
    finally:
        if temporary_path is not None and temporary_path.exists():
            temporary_path.unlink()
