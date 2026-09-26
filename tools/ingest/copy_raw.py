"""Copy manifest-listed study assets into the workspace raw directory."""

from __future__ import annotations

import argparse
import hashlib
import json
import shutil
import tempfile
from pathlib import Path, PurePosixPath


PROJECT_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_MANIFEST = PROJECT_ROOT / "storage" / "import-manifest.json"
DEFAULT_DESTINATION = PROJECT_ROOT / "storage" / "raw"


def digest(path: Path) -> str:
    value = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            value.update(chunk)
    return value.hexdigest()


def safe_relative_path(value: str) -> Path:
    normalized = PurePosixPath(value.replace("\\", "/"))
    if (
        normalized.is_absolute()
        or not normalized.parts
        or any(part in {"", ".", ".."} or ":" in part for part in normalized.parts)
    ):
        raise ValueError(f"unsafe manifest path: {value}")
    return Path(*normalized.parts)


def verify_file(path: Path, expected_bytes: int, expected_hash: str) -> None:
    if not path.is_file():
        raise FileNotFoundError(path)
    actual_bytes = path.stat().st_size
    if actual_bytes != expected_bytes:
        raise ValueError(
            f"size mismatch for {path}: expected {expected_bytes}, got {actual_bytes}"
        )
    actual_hash = digest(path)
    if actual_hash != expected_hash:
        raise ValueError(
            f"sha256 mismatch for {path}: expected {expected_hash}, got {actual_hash}"
        )


def copy_assets(source_root: Path, destination_root: Path, manifest_path: Path) -> dict:
    source_root = source_root.resolve(strict=True)
    destination_root = destination_root.resolve()
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    entries = []
    seen = set()
    total_bytes = 0

    for asset in manifest["assets"]:
        expected_bytes = int(asset["bytes"])
        expected_hash = asset["sha256"]
        for value in asset["paths"]:
            relative = safe_relative_path(value)
            if relative in seen:
                raise ValueError(f"duplicate manifest path: {value}")
            seen.add(relative)
            source = (source_root / relative).resolve()
            destination = (destination_root / relative).resolve()
            if not source.is_relative_to(source_root) or not destination.is_relative_to(
                destination_root
            ):
                raise ValueError(f"unsafe manifest path: {value}")
            verify_file(source, expected_bytes, expected_hash)
            entries.append((source, destination, expected_bytes, expected_hash))
            total_bytes += expected_bytes

    copied = 0
    skipped = 0
    for source, destination, expected_bytes, expected_hash in entries:
        if destination.is_file():
            try:
                verify_file(destination, expected_bytes, expected_hash)
            except ValueError:
                pass
            else:
                skipped += 1
                continue

        destination.parent.mkdir(parents=True, exist_ok=True)
        temporary_path = None
        try:
            with tempfile.NamedTemporaryFile(
                dir=destination.parent,
                prefix=f".{destination.name}.",
                suffix=".tmp",
                delete=False,
            ) as handle:
                temporary_path = Path(handle.name)
            shutil.copyfile(source, temporary_path)
            verify_file(temporary_path, expected_bytes, expected_hash)
            temporary_path.replace(destination)
            copied += 1
        finally:
            if temporary_path is not None and temporary_path.exists():
                temporary_path.unlink()

    return {
        "bytes": total_bytes,
        "copied": copied,
        "files": len(entries),
        "skipped": skipped,
    }


def write_manifest(manifest_path: Path, manifest: dict) -> None:
    manifest_path.parent.mkdir(parents=True, exist_ok=True)
    temporary_path = None
    try:
        with tempfile.NamedTemporaryFile(
            mode="w",
            encoding="utf-8",
            dir=manifest_path.parent,
            prefix=f".{manifest_path.name}.",
            suffix=".tmp",
            delete=False,
        ) as handle:
            temporary_path = Path(handle.name)
            json.dump(manifest, handle, ensure_ascii=False, indent=2)
        temporary_path.replace(manifest_path)
    finally:
        if temporary_path is not None and temporary_path.exists():
            temporary_path.unlink()


def repair_moved_paths(source_root: Path, manifest_path: Path) -> int:
    source_root = source_root.resolve(strict=True)
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    claimed = set()
    missing = []

    for asset in manifest["assets"]:
        for index, value in enumerate(asset["paths"]):
            relative = safe_relative_path(value)
            source = (source_root / relative).resolve()
            if not source.is_relative_to(source_root):
                raise ValueError(f"unsafe manifest path: {value}")
            if source.is_file():
                claimed.add(source)
            else:
                missing.append((asset, index, value))

    needed_sizes = {int(asset["bytes"]) for asset, _, _ in missing}
    candidates = {size: [] for size in needed_sizes}
    for path in source_root.rglob("*"):
        if not path.is_file():
            continue
        resolved = path.resolve()
        if not resolved.is_relative_to(source_root) or resolved in claimed:
            continue
        size = resolved.stat().st_size
        if size in candidates:
            candidates[size].append(resolved)

    replacements = []
    for asset, index, old_value in missing:
        matches = [
            path
            for path in candidates[int(asset["bytes"])]
            if digest(path) == asset["sha256"]
        ]
        if not matches:
            raise FileNotFoundError(f"no hash match for moved path: {old_value}")
        if len(matches) > 1:
            raise ValueError(f"multiple matches for moved path: {old_value}")
        match = matches[0]
        claimed.add(match)
        candidates[int(asset["bytes"])].remove(match)
        replacements.append((asset, index, str(match.relative_to(source_root))))

    for asset, index, value in replacements:
        asset["paths"][index] = value
    if replacements:
        write_manifest(manifest_path, manifest)
    return len(replacements)


def refresh_listed_assets(source_root: Path, manifest_path: Path) -> dict:
    source_root = source_root.resolve(strict=True)
    previous = json.loads(manifest_path.read_text(encoding="utf-8"))
    grouped = {}
    seen = set()

    for asset in previous["assets"]:
        for value in asset["paths"]:
            relative = safe_relative_path(value)
            if relative in seen:
                raise ValueError(f"duplicate manifest path: {value}")
            seen.add(relative)
            source = (source_root / relative).resolve()
            if not source.is_relative_to(source_root):
                raise ValueError(f"unsafe manifest path: {value}")
            if not source.is_file():
                raise FileNotFoundError(source)
            file_hash = digest(source)
            entry = grouped.setdefault(
                file_hash,
                {"bytes": source.stat().st_size, "paths": [], "sha256": file_hash},
            )
            entry["paths"].append(str(relative))

    assets = []
    for entry in grouped.values():
        entry["paths"].sort()
        assets.append(entry)
    assets.sort(key=lambda entry: entry["paths"][0])
    manifest = {
        "generated_at": previous.get("generated_at", "2026-09-07"),
        "asset_count": len(seen),
        "logical_document_count": len(assets),
        "duplicate_groups": sum(1 for entry in assets if len(entry["paths"]) > 1),
        "assets": assets,
        "excluded_technical_files": [],
    }
    write_manifest(manifest_path, manifest)
    return manifest


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("source_root", type=Path)
    parser.add_argument("--manifest", type=Path, default=DEFAULT_MANIFEST)
    parser.add_argument("--destination", type=Path, default=DEFAULT_DESTINATION)
    parser.add_argument("--repair-moved-paths", action="store_true")
    parser.add_argument("--refresh-listed", action="store_true")
    args = parser.parse_args()
    if args.repair_moved_paths:
        repaired = repair_moved_paths(args.source_root, args.manifest)
        print(f"repaired_paths={repaired}")
    if args.refresh_listed:
        refreshed = refresh_listed_assets(args.source_root, args.manifest)
        print(
            f"manifest assets={refreshed['asset_count']} "
            f"logical={refreshed['logical_document_count']}"
        )
    result = copy_assets(args.source_root, args.destination, args.manifest)
    print(
        f"files={result['files']} copied={result['copied']} "
        f"skipped={result['skipped']} bytes={result['bytes']}"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
