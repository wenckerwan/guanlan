# -*- coding: utf-8 -*-
"""把一条提炼结果渲染成观澜 hotspots 的字段（html / summary / level 等）。

字段映射（对应后台 saveHotspot 的可填字段）：
  title    原标题（跨天同名时追加日期后缀，数据库 title 有唯一约束）
  level    绝高/极高→A，很高/高→B，中高/中→C
  priority 同 level
  summary  前两条必背事实（512 字以内）
  type     材料类型（元首外交/中央会议/…）
  tag      每日时政
  period   发布日期 YYYY-MM-DD
  html     结构化长文
"""
import html as _html

LEVEL_MAP = {"绝高": "S", "极高": "A", "很高": "A", "高": "B", "中高": "C", "中": "C"}


def _esc(s) -> str:
    return _html.escape(str(s or ""))


def _section(title: str, items: list, ordered: bool = False) -> str:
    items = [i for i in (items or []) if i]
    if not items:
        return ""
    tag = "ol" if ordered else "ul"
    lis = "".join(f"<li>{_esc(i)}</li>" for i in items)
    return f"<h2>{_esc(title)}</h2><{tag}>{lis}</{tag}>"


def render_html(item: dict) -> str:
    parts = [
        f"<p><strong>日期：</strong>{_esc(item.get('date'))}　"
        f"<strong>来源：</strong>{_esc(item.get('source'))} {_esc(item.get('channel'))}　"
        f"<strong>优先级：</strong>{_esc(item.get('priority'))}　"
        f"<strong>主考模块：</strong>{_esc(item.get('module'))}</p>",
        _section("必背事实", item.get("facts"), ordered=True),
        _section("固定表述（逐字准确）", item.get("fixed_phrases")),
        _section("出题点", item.get("exam_points")),
        _section("易混提醒", item.get("traps")),
    ]
    if item.get("url"):
        parts.append(f'<p>原文：<a href="{_esc(item["url"])}" rel="nofollow">{_esc(item["url"])}</a></p>')
    return "".join(p for p in parts if p)


def to_hotspot_payload(item: dict, subject_id: int) -> dict:
    facts = [f for f in (item.get("facts") or []) if f]
    summary = "；".join(facts[:2])[:500]
    level = LEVEL_MAP.get(item.get("priority", "中"), "C")
    return {
        "title": (item.get("title") or "").strip()[:191],
        "level": level,
        "priority": level,
        "summary": summary,
        "type": (item.get("type") or "")[:64],
        "tag": "每日时政",
        "period": (item.get("date") or "")[:64],
        "html": render_html(item),
        "subjectId": subject_id,
        "status": "published",
    }
