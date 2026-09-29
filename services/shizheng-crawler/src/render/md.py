# -*- coding: utf-8 -*-
"""渲染层：生成与现有文件同构的 markdown。

输出结构（与 时政考点_2026年9月.md 保持一致）：
  速览表 → 分板块固定表述 → 事实速记卡 → 教材接口 → 风险提示
"""
import logging
from datetime import date
from pathlib import Path
from ..config import OUT_DIR

log = logging.getLogger("render")

MODULE_ORDER = ["习思想", "当代", "史纲", "思法", "马原", "时政"]
PRIORITY_ORDER = ["绝高", "极高", "很高", "高", "中高", "中"]


def _prio_key(x):
    p = x.get("priority", "中")
    return PRIORITY_ORDER.index(p) if p in PRIORITY_ORDER else len(PRIORITY_ORDER)


def render_markdown(items: list, title: str, date_range: str, meta: dict) -> str:
    items = [i for i in items if not i.get("skip")]
    items.sort(key=_prio_key)

    L = []
    L.append(f"# {title}")
    L.append("")
    L.append(f"> 整理日期：{date.today().isoformat()}")
    L.append(f"> 收录口径：{date_range}")
    L.append(f"> 数据来源：{meta.get('sources', '人民网 / 人民日报电子版')}")
    L.append(f"> 采集方式：{meta.get('method', '串行单线程、遵守 Crawl-delay、无限速触发')}")
    L.append(f"> 生成方式：自动抓取 + 提炼，共 {len(items)} 条材料")
    L.append("")
    L.append("---")
    L.append("")

    # 一、速览
    L.append("## 一、本篇速览与优先级")
    L.append("")
    L.append("| # | 材料 | 日期 | 类型 | 优先级 | 主考模块 |")
    L.append("|---|---|---|---|---|---|")
    for n, it in enumerate(items, 1):
        t = (it.get("title") or "").replace("|", "｜")[:60]
        L.append(f"| {n} | {t} | {it.get('date','')} | "
                 f"{it.get('type','')} | {it.get('priority','')} | {it.get('module','')} |")
    L.append("")
    L.append("---")
    L.append("")

    # 二、分模块固定表述
    L.append("## 二、分板块固定表述")
    L.append("")
    for mod in MODULE_ORDER:
        group = [i for i in items if i.get("module") == mod]
        if not group:
            continue
        L.append(f"### {mod}")
        L.append("")
        for it in group:
            L.append(f"#### {it.get('title','')} ★{'★' * PRIORITY_ORDER.index(it.get('priority','中')) if it.get('priority') in PRIORITY_ORDER else ''}")
            L.append("")
            L.append(f"- 日期：{it.get('date','')}　来源：{it.get('source','')} {it.get('channel','')}")
            if it.get("facts"):
                L.append("- **必背事实**")
                for f in it["facts"]:
                    L.append(f"    - {f}")
            if it.get("fixed_phrases"):
                L.append("- **固定表述（逐字准确）**")
                for f in it["fixed_phrases"]:
                    L.append(f"    - {f}")
            if it.get("exam_points"):
                L.append("- **出题点**")
                for f in it["exam_points"]:
                    L.append(f"    - {f}")
            if it.get("traps"):
                L.append("- **易混提醒**")
                for f in it["traps"]:
                    L.append(f"    - {f}")
            L.append("")
    L.append("---")
    L.append("")

    # 三、速记卡
    L.append("## 三、事实速记卡（防混淆）")
    L.append("")
    L.append("| 材料 | 关键固定表述 |")
    L.append("|---|---|")
    for it in items:
        ph = it.get("fixed_phrases") or []
        if ph:
            L.append(f"| {(it.get('title') or '')[:34]} | {ph[0][:70]} |")
    L.append("")
    L.append("---")
    L.append("")

    # 四、教材接口
    L.append("## 四、教材接口")
    L.append("")
    L.append("| 材料 | 模块 | 可能形态 |")
    L.append("|---|---|---|")
    for it in items:
        pts = "；".join((it.get("exam_points") or [])[:2])
        L.append(f"| {(it.get('title') or '')[:34]} | {it.get('module','')} | {pts[:70]} |")
    L.append("")
    L.append("---")
    L.append("")

    # 五、待续
    L.append("## 五、下期跟踪重点")
    L.append("")
    L.append("- 二十届五中全会（10月26—29日）——本窗口最高优先级")
    L.append("- 2026年APEC领导人非正式会议（中方主办）")
    L.append("- 二十国集团领导人峰会（第四季度）")
    L.append("- 国庆相关活动")
    L.append("")
    return "\n".join(L)


def write_out(content: str, name: str) -> Path:
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    p = OUT_DIR / name
    p.write_text(content, encoding="utf-8")
    log.info("已写出 %s（%d 字节）", p, len(content.encode("utf-8")))
    return p
