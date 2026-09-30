# -*- coding: utf-8 -*-
"""只读：找出网站候选池里「爬虫库已不存在该标题」的孤儿行。

来由（2026-10-01 实测）：候选 upsert 以 (publish_date, title) 为键，所以标题被
`_clean_title()` 洗过之后重推，会**新建**一行干净标题的候选，旧的脏标题行留在池子里
（实测 id=321「向新而生--文化--人民网」与 id=394「向新而生」并存）。

用法：
    ./venv/bin/python deploy/check_candidate_orphans.py [YYYY-MM-DD]
"""
import sqlite3
import subprocess
import sys
from datetime import date, timedelta

DB = "/opt/shizheng/data/crawl.db"


def site_titles(d: str) -> list:
    sql = (f"SELECT id, title, status FROM shizheng_candidates "
           f"WHERE publish_date='{d}' ORDER BY id;")
    out = subprocess.run(
        ["docker", "exec", "-e", f"Q={sql}", "guanlan-mysql-1", "sh", "-c",
         'mysql --default-character-set=utf8mb4 -uroot -p"$MYSQL_ROOT_PASSWORD" '
         'guanlan -N -B -e "$Q"'],
        capture_output=True, text=True, encoding="utf-8", errors="replace")
    rows = []
    for line in out.stdout.splitlines():
        if "Using a password" in line or "\t" not in line:
            continue
        rid, title, status = line.split("\t", 2)
        rows.append((rid.strip(), title.strip(), status.strip()))
    return rows


def crawler_titles(d: str) -> set:
    con = sqlite3.connect(DB)
    return {r[0].strip() for r in con.execute(
        "SELECT title FROM articles WHERE publish_date=?", (d,))}


def main() -> int:
    d = sys.argv[1] if len(sys.argv) > 1 else (
        date.today() - timedelta(days=1)).isoformat()
    site = site_titles(d)
    local = crawler_titles(d)
    if not site:
        print(f"{d}：网站候选池为空（或查询失败）")
        return 1

    orphans = [r for r in site if r[1] not in local]
    print(f"{d}：网站候选 {len(site)} 行，爬虫库标题 {len(local)} 个，"
          f"孤儿 {len(orphans)} 行")
    for rid, title, status in orphans:
        flag = "  ← 已发布，勿动" if status == "published" else ""
        print(f"  id={rid} [{status}] {title[:56]}{flag}")

    # 同标题重复行（upsert 键冲突之外的异常）
    seen, dups = {}, []
    for rid, title, status in site:
        if title in seen:
            dups.append((seen[title], rid, title, status))
        else:
            seen[title] = rid
    if dups:
        print(f"\n同标题重复 {len(dups)} 组：")
        for a, b, title, status in dups:
            print(f"  id={a} 与 id={b} [{status}] {title[:50]}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
