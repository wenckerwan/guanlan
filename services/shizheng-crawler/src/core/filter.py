# -*- coding: utf-8 -*-
"""关键词过滤：初筛，降低 LLM 成本"""
from ..config import KEYWORDS, EXCLUDE

def hit_keywords(title: str, body: str = "") -> list:
    text = (title or "") + " " + (body or "")[:600]
    return [k for k in KEYWORDS if k in text]

def is_excluded(title: str) -> bool:
    return any(x in (title or "") for x in EXCLUDE)

def is_relevant(title: str, body: str = "") -> bool:
    if is_excluded(title):
        return False
    return len(hit_keywords(title, body)) > 0
