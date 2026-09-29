# -*- coding: utf-8 -*-
"""HTTP 抓取：串行 + 限速 + 重试 + 退避。绝不并发。"""
import time, random, logging, requests
from urllib.parse import urlparse
from ..config import (HEADERS, TIMEOUT, RETRY, RETRY_BACKOFF,
                      DELAY_PEOPLE, DELAY_RMRB, JITTER)

log = logging.getLogger("fetcher")

_last_hit = {}   # host -> 上次请求时间戳


def _delay_for(url: str) -> float:
    """按 host 返回最小间隔。people.com.cn 遵守 robots.txt 的 120 秒。"""
    host = urlparse(url).netloc.lower()
    if host.endswith("paper.people.com.cn"):
        return DELAY_RMRB
    if host.endswith("people.com.cn") or host.endswith("people.cn"):
        return DELAY_PEOPLE
    return DELAY_PEOPLE   # 未知域名按最保守处理


def _respect_rate_limit(url: str):
    host = urlparse(url).netloc.lower()
    wait = _delay_for(url)
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
        _respect_rate_limit(url)
        try:
            r = sess.get(url, headers=HEADERS, timeout=TIMEOUT)
            if r.status_code == 200:
                # 关键：必须用 apparent_encoding，页面声明的 utf-8 会乱码
                r.encoding = r.apparent_encoding or "utf-8"
                return 200, r.text
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
