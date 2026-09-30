# -*- coding: utf-8 -*-
"""限速口径与 sitemap 去重的离线测试（不触网）。"""
import sys
import unittest
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from src.core import fetcher
from src.sources import people_sitemap


ROBOTS_WITH_DELAY = """User-agent: *
Disallow:
Crawl-delay: 120
Sitemap: http://www.people.cn/sitemap_index.xml
"""

ROBOTS_NO_DELAY = """User-agent: * 
Disallow: /2015m2/
Disallow: /n/2015m2/"""

ROBOTS_OTHER_AGENT = """User-agent: Baiduspider
Crawl-delay: 5

User-agent: *
Disallow: /private/
"""

ROBOTS_INLINE_COMMENT = """User-agent: *   # 所有爬虫
Crawl-delay: 30   # 半分钟
"""


class ParseCrawlDelayTest(unittest.TestCase):
    def test_declared(self):
        self.assertEqual(fetcher._parse_crawl_delay(ROBOTS_WITH_DELAY), 120.0)

    def test_absent(self):
        self.assertIsNone(fetcher._parse_crawl_delay(ROBOTS_NO_DELAY))

    def test_only_other_agent(self):
        """只给别的 UA 声明的 Crawl-delay 不应套到 * 上。"""
        self.assertIsNone(fetcher._parse_crawl_delay(ROBOTS_OTHER_AGENT))

    def test_inline_comment_and_space(self):
        self.assertEqual(fetcher._parse_crawl_delay(ROBOTS_INLINE_COMMENT), 30.0)


class DelayForTest(unittest.TestCase):
    def setUp(self):
        fetcher._robots_delay.clear()

    def test_declared_host_honors_robots(self):
        with mock.patch.object(fetcher, "_robots_crawl_delay", return_value=120.0):
            self.assertEqual(
                fetcher._delay_for("http://www.people.cn/sitemap_index.xml"), 120.0)

    def test_undeclared_host_uses_default(self):
        with mock.patch.object(fetcher, "_robots_crawl_delay", return_value=None):
            self.assertEqual(
                fetcher._delay_for("http://finance.people.com.cn/n1/2026/x.html"),
                fetcher.DEFAULT_DELAY)

    def test_paper_host_fixed(self):
        """人民日报电子版不查 robots，固定 DELAY_RMRB。"""
        with mock.patch.object(fetcher, "_robots_crawl_delay") as m:
            self.assertEqual(
                fetcher._delay_for("http://paper.people.com.cn/rmrb/pc/x.html"),
                fetcher.DELAY_RMRB)
            m.assert_not_called()

    def test_robots_cached_per_host(self):
        """同一主机的 robots 只读一次。"""
        calls = []

        def fake_get(url, **kwargs):
            calls.append(url)
            return mock.Mock(status_code=200, text=ROBOTS_WITH_DELAY,
                             apparent_encoding="utf-8")

        with mock.patch.object(fetcher.requests.Session, "get", side_effect=fake_get):
            sess = fetcher.requests.Session()
            fetcher._robots_crawl_delay("finance.people.com.cn", sess)
            fetcher._robots_crawl_delay("finance.people.com.cn", sess)
        self.assertEqual(len(calls), 1)
        self.assertEqual(fetcher._robots_delay["finance.people.com.cn"], 120.0)


class ListSitemapsDedupeTest(unittest.TestCase):
    def test_duplicate_channel_listed_once(self):
        """sitemap_index 把 legal 列两次，去重后只能出现一次。"""
        index = "".join(
            f"<sitemap><loc>http://www.people.cn/sitemap/cn/{ch}/news_sitemap.xml</loc></sitemap>"
            for ch in ["politics", "world", "legal", "legal", "culture", "sports"]
        )
        xml = f'<?xml version="1.0"?><sitemapIndex>{index}</sitemapIndex>'
        with mock.patch.object(people_sitemap.fetcher, "get", return_value=(200, xml)):
            out = people_sitemap.list_sitemaps()
        channels = [ch for _, ch in out]
        self.assertEqual(channels, ["politics", "world", "legal", "culture"])
        self.assertEqual(len(out), len({u for u, _ in out}))

    def test_keep_filter_excludes_unwanted(self):
        xml = ('<sitemapIndex><sitemap><loc>http://www.people.cn/sitemap/cn/sports/'
               'news_sitemap.xml</loc></sitemap></sitemapIndex>')
        with mock.patch.object(people_sitemap.fetcher, "get", return_value=(200, xml)):
            self.assertEqual(people_sitemap.list_sitemaps(), [])


if __name__ == "__main__":
    unittest.main(verbosity=2)
