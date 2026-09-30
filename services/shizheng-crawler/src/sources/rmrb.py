# -*- coding: utf-8 -*-
"""人民日报电子版抓取。

关键事实（实测）：
  * 版面列表：/rmrb/pc/layout/{YYYYMM}/{DD}/node_{NN}.html   NN 从 01 开始
  * 正文页面：/rmrb/pc/content/{YYYYMM}/{DD}/{cid}.html      ← 日期必须带斜杠！
  * 正文容器：<div id="ozoom">
  * 实际版面数每天不同（实测 9/27 为 8 版），需探测，不能写死

重要教训：只抓头版会漏重要素材。
  实测 2026-09-27 第02版有《中美达成八点成果共识》，头版没有。
  因此默认抓取全部版面。

编码教训（2026-09-30）：版面页文字稀疏，`apparent_encoding` 会把它猜成
  MacCyrillic，导致标题变成 `еЕіиЊєеѓМж∞С...` 且「含"版"且含"："」的导航过滤
  规则失效。现统一由 core.htmltext.decode_html 按 HTTP 头/meta 声明解码，标题再过
  一遍 fix_mojibake 兜底。
"""
import re, logging
from datetime import date, timedelta
from pathlib import Path
from ..config import RMRB_LAYOUT, RMRB_CONTENT, RMRB_MAX_PAGE, RAW_DIR, MIN_BODY_CJK
from ..core import db, fetcher, htmltext

log = logging.getLogger("rmrb")

RE_ARTICLE_LINK = re.compile(
    r'<a[^>]+href="([^"]*content_(\d+)\.html)"[^>]*>(.*?)</a>', re.S)
RE_EDITION = re.compile(r"第\s*(\d+)\s*版")


def _clean(s: str) -> str:
    # 标题也过一遍 mojibake 自愈：版面页文字少，chardet 曾把 UTF-8 猜成 MacCyrillic
    return htmltext.fix_mojibake(htmltext.clean_text(s))


def detect_pages(yyyymm: str, dd: str, sess) -> int:
    """探测当日实际版面数（因为每天不同）"""
    n = 0
    for i in range(1, RMRB_MAX_PAGE + 1):
        url = RMRB_LAYOUT.format(yyyymm=yyyymm, dd=dd, page=i)
        status, _ = fetcher.get(url, sess)
        if status == 200:
            n = i
        elif status == 404:
            break          # 版面连续不存在即停止，不再往后探
    return n


def list_edition(yyyymm: str, dd: str, page: int, sess):
    """返回某一版的稿件列表 [(url, cid, title)]"""
    url = RMRB_LAYOUT.format(yyyymm=yyyymm, dd=dd, page=page)
    status, html = fetcher.get(url, sess)
    if status != 200 or not html:
        return None, []
    base = f"http://paper.people.com.cn/rmrb/pc/layout/{yyyymm}/{dd}/"
    out, seen_cid = [], set()
    for href, cid, raw_title in RE_ARTICLE_LINK.findall(html):
        if cid in seen_cid:
            continue
        title = _clean(raw_title)
        # 过滤版面导航行（"第01版：要闻"、"PDF下载"）
        if "版" in title and ("PDF" in title or "：" in title):
            continue
        # 过滤版权行（"本版责编：史一棋"）。它本身是个链接，正文页只有链接堆，
        # 2026-09-29 曾入库 2 条（id 40/68）。当时因标题是乱码，上面的
        # 「含"版"且含"："」规则匹配不上才漏掉，编码修好后这里再兜一层。
        if title.startswith("本版责编") or "责编：" in title:
            continue
        if not title or len(title) < 5:
            continue
        full = href
        if not full.startswith("http"):
            # 头版用 ../../../content/... 的相对路径
            full = RMRB_CONTENT.format(yyyymm=yyyymm, dd=dd, cid=f"content_{cid}.html")
        seen_cid.add(cid)
        out.append((full, cid, title))
    return html, out


def fetch_body(cid: str, yyyymm: str, dd: str, sess):
    """抓正文，返回 (纯文本, 正文页原始HTML)"""
    url = RMRB_CONTENT.format(yyyymm=yyyymm, dd=dd, cid=f"content_{cid}.html")
    status, html = fetcher.get(url, sess)
    if status != 200 or not html:
        return "", ""
    # 统一走 htmltext：ozoom 容器配平匹配 + 剥 script/style + 过滤图注/页脚
    return htmltext.extract_body(html), html


def crawl_day(d: date, sess, pages: int = None) -> int:
    """抓取某一天全部版面的稿件。返回新增篇数。"""
    yyyymm = d.strftime("%Y%m")
    dd     = d.strftime("%d")
    pub    = d.strftime("%Y-%m-%d")

    if pages is None:
        pages = detect_pages(yyyymm, dd, sess)
        if pages == 0:
            log.warning("%s 无任何版面（可能未出报）", pub)
            return 0
    log.info("%s 共 %d 个版面", pub, pages)

    added = 0
    for page in range(1, pages + 1):
        html, items = list_edition(yyyymm, dd, page, sess)
        if items is None:
            continue
        log.info("  第%02d版：%d 篇", page, len(items))
        for url, cid, title in items:
            if db.seen(url):
                continue
            body, art_html = fetch_body(cid, yyyymm, dd, sess)
            n_cjk = htmltext.cjk_count(body)
            if n_cjk < MIN_BODY_CJK:
                log.warning("    正文仅 %d 个汉字（<%d），判为无效稿，跳过：%s",
                            n_cjk, MIN_BODY_CJK, title[:40])
                continue
            raw = RAW_DIR / f"rmrb_{yyyymm}{dd}_{cid}.html"
            if art_html:
                raw.write_text(art_html, encoding="utf-8")
            ok = db.save_article(
                url=url, source="rmrb", title=title, publish_date=pub,
                body=body, raw_path=str(raw), content_id=cid,
                channel=f"第{page:02d}版",
            )
            if ok:
                added += 1
                log.info("    + %s", title[:50])
    return added


def crawl_range(start: date, end: date, sess, pages: int = None) -> int:
    total, d = 0, start
    while d <= end:
        try:
            total += crawl_day(d, sess, pages)
        except Exception as e:
            log.error("%s 抓取失败：%s", d, e)
        d += timedelta(days=1)
    return total
