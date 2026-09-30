# -*- coding: utf-8 -*-
"""部署后只读探针：验证限速口径、配置项、sitemap 去重与 pipeline 依赖可导入。"""
import logging
import sys

sys.path.insert(0, "/opt/shizheng")
logging.basicConfig(level=logging.INFO, format="%(levelname)s %(message)s")

from src import config
from src.core import fetcher, db  # noqa: F401  (db 导入 = pipeline 依赖检查)

print("LIMIT_PER_CHANNEL =", config.LIMIT_PER_CHANNEL)
print("DEFAULT_DELAY     =", config.DEFAULT_DELAY)
print("ROBOTS_TIMEOUT    =", config.ROBOTS_TIMEOUT)

probe = [
    "http://www.people.cn/sitemap_index.xml",
    "http://finance.people.com.cn/n1/2026/x.html",
    "http://culture.people.com.cn/n1/2026/x.html",
    "http://theory.people.com.cn/n1/2026/x.html",
    "http://paper.people.com.cn/rmrb/pc/x.html",
]
print("--- 每主机限速口径 ---")
for u in probe:
    host = u.split("/")[2]
    print(f"{host:26s} -> {fetcher._delay_for(u):.0f}s")

print("--- sitemap 频道（去重后）---")
from src.sources import people_sitemap
maps = people_sitemap.list_sitemaps()
print("条数 =", len(maps), "频道 =", [ch for _, ch in maps])
assert len(maps) == len({u for u, _ in maps}), "sitemap 仍有重复！"
print("PROBE_OK")
