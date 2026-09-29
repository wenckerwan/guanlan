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
"""
import re, logging
from datetime import date, timedelta
from pathlib import Path
from ..config import RMRB_LAYOUT, RMRB_CONTENT, RMRB_MAX_PAGE, RAW_DIR
from ..core import db, fetcher

log = logging.getLogger("rmrb")

RE_ARTICLE_LINK = re.compile(
    r'<a[^>]+href="([^"]*content_(\d+)\.html)"[^>]*>(.*?)</a>', re.S)
RE_OZOOM = re.compile(r'<div[^>]*id="ozoom"[^>]*>(.*?)</div>\s*</div>', re.S)
RE_P = re.compile(r"<p[^>]*>(.*?)</p>", re.S)
RE_TAG = re.compile(r"<[^>]+>")
RE_WS = re.compile(r"\s+")
RE_EDITION = re.compile(r"第\s*(\d+)\s*版")


def _clean(s: str) -> str:
    s = RE_TAG.sub("", s)
    s = s.replace("&nbsp;", " ").replace("&amp;", "&")
    s = RE_WS.sub(" ", s)
    return s.strip()


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
    m = RE_OZOOM.search(html)
    seg = m.group(1) if m else html
    parts = []
    for p in RE_P.findall(seg):
        t = _clean(p)
        # 跳过图注（"这是习近平…合影"这类重复的图片说明）与页脚
        if len(t) < 15:
            continue
        if t.startswith("《人民日报》") or t.startswith("人民日报 (20"):
            continue
        if re.match(r"^\d+$", t):
            continue
        parts.append(t)
    return "\n".join(parts), html


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
            if not body:
                log.warning("    正文为空，跳过：%s", title[:40])
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
