"""观澜 ingest 公共工具：Markdown -> HTML、星级、文本清洗。"""
from __future__ import annotations

import re
from pathlib import Path

import markdown

MD = markdown.Markdown(extensions=["tables", "fenced_code", "sane_lists", "nl2br"])


def md_to_html(text: str) -> str:
    MD.reset()
    return MD.convert(text or "")


def clean(text: str) -> str:
    text = (text or "").replace("\u00a0", " ").replace("\u3000", " ")
    text = re.sub(r"[ \t]+", " ", text)
    return text.strip()


def star_priority(text: str, default: str = "B") -> str:
    """★★★★★ -> S, ★★★★ -> A, ★★★ -> B, ★★ -> C"""
    m = re.search(r"(★+)", text or "")
    if not m:
        return default
    n = len(m.group(1))
    return {5: "S", 4: "A", 3: "B", 2: "C", 1: "C"}.get(n, default)


def strip_stars(text: str) -> str:
    return re.sub(r"[★☆]+", "", text or "").strip()


def first_sentence(text: str, limit: int = 80) -> str:
    body = re.sub(r"\s+", " ", strip_md(text)).strip()
    if not body:
        return ""
    for sep in ["。", "；", "！", "？", ". "]:
        idx = body.find(sep)
        if 0 < idx <= limit:
            return body[: idx + 1]
    return body[:limit]


def strip_md(text: str) -> str:
    text = re.sub(r"`{1,3}", "", text or "")
    text = re.sub(r"\*\*|\*|__", "", text)
    text = re.sub(r"^[#>\-\s|]+", "", text, flags=re.M)
    text = re.sub(r"\[(.*?)\]\((.*?)\)", r"\1", text)
    return text


def slugify(text: str, fallback: str = "item") -> str:
    """中文标题 -> 稳定 slug：保留 ascii，中文转拼音级不可用时用哈希后缀。"""
    import hashlib

    ascii_part = re.sub(r"[^a-zA-Z0-9]+", "-", text or "").strip("-").lower()
    digest = hashlib.sha1((text or fallback).encode("utf-8")).hexdigest()[:8]
    if ascii_part:
        return f"{ascii_part[:48]}-{digest}"
    return f"{fallback}-{digest}"


def read_text(path: Path) -> str:
    return path.read_text(encoding="utf-8", errors="replace")


def split_sections(text: str, level: int = 2) -> list[tuple[str, str]]:
    """按指定级别标题切分，返回 [(标题, 正文)]。"""
    pattern = re.compile(rf"^{'#' * level}\s+(.*)$", re.M)
    marks = list(pattern.finditer(text))
    out: list[tuple[str, str]] = []
    for i, m in enumerate(marks):
        end = marks[i + 1].start() if i + 1 < len(marks) else len(text)
        out.append((m.group(1).strip(), text[m.end():end].strip()))
    return out


def split_all_headings(text: str) -> list[tuple[int, str, str]]:
    """返回 [(级别, 标题, 正文到下一个任意级别标题)]。"""
    pattern = re.compile(r"^(#{1,6})\s+(.*)$", re.M)
    marks = list(pattern.finditer(text))
    out: list[tuple[int, str, str]] = []
    for i, m in enumerate(marks):
        end = marks[i + 1].start() if i + 1 < len(marks) else len(text)
        out.append((len(m.group(1)), m.group(2).strip(), text[m.end():end].strip()))
    return out
