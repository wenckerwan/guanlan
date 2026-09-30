# -*- coding: utf-8 -*-
"""清理 people 轨道历史标题里的站点后缀（如「×××--理论-中国共产党新闻网」）。

新版 people_sitemap._clean_title() 已会在入库前剥后缀，本脚本只负责把**旧代码存进库的**
71 条（2026-09-29 那轮）拉齐，避免 pipeline 把带后缀的标题推成 hotspots 标题。
rmrb 标题不含后缀，脚本也只处理 source='people'。

用法：
    ./venv/bin/python deploy/clean_title_suffix.py            # dry-run
    ./venv/bin/python deploy/clean_title_suffix.py --apply    # 写库（跑前先备份 crawl.db）
"""
import sqlite3
import sys
from pathlib import Path

sys.path.insert(0, "/opt/shizheng")
from src.sources.people_sitemap import _clean_title  # noqa: E402

DB = Path("/opt/shizheng/data/crawl.db")


def main() -> int:
    apply = "--apply" in sys.argv
    con = sqlite3.connect(str(DB), timeout=30)
    rows = con.execute(
        "SELECT id, title FROM articles WHERE source='people'").fetchall()

    changes = [(i, t, _clean_title(t)) for i, t in rows if t and _clean_title(t) != t]
    print(f"people 共 {len(rows)} 篇；需清理标题 {len(changes)} 条")
    for aid, old, new in changes[:80]:
        print(f"  id={aid}\n    - {old}\n    + {new}")
    if len(changes) > 80:
        print(f"  ...（另有 {len(changes) - 80} 条）")

    if not apply:
        print("\nDRY-RUN（未写库）。确认后加 --apply 执行。")
        return 0
    if not changes:
        print("\n无可清理项。")
        return 0

    con.execute("BEGIN IMMEDIATE")
    for aid, _old, new in changes:
        con.execute("UPDATE articles SET title=? WHERE id=?", (new, aid))
    con.commit()
    con.close()
    print(f"\nAPPLIED：已更新 {len(changes)} 条标题。")
    return 0


if __name__ == "__main__":
    sys.exit(main())
