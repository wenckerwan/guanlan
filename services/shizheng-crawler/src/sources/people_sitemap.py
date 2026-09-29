# -*- coding: utf-8 -*-
"""人民网 sitemap 增量轨道。

优势：只请求 1 次 sitemap，拿到当天全部新 URL，不必爬首页。
注意：sitemap 不覆盖 paper.people.com.cn，因此 rmrb 轨道不可省。
"""
import re, logging, xml.etree.ElementTree as ET
from datetime import date
from pathlib import Path
from ..config import SITEMAP_INDEX, SITEMAP_KEEP, RAW_DIR
from ..core import db, fetcher

log = logging.getLogger("people")

NS = {"sm": "http://www.sitemaps.org/schemas/sitemap/0.9"}
RE_OZOOM = re.compile(r'<div[^>]*id="ozoom"[^>]*>(.*?)</div>\s*</div>', re.S)
RE_ART   = re.compile(r'<div[^>]*class="rm_txt_con[^"]*"[^>]*>(.*?)</div>', re.S)
RE_P     = re.compile(r"<p[^>]*>(.*?)</p>", re.S)
RE_TAG   = re.compile(r"<[^>]+>")
RE_WS    = re.compile(r"\s+")
RE_TITLE = re.compile(r"<title>(.*?)</title>", re.S)


def _clean(s: str) -> str:
    s = RE_TAG.sub("", s).replace("&nbsp;", " ").replace("&amp;", "&")
    return RE_WS.sub(" ", s).strip()


def list_sitemaps() -> list:
    """返回 [(url, channel)]，按 SITEMAP_KEEP 过滤"""
    status, xml = fetcher.get(SITEMAP_INDEX)
    if status != 200:
        log.error("sitemap_index 获取失败")
        return []
    out = []
    for m in re.finditer(r"<loc>(.*?)</loc>", xml):
        u = m.group(1).strip()
        for ch in SITEMAP_KEEP:
            if f"/cn/{ch}/" in u:
                out.append((u, ch))
                break
    return out


def urls_since(sitemap_url: str, since: date) -> list:
    """取子图中 lastmod >= since 的 URL"""
    status, xml = fetcher.get(sitemap_url)
    if status != 200:
        return []
    out = []
    for m in re.finditer(r"<url>(.*?)</url>", xml, re.S):
        blk = m.group(1)
        loc = re.search(r"<loc>(.*?)</loc>", blk)
        mod = re.search(r"<lastmod>(.*?)</lastmod>", blk)
        if not loc:
            continue
        if mod:
            try:
                if date.fromisoformat(mod.group(1).strip()[:10]) < since:
                    continue
            except ValueError:
                pass
        out.append(loc.group(1).strip())
    return out


def _extract_body(html: str) -> str:
    m = RE_OZOOM.search(html)
    if not m:
        m = RE_ART.search(html)
    seg = m.group(1) if m else html
    parts = []
    for p in RE_P.findall(seg):
        t = _clean(p)
        if len(t) > 15 and not t.startswith("《人民日报》"):
            parts.append(t)
    return "\n".join(parts)


def crawl(since: date, sess, limit_per_channel: int = 60) -> int:
    added = 0
    for sm_url, channel in list_sitemaps():
        urls = urls_since(sm_url, since)[:limit_per_channel]
        log.info("[%s] %d 条候选", channel, len(urls))
        for u in urls:
            if db.seen(u):
                continue
            status, html = fetcher.get(u, sess)
            if status != 200 or not html:
                continue
            body = _extract_body(html)
            if len(body) < 60:
                continue
            tm = RE_TITLE.search(html)
            title = _clean(tm.group(1)) if tm else u
            raw = RAW_DIR / f"people_{abs(hash(u)) % 10**12}.html"
            raw.write_text(html, encoding="utf-8")
            if db.save_article(url=u, source="people", title=title,
                               publish_date=since.isoformat(), body=body,
                               raw_path=str(raw), channel=channel):
                added += 1
                log.info("  + [%s] %s", channel, title[:46])
    return added
