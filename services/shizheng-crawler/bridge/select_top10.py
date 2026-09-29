# -*- coding: utf-8 -*-
"""从当天提炼结果中选出最适合考研政治的 N 条。

规则：
  1. 按优先级排序：绝高 > 极高 > 很高 > 高 > 中高 > 中
  2. 同级按模块覆盖度优先（同一天避免 10 条全是同一模块）
  3. 按标题去重（同一事件多篇报道只留优先级最高的一条）
"""
from src.render.md import PRIORITY_ORDER


def _prio_key(item: dict) -> int:
    p = item.get("priority", "中")
    return PRIORITY_ORDER.index(p) if p in PRIORITY_ORDER else len(PRIORITY_ORDER)


def select_top(items: list, n: int = 10) -> list:
    """返回排序后的前 n 条；不足 n 条时有多少返回多少。"""
    seen_titles, deduped = set(), []
    for it in sorted(items, key=_prio_key):
        if it.get("skip"):
            continue
        t = (it.get("title") or "").strip()
        if not t or t in seen_titles:
            continue
        seen_titles.add(t)
        deduped.append(it)

    picked, rest = [], []
    modules = set()
    for it in deduped:
        mod = it.get("module", "")
        if len(picked) < n and (mod not in modules or len(picked) < n // 2):
            picked.append(it)
            modules.add(mod)
        else:
            rest.append(it)
    for it in rest:                       # 模块去重后仍不足 n 条，按顺序补齐
        if len(picked) >= n:
            break
        picked.append(it)
    return picked
