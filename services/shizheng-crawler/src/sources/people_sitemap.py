# -*- coding: utf-8 -*-
"""人民网 sitemap 增量轨道。

优势：只请求 1 次 sitemap，拿到当天全部新 URL，不必爬首页。
注意：sitemap 不覆盖 paper.people.com.cn，因此 rmrb 轨道不可省。

2026-09-30 的两个实测教训（都已修）：
  1. 旧的 `<div class="rm_txt_con[^"]*"[^>]*>(.*?)</div>` 是**非贪婪**匹配，遇到正文里
     嵌套的 `<div class="bza">` 就在第一个 `</div>` 处截断，正文变 0 字被静默丢弃 ——
     politics / world / society 三个频道整轮 0 篇入库。现改用 core.htmltext 的
     `<div>` 配平扫描。
  2. `<p>` 里可能嵌 `<script>showPlayer({...})</script>`，不剥 script 会把 JS 存成正文。
"""
import re, time, logging, xml.etree.ElementTree as ET
from datetime import date
from pathlib import Path
from ..config import (SITEMAP_INDEX, SITEMAP_KEEP, RAW_DIR, LIMIT_PER_CHANNEL,
                      MIN_BODY_CJK)
from ..core import db, fetcher, htmltext

log = logging.getLogger("people")

NS = {"sm": "http://www.sitemaps.org/schemas/sitemap/0.9"}
RE_TITLE = re.compile(r"<title>(.*?)</title>", re.S)
# 站点后缀：人民网 <title> 形如「正文标题--频道--人民网」「正文标题-理论-中国共产党新闻网」。
# 频道段可能是「教育」「经济·科技」「2024年全国两会」等任意词，所以不能写死频道名，
# 只能按「1~2 个短分隔段 + 已知站名 + 行尾」来锚定；段长限 12 字，避免吃掉真标题。
# 分隔符只认半角 `-`/`--`：全角竖线「｜」和「丨」常出现在**真标题内部**
# （实测「原来你是这样的人大代表｜灭火英雄跨界守护文化根脉--2024年全国两会--人民网」），
# 把它们当分隔符会把副标题一起剥掉。
RE_SITE_NAMES = r"(?:人民网|中国共产党新闻网|中共新闻网|人民日报|人民周刊|学习微平台)"
RE_SITE_SUFFIX = re.compile(
    rf"((?:--|-)\s*[^-]{{1,12}}){{1,2}}(?:--|-)\s*{RE_SITE_NAMES}\s*$")


def _clean_title(s: str) -> str:
    """标题去乱码 + 去站点后缀（后缀会一路带到 hotspots 标题里，很难看）。"""
    t = htmltext.fix_mojibake(htmltext.clean_text(s))
    m = RE_SITE_SUFFIX.search(t)
    if not m:
        return t
    short = t[:m.start()].strip(" -—|｜")
    # 剥完还得像个标题（>=4 字）；否则说明整条基本就是站名类噪声，保持原样
    # （实测「如何读《孟子》--理论-中国共产党新闻网」剥完 7 字，属正常短标题）
    return short if len(short) >= 4 else t


def list_sitemaps() -> list:
    """返回 [(url, channel)]，按 SITEMAP_KEEP 过滤并**去重**。

    sitemap_index 实测会把同一频道列多次（如 legal 出现两次），
    不去重就会把整个频道白跑一遍（旧限速口径下约 2 小时）。
    """
    status, xml = fetcher.get(SITEMAP_INDEX)
    if status != 200:
        log.error("sitemap_index 获取失败")
        return []
    out, seen = [], set()
    for m in re.finditer(r"<loc>(.*?)</loc>", xml):
        u = m.group(1).strip()
        if u in seen:
            continue
        for ch in SITEMAP_KEEP:
            if f"/cn/{ch}/" in u:
                seen.add(u)
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
    """正文提取：容器 `<div>` 配平匹配 + 先剥 script/style。

    旧实现 `(.*?)</div>` 非贪婪会在第一个嵌套 div（`<div class="bza">`）处截断，
    导致 politics/world/society 整频道 0 篇入库（静默丢数据）。
    """
    return htmltext.extract_body(html)


def crawl(since: date, sess, limit_per_channel: int = None) -> int:
    """抓取 sitemap 中当天新增文章。

    limit_per_channel 缺省读 config.LIMIT_PER_CHANNEL；
    跨频道重复的 URL 只抓一次（seen_urls），避免同一篇稿子被多次请求。
    """
    limit = LIMIT_PER_CHANNEL if limit_per_channel is None else limit_per_channel
    added = 0
    seen_urls = set()
    for sm_url, channel in list_sitemaps():
        t0 = time.time()
        urls = urls_since(sm_url, since)[:limit]
        # 去掉本轮已抓过的（跨频道转载/同稿多频道）
        todo = [u for u in urls if u not in seen_urls]
        seen_urls.update(todo)
        log.info("[%s] %d 条候选（本轮待抓 %d）", channel, len(urls), len(todo))
        ch_added = 0
        for u in todo:
            if db.seen(u):
                continue
            status, html = fetcher.get(u, sess)
            if status != 200 or not html:
                continue
            body = _extract_body(html)
            n_cjk = htmltext.cjk_count(body)
            if len(body) < 60 or n_cjk < MIN_BODY_CJK:
                log.info("  - [%s] 正文过短（%d 字 / %d 汉字），跳过", channel, len(body), n_cjk)
                continue
            tm = RE_TITLE.search(html)
            title = _clean_title(tm.group(1)) if tm else u
            raw = RAW_DIR / f"people_{abs(hash(u)) % 10**12}.html"
            raw.write_text(html, encoding="utf-8")
            if db.save_article(url=u, source="people", title=title,
                               publish_date=since.isoformat(), body=body,
                               raw_path=str(raw), channel=channel):
                added += 1
                ch_added += 1
                log.info("  + [%s] %s", channel, title[:46])
        log.info("[%s] 完成：新增 %d 篇，耗时 %.1f 分钟",
                 channel, ch_added, (time.time() - t0) / 60)
    return added
