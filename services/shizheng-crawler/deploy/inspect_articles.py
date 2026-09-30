# -*- coding: utf-8 -*-
"""只读：看某几篇的旧正文全文与存档 HTML 里有哪些 <p>，判断是真稿还是版面页。"""
import sqlite3
import sys
from pathlib import Path

sys.path.insert(0, "/opt/shizheng")
from src.core import htmltext  # noqa: E402

ids = [int(x) for x in sys.argv[1:]] or [325, 326, 341, 342, 361, 362]
con = sqlite3.connect("/opt/shizheng/data/crawl.db")
for aid in ids:
    row = con.execute(
        "SELECT id, title, url, channel, body, raw_path FROM articles WHERE id=?",
        (aid,)).fetchone()
    if not row:
        print(f"id={aid} 不存在")
        continue
    _id, title, url, channel, body, raw = row
    print("=" * 78)
    print(f"id={aid} [{channel}] {title}")
    print(f"url={url}")
    print(f"旧正文 {len(body or '')} 字 / {htmltext.cjk_count(body or '')} 汉字：")
    for i, line in enumerate((body or "").split("\n")[:12], 1):
        print(f"  {i:2d}| {line[:110]}")
    p = Path(raw or "")
    if p.is_file():
        data = p.read_bytes()
        html = htmltext.decode_html(data, htmltext.declared_charset(None, data))
        ps = htmltext.paragraphs(htmltext.strip_noise(html))
        print(f"存档 HTML 里可用 <p> 段：{len(ps)} 段")
        for i, t in enumerate(ps[:6], 1):
            print(f"  p{i}| {t[:110]}")
    else:
        print("存档 HTML 不存在")
