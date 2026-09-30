# -*- coding: utf-8 -*-
"""真实抓取冒烟：验证完整限速+重试路径可用，且同主机请求起始间隔 >= DEFAULT_DELAY。

限速语义说明：Crawl-delay 按「两次请求的发起时刻」计，_last_hit 记录的是发起时刻，
因此实际间隔 >= 12s，而不是「上次响应结束到下次发起 >= 12s」。
"""
import logging
import sys

sys.path.insert(0, "/opt/shizheng")
logging.basicConfig(level=logging.INFO, format="%(levelname)s %(message)s")

import requests
from src import config
from src.core import fetcher

HOST = "politics.people.com.cn"
URLS = [
    "http://politics.people.com.cn/n1/2026/0930/c461001-40808449.html",
    "http://politics.people.com.cn/n1/2026/0930/c461001-40808449.html",  # 同一篇抓两次，确保 200
]

sess = requests.Session()
sess.headers.update({"Connection": "keep-alive"})

status, html = fetcher.get(URLS[0], sess)
print(f"#1 status={status} 长度={len(html or '')}")
assert status == 200 and html, "首篇抓取失败"
start1 = fetcher._last_hit[HOST]

status2, html2 = fetcher.get(URLS[1], sess)
start2 = fetcher._last_hit[HOST]
gap = start2 - start1
print(f"#2 status={status2} 长度={len(html2 or '')}")
print(f"两次请求发起间隔={gap:.1f}s（DEFAULT_DELAY={fetcher.DEFAULT_DELAY}s + 抖动）")
assert gap >= fetcher.DEFAULT_DELAY, f"限速未生效: {gap:.1f}s"
assert gap < config.DELAY_PEOPLE, f"仍按旧的 120s 口径: {gap:.1f}s"

body = __import__("src.sources.people_sitemap", fromlist=["_extract_body"])._extract_body(html)
print(f"正文提取长度={len(body)}（>60 才会入库）")
assert len(body) > 60, "正文提取异常"
print("SMOKE_OK")
