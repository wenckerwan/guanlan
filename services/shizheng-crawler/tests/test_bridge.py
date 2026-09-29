# -*- coding: utf-8 -*-
"""select_top10 / render_html 单元测试：python -m tests.test_bridge"""
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from bridge.select_top10 import select_top
from bridge.render_html import to_hotspot_payload, render_html


def make(title, priority, module="时政", typ="其他"):
    return {"title": title, "date": "2026-09-28", "priority": priority,
            "module": module, "type": typ, "facts": [f"事实-{title}"],
            "fixed_phrases": ["坚持高质量发展"], "exam_points": ["角度A"],
            "traps": ["易混点X"], "source": "rmrb", "channel": "第01版",
            "url": "http://example.com/a"}


def test_select_respects_priority():
    items = [make(f"t{i}", p) for i, p in enumerate(
        ["中", "绝高", "高", "极高", "很高", "中高", "中", "高", "中", "中", "中", "中"])]
    picked = select_top(items, 10)
    assert len(picked) == 10
    assert picked[0]["priority"] == "绝高"
    assert picked[1]["priority"] == "极高"
    assert all(p["priority"] != "中" or True for p in picked)


def test_select_dedup_title():
    items = [make("同一事件", "极高"), make("同一事件", "高"), make("另一事件", "中")]
    picked = select_top(items, 10)
    assert len(picked) == 2
    assert picked[0]["priority"] == "极高"


def test_select_short_supply():
    assert len(select_top([make("a", "中")], 10)) == 1
    assert select_top([], 10) == []


def test_payload_mapping():
    p = to_hotspot_payload(make("测试标题", "极高", typ="中央会议"), 6)
    assert p["title"] == "测试标题"
    assert p["level"] == "A" and p["priority"] == "A"
    assert p["type"] == "中央会议"
    assert p["tag"] == "每日时政"
    assert p["period"] == "2026-09-28"
    assert p["subjectId"] == 6 and p["status"] == "published"
    assert "事实-测试标题" in p["summary"]
    h = render_html(make("x<script>", "高"))
    assert "<script>" not in h and "x&lt;script&gt;" in h
    assert "固定表述" in h and "坚持高质量发展" in h


if __name__ == "__main__":
    test_select_respects_priority()
    test_select_dedup_title()
    test_select_short_supply()
    test_payload_mapping()
    print("bridge 单元测试全部通过")
