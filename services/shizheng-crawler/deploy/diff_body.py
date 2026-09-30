# -*- coding: utf-8 -*-
"""对比一篇正文的旧值与「从存档 HTML 重提」的新值，确认减少的是噪声不是内容。只读。"""
import sqlite3
import sys
from pathlib import Path

sys.path.insert(0, "/opt/shizheng")
from src.core import htmltext  # noqa: E402

aid = int(sys.argv[1]) if len(sys.argv) > 1 else 87
con = sqlite3.connect("/opt/shizheng/data/crawl.db")
title, old, raw = con.execute(
    "SELECT title, body, raw_path FROM articles WHERE id=?", (aid,)).fetchone()
data = Path(raw).read_bytes()
new = htmltext.extract_body(
    htmltext.decode_html(data, htmltext.declared_charset(None, data)))

print(f"id={aid} {title}")
print(f"旧 {len(old)} 字 / {htmltext.cjk_count(old)} 汉字；"
      f"新 {len(new)} 字 / {htmltext.cjk_count(new)} 汉字")
print("\n--- 旧 开头 200 ---\n" + (old or "")[:200])
print("\n--- 新 开头 200 ---\n" + new[:200])
print("\n--- 旧 结尾 300 ---\n" + (old or "")[-300:])
print("\n--- 新 结尾 300 ---\n" + new[-300:])

# 旧有新没有的段落（前 10 条），用来判断丢的是不是真内容
old_ps = [p.strip() for p in (old or "").split("\n") if p.strip()]
new_ps = {p.strip() for p in new.split("\n") if p.strip()}
lost = [p for p in old_ps if p not in new_ps]
print(f"\n--- 旧有新无的段落 {len(lost)} 条（前 10） ---")
for p in lost[:10]:
    print(f"  [{len(p)} 字] {p[:120]}")
