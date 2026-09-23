"""观澜 ingest 总入口：从只读源目录生成 storage/dataset/*.json。"""
from __future__ import annotations

import json
import subprocess
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
OUT = HERE.parents[1] / "storage" / "dataset"

STEPS = [
    ("build_questions.py", "papers.json + questions.json"),
    ("build_articles.py", "analysis_articles.json + hotspots.json + predictions.json"),
    ("build_mistakes.py", "mistakes.json"),
    ("build_mocks.py", "mocks.json + stats.json"),
]


def main() -> int:
    OUT.mkdir(parents=True, exist_ok=True)
    failed = []
    for script, produces in STEPS:
        print(f"== {script} -> {produces}")
        result = subprocess.run([sys.executable, str(HERE / script)], cwd=HERE)
        if result.returncode != 0:
            failed.append(script)

    print("\n== dataset summary")
    for path in sorted(OUT.glob("*.json")):
        size = path.stat().st_size
        try:
            payload = json.loads(path.read_text(encoding="utf-8"))
            count = len(payload) if isinstance(payload, list) else (
                sum(len(v) for v in payload.values() if isinstance(v, list)) if isinstance(payload, dict) else "?"
            )
        except Exception:
            count = "?"
        print(f"  {path.name:<26} {size / 1024:9.1f} KB  items={count}")

    if failed:
        print("\nFAILED:", ", ".join(failed))
        return 1
    print("\nOK")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
