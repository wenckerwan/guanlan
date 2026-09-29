"""生成 analysis_articles.json / hotspots.json / predictions.json。"""
from __future__ import annotations

import json
import re
from common import (
    DATASET_ROOT,
    RAW_ROOT,
    clean,
    md_to_html,
    read_text,
    slugify,
    split_all_headings,
    star_priority,
)

ROOT = RAW_ROOT
ANALYSIS_DIR = ROOT / "真题分析_2012-2026"
HOTSPOT_DIR = ROOT / "时政热点"
OUT = DATASET_ROOT

ANALYSIS_CATEGORY = {
    "选择题分析.md": "选择题规律",
    "选择题绝对错误选项规律.md": "选择题规律",
    "选择题绝对错误选项规律v2.md": "选择题规律",
    "选择题绝对错误选项规律v2_发行版.md": "选择题规律",
    "错项核对_2024-2026.md": "选择题规律",
    "分析题分析.md": "分析题规律",
    "综合结论.md": "综合结论",
    "重要会议与真题切合度.md": "会议与周年",
    "周年纪念日与真题切合度.md": "会议与周年",
    "周年会议与真题规律总结.md": "会议与周年",
    "国际会议真题分析报告.md": "会议与周年",
    "早期真题分析_1994-2011.md": "选择题规律",
    "真题分析复盘_OCR与答案错位_20260922.md": "数据说明",
}

# 时政类文件：归入 hotspots / predictions
HOTSPOT_FILES = sorted(HOTSPOT_DIR.glob("时政考点_*.md"))
SUPPLEMENT_FILES = sorted(ANALYSIS_DIR.glob("时政增补_*.md"))
PREDICTION_FILES = [
    HOTSPOT_DIR / "时政考点预测_真题反推" / "2027时政考点预测_纯真题反推.md",
    ANALYSIS_DIR / "2027考研政治时政热点预测.md",
    ANALYSIS_DIR / "人民网2026考研政治时政精选.md",
    ANALYSIS_DIR / "人民网462314建党105周年专题考研政治精选.md",
    ANALYSIS_DIR / "上合组织专题_2026.md",
    *SUPPLEMENT_FILES,
]

PREDICTION_LAYER = {
    "2027时政考点预测_纯真题反推.md": "真题反推",
    "2027考研政治时政热点预测.md": "热点预测",
    "人民网2026考研政治时政精选.md": "原文精选",
    "人民网462314建党105周年专题考研政治精选.md": "专题精选",
    "上合组织专题_2026.md": "专题精选",
}


def body_without_h1(text: str) -> str:
    return re.sub(r"^#\s+.*$", "", text, count=1, flags=re.M).strip()


def summary_of(text: str, limit: int = 120) -> str:
    """摘要只取正文段落：跳过标题、引用、表格、分隔线与列表标记。"""
    body = body_without_h1(text)
    body = re.sub(r"^\s*\|.*$", "", body, flags=re.M)          # 表格行
    body = re.sub(r"^\s*[-:]{3,}.*$", "", body, flags=re.M)    # 表格分隔线
    body = re.sub(r"^>\s?.*$", "", body, flags=re.M)           # 引用
    body = re.sub(r"^#{1,6}\s+.*$", "", body, flags=re.M)      # 标题
    body = re.sub(r"^\s*[-*+]\s+", "", body, flags=re.M)       # 列表标记
    body = re.sub(r"^\s*\d+[.、)]\s+", "", body, flags=re.M)
    body = re.sub(r"[*`_]+", "", body)
    body = re.sub(r"^---$", "", body, flags=re.M)
    body = re.sub(r"\s+", " ", body).strip()
    return body[:limit]


def outline_of(text: str) -> list[dict]:
    return [
        {"level": lvl, "title": clean(title)}
        for lvl, title, _ in split_all_headings(text)
        if lvl <= 3
    ]


def build_analysis_articles() -> list[dict]:
    items = []
    for order, path in enumerate(sorted(ANALYSIS_DIR.glob("*.md"))):
        if path.name == "README.md" or path.name not in ANALYSIS_CATEGORY:
            continue
        text = read_text(path)
        title = next((t for lvl, t, _ in split_all_headings(text) if lvl == 1), path.stem)
        # 「发行版」与工作稿标题相同，slug 必须区分，否则唯一约束会合并掉发布稿
        slug_source = title if "_发行版" not in path.name else f"{title} 发行版"
        items.append({
            "slug": slugify(slug_source, "analysis"),
            "release": "_发行版" in path.name,
            "title": title,
            "category": ANALYSIS_CATEGORY[path.name],
            "priority": star_priority(text, "A"),
            "summary": summary_of(text),
            "html": md_to_html(body_without_h1(text)),
            "outline": outline_of(text),
            "source_file": path.name,
            "word_count": len(text),
            "sort_order": order,
        })
    return items


def build_hotspots() -> list[dict]:
    items = []
    for order, path in enumerate(HOTSPOT_FILES):
        text = read_text(path)
        title = next((t for lvl, t, _ in split_all_headings(text) if lvl == 1), path.stem)
        period = path.stem.replace("时政考点_", "")
        items.append({
            "slug": slugify(period, "hotspot"),
            "title": title,
            "period": period,
            "priority": star_priority(text, "A"),
            "summary": summary_of(text),
            "html": md_to_html(body_without_h1(text)),
            "outline": outline_of(text),
            "source_file": path.name,
            "sort_order": order,
        })
    return items


def build_predictions() -> list[dict]:
    items = []
    for order, path in enumerate(PREDICTION_FILES):
        if not path.exists():
            continue
        text = read_text(path)
        title = next((t for lvl, t, _ in split_all_headings(text) if lvl == 1), path.stem)
        items.append({
            "slug": slugify(title, "prediction"),
            "title": title,
            "layer": "每日增补" if path.name.startswith("时政增补_") else PREDICTION_LAYER.get(path.name, "其他"),
            "priority": star_priority(text, "A"),
            "summary": summary_of(text),
            "html": md_to_html(body_without_h1(text)),
            "outline": outline_of(text),
            "source_file": path.name,
            "word_count": len(text),
            "sort_order": order,
        })
    return items


def main() -> None:
    OUT.mkdir(parents=True, exist_ok=True)
    for name, payload in [
        ("analysis_articles.json", build_analysis_articles()),
        ("hotspots.json", build_hotspots()),
        ("predictions.json", build_predictions()),
    ]:
        (OUT / name).write_text(json.dumps(payload, ensure_ascii=False, indent=1), encoding="utf-8")
        print(name, len(payload))


if __name__ == "__main__":
    main()
