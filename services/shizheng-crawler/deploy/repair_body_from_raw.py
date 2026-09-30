# -*- coding: utf-8 -*-
"""用存档的原始 HTML 重提正文，修掉旧代码存下的 JS 污染正文。只改 body 一列。

背景：2026-09-30 之前入库的正文没剥 `<script>`，人民网文化频道的
`<p>` 里带 `showPlayer({...})` 播放器初始化代码，被原样存成了正文
（实测 id=86）。这些 URL 已 `db.seen()`，重跑抓取不会覆盖，只能就地修。

判据（同时满足才更新）：
  1. 旧正文有问题：命中 JS 特征（showPlayer / function( / window. / var 等），
     或任一行是版式残留（「2025年03月07日14:51 来源：人民网」这类日期来源行），
     或残留人民网页脚（版权行 / 许可证号 / 举报电话 / 社概况链接堆）；
  2. `raw_path` 存档文件还在；
  3. 新正文不再命中上述任一问题，且汉字数 >= MIN_BODY_CJK。

用法：
    ./venv/bin/python deploy/repair_body_from_raw.py            # dry-run
    ./venv/bin/python deploy/repair_body_from_raw.py --apply    # 写库（跑前先备份 crawl.db）

配套核对工具：deploy/diff_body.py <id>（只读，逐段对比旧/新正文，
用来确认减少的是版式噪声而不是真内容）。
"""
import re
import sqlite3
import sys
from pathlib import Path

sys.path.insert(0, "/opt/shizheng")
from src.config import MIN_BODY_CJK  # noqa: E402
from src.core import htmltext  # noqa: E402

DB = Path("/opt/shizheng/data/crawl.db")
RE_JS = re.compile(r"showPlayer|function\s*\(|window\.|document\.|var\s+\w+\s*=|jQuery|\$\(")


def problems(body: str) -> list:
    """旧正文的问题清单：JS 污染 / 任一行是日期来源行等版式残留 / 页脚残留。

    逐行扫描而非只看首行——实测 id=172 的日期来源行夹在正文中间
    （「…演讲时间：2026年7月\\n2026年09月26日08:17 来源：光明日报222\\n…」），
    只看首行会漏掉。版式行与页脚行的判定都复用 `htmltext`（与抓取时同一套规则），
    避免两处判据漂移。
    """
    out = []
    if not body:
        return out
    if RE_JS.search(body):
        out.append("JS")
    for line in body.split("\n"):
        s = line.strip()
        if htmltext.is_boilerplate(s):
            out.append("版式残留")
            break
    if htmltext.is_footer(body):
        out.append("页脚残留")
    return out


def main() -> int:
    apply = "--apply" in sys.argv
    con = sqlite3.connect(str(DB), timeout=30)
    rows = con.execute(
        "SELECT id, title, body, raw_path FROM articles").fetchall()

    fixes, skipped = [], []
    for aid, title, body, raw_path in rows:
        bad = problems(body or "")
        if not bad:
            continue
        p = Path(raw_path or "")
        if not p.is_file():
            skipped.append((aid, title, "存档 HTML 不存在"))
            continue
        data = p.read_bytes()
        html = htmltext.decode_html(data, htmltext.declared_charset(None, data))
        new = htmltext.extract_body(html)
        left = problems(new)
        if left:
            skipped.append((aid, title, f"重提后仍有问题：{'/'.join(left)}"))
            continue
        if htmltext.cjk_count(new) < MIN_BODY_CJK:
            skipped.append((aid, title, f"重提后仅 {htmltext.cjk_count(new)} 个汉字"))
            continue
        fixes.append((aid, title, body, new, "/".join(bad)))

    print(f"扫描 {len(rows)} 篇；正文有问题 {len(fixes) + len(skipped)} 篇；"
          f"可修 {len(fixes)} 篇；跳过 {len(skipped)} 篇")
    for aid, title, old, new, bad in fixes:
        print(f"  id={aid}[{bad}] {title[:40]}\n    旧 {len(old)} 字 -> 新 {len(new)} 字 / "
              f"{htmltext.cjk_count(new)} 汉字")
        print(f"    新正文开头：{new[:80]}")
    for aid, title, why in skipped:
        print(f"  [SKIP] id={aid} {title[:36]} —— {why}")

    if not apply:
        print("\nDRY-RUN（未写库）。确认后加 --apply 执行。")
        return 0
    if not fixes:
        print("\n无可修项。")
        return 0

    con.execute("BEGIN IMMEDIATE")
    for aid, _t, _old, new, _bad in fixes:
        con.execute("UPDATE articles SET body=? WHERE id=?", (new, aid))
    con.commit()
    con.close()
    print(f"\nAPPLIED：已重提 {len(fixes)} 篇正文。")
    return 0


if __name__ == "__main__":
    sys.exit(main())
