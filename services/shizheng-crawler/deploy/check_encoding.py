# -*- coding: utf-8 -*-
"""检查 rmrb 标题/正文是否存在编码乱码（日志里曾出现 mojibake）。只读。"""
import re
import sqlite3

c = sqlite3.connect("/opt/shizheng/data/crawl.db")

MOJIBAKE = re.compile(r"[а-яА-Я∞≈еЕіиЊєеѓМж∞СзЪД]")

rows = c.execute("SELECT id, channel, title, body FROM articles WHERE source='rmrb'").fetchall()
print(f"rmrb 共 {len(rows)} 篇")
bad_title = [r for r in rows if MOJIBAKE.search(r[2] or "")]
bad_body = [r for r in rows if MOJIBAKE.search((r[3] or "")[:2000])]
print(f"标题乱码 {len(bad_title)} 篇；正文乱码 {len(bad_body)} 篇")
for r in bad_title[:5]:
    print("  TITLE?", r[0], r[1], repr(r[2][:60]))
for r in bad_body[:3]:
    print("  BODY?", r[0], r[1], repr((r[3] or "")[:120]))

print("--- rmrb 第18版 全部标题 ---")
for r in c.execute("SELECT id, title FROM articles WHERE source='rmrb' AND channel='第18版'"):
    print(" ", r[0], repr(r[1][:60]))

print("--- people 乱码检查 ---")
rows2 = c.execute("SELECT id, title FROM articles WHERE source='people'").fetchall()
print("people 标题乱码",
      sum(1 for r in rows2 if MOJIBAKE.search(r[1] or "")), "/", len(rows2))
