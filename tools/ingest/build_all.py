"""观澜 ingest 总入口：从只读源目录生成 storage/dataset/*.json。"""
from __future__ import annotations

import subprocess
import sys
from pathlib import Path

try:
    from .dataset_manifest import build_manifest, write_manifest
except ImportError:
    from dataset_manifest import build_manifest, write_manifest

HERE = Path(__file__).resolve().parent
OUT = HERE.parents[1] / "storage" / "dataset"
SOURCE_MANIFEST = HERE.parents[1] / "storage" / "import-manifest.json"
DATASET_MANIFEST = HERE.parents[1] / "storage" / "dataset-manifest.json"

STEPS = [
    ("build_questions.py", "papers.json + questions.json"),
    ("build_articles.py", "analysis_articles.json + hotspots.json + predictions.json"),
    ("build_mistakes.py", "mistakes.json"),
    ("build_mocks.py", "mocks.json + stats.json"),
]


def publish_manifest(dataset_dir: Path, source_manifest: Path, output: Path) -> dict:
    manifest = build_manifest(dataset_dir, source_manifest)
    write_manifest(output, manifest)
    return manifest


def main() -> int:
    OUT.mkdir(parents=True, exist_ok=True)
    for script, produces in STEPS:
        print(f"== {script} -> {produces}")
        result = subprocess.run([sys.executable, str(HERE / script)], cwd=HERE)
        if result.returncode != 0:
            print(f"\nFAILED: {script}")
            return 1

    print("\n== manifest.py -> import-manifest.json")
    result = subprocess.run([sys.executable, str(HERE / "manifest.py")], cwd=HERE)
    if result.returncode != 0:
        print("\nFAILED: manifest.py")
        return 1

    manifest = publish_manifest(OUT, SOURCE_MANIFEST, DATASET_MANIFEST)
    print("\n== dataset summary")
    for name, details in manifest["datasets"].items():
        print(
            f"  {name:<26} {details['bytes'] / 1024:9.1f} KB  "
            f"items={details['items']}  sha256={details['sha256']}"
        )
    totals = manifest["totals"]
    print(
        f"  totals: files={totals['files']}  items={totals['items']}  "
        f"bytes={totals['bytes']}"
    )
    print("\nOK")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
