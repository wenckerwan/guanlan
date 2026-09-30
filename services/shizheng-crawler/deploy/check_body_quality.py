# -*- coding: utf-8 -*-
"""数据质量检查：正文是否被 JS 污染、各来源正文长度分布。只读。"""
import sqlite3

c = sqlite3.connect("/opt/shizheng/data/crawl.db")

print("--- 正文疑似 JS 污染检查 ---")
for kw in ["showPlayer", "function(", "scriptId", "jQuery", "window.", "var "]:
    n = c.execute("SELECT count(*) FROM articles WHERE body LIKE ?",
                  ("%" + kw + "%",)).fetchone()[0]
    print(f"{kw:12s} -> {n} 篇")

print("--- 各 source 正文长度 ---")
for r in c.execute("SELECT source, count(*), avg(length(body)), min(length(body)), "
                   "max(length(body)) FROM articles GROUP BY source"):
    print(f"source={r[0]} 篇数={r[1]} 平均={r[2]:.0f} 最短={r[3]} 最长={r[4]}")

print("--- people 各频道一篇样例 ---")
for ch in ("theory", "legal", "culture", "finance"):
    row = c.execute("SELECT title, body FROM articles WHERE source='people' AND channel=? "
                    "LIMIT 1", (ch,)).fetchone()
    if not row:
        print(f"[{ch}] 无数据")
        continue
    print(f"[{ch}] {row[0][:40]}")
    print("   ", row[1][:180].replace("\n", " "))
