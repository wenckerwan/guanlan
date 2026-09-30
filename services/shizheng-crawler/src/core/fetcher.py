# -*- coding: utf-8 -*-
"""HTTP 抓取：串行 + 限速 + 重试 + 退避。绝不并发。

限速口径（V0.1-dev.26 起）：
  * Crawl-delay 是 robots.txt 的**按主机**指令，只在声明它的主机上生效。
    实测：www.people.com.cn / www.people.cn 声明 120；paper.people.com.cn、
    finance/world/politics/legal/theory/opinion/env.people.com.cn 的 robots.txt
    为 404，culture/society.people.com.cn 有 robots 但未声明 Crawl-delay。
  * 因此这里按主机懒加载 robots.txt 并缓存：声明了就严格遵守（含 120 秒），
    没声明的用 DEFAULT_DELAY 保守值 + 抖动，不再一刀切 120 秒。
  * 重试不再重走完整限速等待（一个失效 URL 最多浪费 3×delay），只用退避。
"""
import time, random, logging, requests
from urllib.parse import urlparse
from ..config import (HEADERS, TIMEOUT, RETRY, RETRY_BACKOFF,
                      DELAY_RMRB, DEFAULT_DELAY, JITTER, ROBOTS_TIMEOUT)
from .htmltext import decode_html, declared_charset

log = logging.getLogger("fetcher")

_last_hit = {}      # host -> 上次请求时间戳
_robots_delay = {}  # host -> Crawl-delay 秒数；None 表示未声明/取不到


def _parse_crawl_delay(text: str):
    """从 robots.txt 文本里取 User-agent: * 适用的 Crawl-delay。取不到返回 None。"""
    applies = False
    for raw in text.splitlines():
        line = raw.split("#", 1)[0].strip()
        if not line or ":" not in line:
            continue
        key, _, value = line.partition(":")
        key = key.strip().lower()
        value = value.strip()
        if key == "user-agent":
            applies = value == "*"
        elif key == "crawl-delay" and applies:
            try:
                delay = float(value)
            except ValueError:
                return None
            return delay if delay > 0 else None
    return None


def _robots_crawl_delay(host: str, session=None) -> None or float:
    """懒加载并缓存某主机的 Crawl-delay（None = 未声明）。"""
    if host in _robots_delay:
        return _robots_delay[host]

    delay = None
    try:
        sess = session or requests
        r = sess.get(f"http://{host}/robots.txt", headers=HEADERS,
                     timeout=ROBOTS_TIMEOUT)
        if r.status_code == 200:
            r.encoding = r.apparent_encoding or "utf-8"
            delay = _parse_crawl_delay(r.text)
    except requests.RequestException as e:
        log.warning("robots.txt 读取失败 %s：%s（按未声明处理）", host, str(e)[:60])

    _robots_delay[host] = delay
    log.info("限速口径 %s -> %s", host,
             f"robots Crawl-delay {delay:.0f}s" if delay else f"默认 {DEFAULT_DELAY:.0f}s")
    return delay


def _delay_for(url: str, session=None) -> float:
    """按 host 返回最小间隔：robots 声明优先，其次域名保守默认。"""
    host = urlparse(url).netloc.lower()

    # 人民日报电子版：无 robots.txt，沿用主动克制的固定值
    if host.endswith("paper.people.com.cn"):
        return DELAY_RMRB

    declared = _robots_crawl_delay(host, session)
    if declared:
        return declared
    # robots 未声明 Crawl-delay（404 或无该指令）时，用保守默认值而非 DELAY_PEOPLE
    return DEFAULT_DELAY


def _respect_rate_limit(url: str, session=None):
    host = urlparse(url).netloc.lower()
    wait = _delay_for(url, session)
    last = _last_hit.get(host)
    if last is not None:
        elapsed = time.time() - last
        need = wait - elapsed
        if need > 0:
            need *= (1 + random.uniform(0, JITTER))   # 抖动
            log.debug("限速等待 %.1fs (%s)", need, host)
            time.sleep(need)
    _last_hit[host] = time.time()


def get(url: str, session: requests.Session = None):
    """返回 (status_code, text) ；失败返回 (None, 错误信息)"""
    sess = session or requests
    for attempt in range(1, RETRY + 1):
        # 只在首次尝试时按主机限速；重试仅退避，避免失效 URL 反复烧满 delay
        if attempt == 1:
            _respect_rate_limit(url, sess)
        try:
            r = sess.get(url, headers=HEADERS, timeout=TIMEOUT)
            if r.status_code == 200:
                # 编码：优先 HTTP 头/meta 声明，其次 chardet 猜测，最后 utf-8/gb18030 兜底；
                # 并对 UTF-8→cp1252 型 mojibake 自愈（旧写法只用 apparent_encoding，
                # 对文字少的版面页会猜错，产生 10/75 篇乱码标题）。
                return 200, decode_html(r.content,
                                        declared_charset(r.headers, r.content),
                                        r.apparent_encoding)
            if r.status_code in (403, 429):
                back = RETRY_BACKOFF * attempt * 3      # 限流类退避更久
                log.warning("HTTP %s 于 %s，退避 %.0fs", r.status_code, url, back)
                time.sleep(back)
                continue
            if r.status_code == 404:
                return 404, ""
            log.warning("HTTP %s 于 %s（第 %d 次）", r.status_code, url, attempt)
        except requests.RequestException as e:
            log.warning("请求异常 %s：%s（第 %d 次）", type(e).__name__, str(e)[:80], attempt)
        if attempt < RETRY:
            time.sleep(RETRY_BACKOFF * attempt)
    return None, "重试耗尽"
