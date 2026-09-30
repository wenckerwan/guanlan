# -*- coding: utf-8 -*-
"""删除「正文全是页脚/版式残留、无任何真内容」的垃圾行。默认 dry-run。

来由（2026-10-01 实测）：edu.people.com.cn 的「每日一闻 / 每日一句」这类条目
抓回来的页面里没有可用 `<p>`，旧代码把整块页脚（社概况链接堆 + 许可证号 + 版权行）
当正文存了下来，共 521 字 / 211 汉字——`len(body) < 60` 与 `MIN_BODY_CJK=30`
两道闸都拦不住它（页脚本身汉字很多），只有 `htmltext.is_footer()` 能识别。

判据（三条同时满足才删）：
  1. 旧正文每一行都命中 is_footer / is_boilerplate（即没有任何真内容行）；
  2. 用存档 HTML 按现行规则重提，得到 0 个汉字（确认不是提取器的问题）；
  3. `raw_path` 存档存在（保证判据 2 可信）。

删除前把整行导出到 `data/deleted_articles_<时间戳>.json`，可据此还原。

用法：
    ./venv/bin/python deploy/delete_junk_articles.py            # dry-run
    ./venv/bin/python deploy/delete_junk_articles.py --apply    # 导出 + 删除
"""
import json
import sqlite3
import sys
from datetime import datetime
from pathlib import Path

sys.path.insert(0, "/opt/shizheng")
from src.core import htmltext  # noqa: E402

DB = Path("/opt/shizheng/data/crawl.db")
OUT = Path("/opt/shizheng/data")


def all_lines_junk(body: str) -> bool:
    lines = [ln.strip() for ln in (body or "").split("\n") if ln.strip()]
    if not lines:
        return False
    return all(htmltext.is_footer(ln) or htmltext.is_boilerplate(ln) for ln in lines)


def main() -> int:
    apply = "--apply" in sys.argv
    con = sqlite3.connect(str(DB), timeout=30)
    con.row_factory = sqlite3.Row
    rows = con.execute(
        "SELECT id, title, url, channel, source, publish_date, body, raw_path, refined"
        " FROM articles").fetchall()

    hits = []
    for r in rows:
        if not all_lines_junk(r["body"]):
            continue
        p = Path(r["raw_path"] or "")
        if not p.is_file():
            continue
        data = p.read_bytes()
        new = htmltext.extract_body(
            htmltext.decode_html(data, htmltext.declared_charset(None, data)))
        if htmltext.cjk_count(new) > 0:
            continue
        hits.append(dict(r))

    print(f"扫描 {len(rows)} 篇；正文全为页脚/版式残留且重提无内容 {len(hits)} 篇")
    for h in hits:
        print(f"  id={h['id']} [{h['channel']}] {h['title'][:40]}")
        print(f"    {h['url']}")
        print(f"    旧正文 {len(h['body'] or '')} 字 / {htmltext.cjk_count(h['body'] or '')} 汉字，"
              f"全部 {len([x for x in (h['body'] or '').splitlines() if x.strip()])} 行均为噪声")

    if not apply:
        print("\nDRY-RUN（未写库）。确认后加 --apply 执行。")
        return 0
    if not hits:
        print("\n无可删项。")
        return 0

    ts = datetime.now().strftime("%Y%m%d-%H%M%S")
    backup = OUT / f"deleted_articles_{ts}.json"
    backup.write_text(json.dumps(hits, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"\n已导出待删行到 {backup}")

    con.execute("BEGIN IMMEDIATE")
    ids = [h["id"] for h in hits]
    qmarks = ",".join("?" * len(ids))
    cur = con.execute(f"DELETE FROM articles WHERE id IN ({qmarks})", ids)
    con.commit()
    print(f"APPLIED：已删除 {cur.rowcount} 行。剩余 "
          f"{con.execute('SELECT COUNT(*) FROM articles').fetchone()[0]} 篇。")
    con.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
