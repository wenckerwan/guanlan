# -*- coding: utf-8 -*-
"""就地修复已入库的乱码标题（2026-09-30 实测 10 条，id 36-40 / 64-68）。

成因：旧版 fetcher 用 `r.apparent_encoding`（chardet）解码，而人民日报**版面页**
文字稀疏，被猜成 MacCyrillic，于是 UTF-8 字节被按 mac-cyrillic 解出西里尔乱码。
新版 fetcher 已改为「优先 HTTP 头/meta 声明字符集 + 多候选严格解码」，本脚本只负责
清理历史数据。

修复优先级：
  1. `htmltext.fix_mojibake(title)`——按 mac-cyrillic/cp1251/cp1252/latin-1 回编码再
     按 UTF-8 解，要求结果含中文且乱码特征消失才采纳；
  2. 若 1 修不动（字节曾被 errors='replace' 丢掉）且存档 HTML 还在，则用新解码器
     重读 `raw_path`，从 `<title>`/`<h1>` 里取干净标题。

用法：
    ./venv/bin/python deploy/repair_mojibake.py            # dry-run，只打印
    ./venv/bin/python deploy/repair_mojibake.py --apply    # 实际写库
"""
import re
import sqlite3
import sys
from pathlib import Path

sys.path.insert(0, "/opt/shizheng")
from src.core import htmltext  # noqa: E402

DB = Path("/opt/shizheng/data/crawl.db")
RE_TITLE = re.compile(r"<title[^>]*>(.*?)</title>", re.S | re.I)
RE_H1 = re.compile(r"<h1[^>]*>(.*?)</h1>", re.S | re.I)


def title_from_raw(raw_path: str) -> str:
    """用新解码器重读存档 HTML，取 <h1> 或 <title> 作为标题。"""
    p = Path(raw_path or "")
    if not p.is_file():
        return ""
    data = p.read_bytes()
    text = htmltext.decode_html(data, htmltext.declared_charset(None, data))
    for pat in (RE_H1, RE_TITLE):
        m = pat.search(text)
        if m:
            t = htmltext.clean_text(m.group(1))
            # 去掉站名后缀：「xxx--时政--人民网」/「xxx_人民日报」
            t = re.split(r"(--|_|\|)\s*(时政|人民网|人民日报|理论|中国共产党新闻网)", t)[0]
            if len(t) >= 5:
                return t.strip()
    return ""


def main() -> int:
    apply = "--apply" in sys.argv
    con = sqlite3.connect(str(DB))
    rows = con.execute("SELECT id, title, body, raw_path, source FROM articles").fetchall()

    fixed, body_bad, unrepaired = [], [], []
    for aid, title, body, raw_path, source in rows:
        new = htmltext.fix_mojibake(title or "")
        if new == title:
            if htmltext.RE_MOJIBAKE.search(title or ""):
                alt = title_from_raw(raw_path)
                if alt:
                    fixed.append((aid, title, alt, "raw"))
                else:
                    unrepaired.append((aid, title))
            if htmltext.RE_MOJIBAKE.search(body or "") and not htmltext.RE_CJK.search(body or ""):
                body_bad.append((aid, source, len(body or "")))
            continue
        if htmltext.RE_CJK.search(new):
            fixed.append((aid, title, new, "codec"))
        else:
            unrepaired.append((aid, title))

    print(f"扫描 {len(rows)} 篇；待修标题 {len(fixed)} 条；无法修复 {len(unrepaired)} 条；"
          f"正文疑似乱码 {len(body_bad)} 篇")
    for aid, old, new, how in fixed:
        print(f"  [{how}] id={aid}\n    - {old}\n    + {new}")
    for aid, old in unrepaired:
        print(f"  [FAIL] id={aid} {old!r}")
    for aid, source, n in body_bad:
        print(f"  [BODY] id={aid} {source} 正文 {n} 字仍含乱码特征")

    if not apply:
        print("\nDRY-RUN（未写库）。确认后加 --apply 执行。")
        return 0
    if not fixed:
        print("\n无可修复项，未写库。")
        return 0

    con.execute("BEGIN")
    for aid, _old, new, _how in fixed:
        con.execute("UPDATE articles SET title=? WHERE id=?", (new, aid))
    con.commit()
    print(f"\nAPPLIED：已更新 {len(fixed)} 条标题。")
    con.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
