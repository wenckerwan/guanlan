"""生成 storage/dataset/mistakes.json（考生隔离的错题册 + 提分手册）。"""
from __future__ import annotations

import json
import re
from pathlib import Path

from common import DATASET_ROOT, RAW_ROOT, clean, md_to_html, read_text, split_all_headings

ROOT = RAW_ROOT / "个人错题分析"
OUT = DATASET_ROOT

STUDENTS = [
    {"code": "A", "name": "考生 A", "relation": "本人", "dir": "本人_wencker"},
    {"code": "B", "name": "考生 B", "relation": "朋友", "dir": "徐丽丹"},
]

# 文件名后缀 -> 模块名（两个考生的命名不完全一致，故按后缀统一）
MODULE_SUFFIX = {
    "": "马原",
    "_马原": "马原",
    "_史纲": "史纲",
    "_毛概": "毛中特",
    "_毛中特": "毛中特",
}


def module_of(filename: str, prefix: str) -> str | None:
    if not filename.startswith(prefix) or not filename.endswith(".md"):
        return None
    return MODULE_SUFFIX.get(filename[len(prefix):-3])

OPTION_MARK = {
    "我选了（错）": "chosen",
    "我选了(错)": "chosen",
    "正确项（我漏了）": "missed",
    "正确项(我漏了)": "missed",
    "正确项（你选对了）": "hit",
    "正确项(你选对了)": "hit",
    "正确项": "correct",
}

ITEM_HEAD_RE = re.compile(r"^##\s+(.*)$", re.M)
CHAPTER_HEAD_RE = re.compile(r"^#\s+(.*)$", re.M)
CN_DIGITS = {"一": 1, "二": 2, "三": 3, "四": 4, "五": 5,
             "六": 6, "七": 7, "八": 8, "九": 9, "十": 10}


def chapter_number(title: str) -> int:
    m = re.match(r"^第\s*([一二三四五六七八九十]+)\s*章", title or "")
    if not m:
        return 0
    word = m.group(1)
    if word == "十":
        return 10
    if len(word) == 2 and word[0] == "十":
        return 10 + CN_DIGITS.get(word[1], 0)
    if len(word) == 2 and word[1] == "十":
        return CN_DIGITS.get(word[0], 0) * 10
    return CN_DIGITS.get(word, 0)


def parse_state(text: str) -> dict:
    """解析 `**上次**：我选 **B** ｜ 正确 **D** ｜ 单选`。

    考生 B 的马原批次导出时删掉了错选项，占位为「（导出时已删）」，
    此时 myAnswer 记为 `未记录`，但正确项与题型仍要解析出来。
    """
    m = re.search(
        r"我选\s*\*{0,2}([A-D,\s]*|（[^）]*）)\*{0,2}\s*[｜|]\s*正确\s*\*{0,2}([A-D,\s]+?)\*{0,2}\s*[｜|]\s*([^\n*]+)",
        text,
    )
    if not m:
        loose = re.search(r"正确\s*\*{0,2}([A-D][A-D,\s]*)\*{0,2}", text)
        if loose:
            correct = clean(loose.group(1)).replace(" ", "")
            return {"myAnswer": "", "correctAnswer": correct, "qType": ""}
        return {"myAnswer": "", "correctAnswer": "", "qType": ""}

    chosen = clean(m.group(1)).replace(" ", "")
    if not re.fullmatch(r"[A-D,]*", chosen):
        chosen = ""
    return {
        "myAnswer": chosen,
        "correctAnswer": clean(m.group(2)).replace(" ", ""),
        "qType": clean(m.group(3)),
    }


def parse_options(body: str) -> list[dict]:
    options = []
    for line in body.splitlines():
        m = re.match(r"^-\s*([A-D])[．.、]\s*(.*)$", line.strip())
        if not m:
            continue
        label, raw = m.group(1), m.group(2)
        mark = ""
        for key, value in OPTION_MARK.items():
            if key in raw:
                mark = value
                raw = raw.split("←")[0]
                break
        options.append({"label": label, "text": clean(raw), "mark": mark})
    return options


def error_type(chosen: str, correct: str) -> str:
    if not correct:
        return "未知"
    if not chosen:
        # 考生 B 的马原批次：导出件删掉了错选项，错因不可判定
        return "未记录"
    a, b = set(chosen), set(correct)
    if a == b:
        return "正确"
    if a & b:
        return "既漏又错"
    return "纯错选" if len(chosen) >= len(correct) else "纯漏选"


def chapter_map(text: str) -> list[tuple[int, str]]:
    return [(m.end(), clean(m.group(1))) for m in CHAPTER_HEAD_RE.finditer(text)]


def chapter_of(mapping: list[tuple[int, str]], offset: int, fallback: str) -> str:
    current = fallback
    for pos, title in mapping:
        if pos <= offset:
            current = title
        else:
            break
    return current


def parse_recall(path: Path, module: str) -> list[dict]:
    text = read_text(path)
    mapping = chapter_map(text)
    marks = list(ITEM_HEAD_RE.finditer(text))
    items = []
    for i, m in enumerate(marks):
        head = clean(m.group(1))
        if head.startswith("进度表") or "题数" in head:
            continue
        end = marks[i + 1].start() if i + 1 < len(marks) else len(text)
        body = text[m.end():end]
        # 题号优先取「题库题号」（回访清单明确要求以题库真实题号为准），否则退回顺序号
        bracket = re.search(r"〔(.*?)〕", head)
        tag = clean(bracket.group(1)) if bracket else clean(head)
        chapter = chapter_of(mapping, m.start(), clean(re.sub(r"^[0-9]+[.、]\s*", "", head.split("〔")[0])))
        qbank = re.search(r"第?\s*([一二三四五六七八九十]+)章\s*第?\s*(\d+)\s*题", tag)
        if qbank:
            code = qbank.group(2)
            chapter = f"第{qbank.group(1)}章"
        else:
            qbank = re.search(r"([一二三四五六七八九十]+)章\s*[·・]\s*第\s*(\d+)\s*题", head)
            if qbank:
                code = qbank.group(2)
                chapter = f"第{qbank.group(1)}章"
            else:
                num = re.match(r"^([0-9]+)", head)
                code = num.group(1) if num else str(len(items) + 1)

        stem_lines = []
        for line in body.splitlines():
            s = line.strip()
            if not s:
                continue
            if s.startswith("**上次**"):
                break
            if s.startswith(("-", "**这次**", ">", "#")):
                continue
            stem_lines.append(s)

        state = parse_state(body)
        qtype = state["qType"] if state["qType"] in ("单选", "多选") else (
            "多选" if len(state["correctAnswer"]) > 1 else "单选"
        )
        items.append({
            "module": module,
            "chapter": chapter,
            "chapterNo": chapter_number(chapter),
            "sourceNo": code,
            "kaodian": tag,
            "stem": clean(" ".join(stem_lines)),
            "options": parse_options(body),
            "myAnswer": state["myAnswer"],
            "correctAnswer": state["correctAnswer"],
            "qType": qtype,
            "errorType": error_type(state["myAnswer"], state["correctAnswer"]),
            "action": "",
        })
    return items


DETAIL_ROW_RE = re.compile(r"^\|(.*)\|\s*$", re.M)


def _cells(line: str) -> list[str]:
    return [clean(c) for c in line.strip().strip("|").split("|")]


ERROR_KINDS = {"既漏又错", "纯错选", "纯漏选", "正确"}


def parse_details(path: Path) -> dict[str, dict]:
    """错题明细.md → {`{章号}章·{题库题号}`: {action, errorType}}。

    两种表式：
    - 马原式：`| 四章·第3题 | 考点 | 下次怎么做 |`
    - 毛中特式：`| 序号 | 二 | 二第4题（单选） | 考点 | 我选 | 正确 | 错法 |`
    """
    if not path.exists():
        return {}
    table: dict[str, dict] = {}
    for m in DETAIL_ROW_RE.finditer(read_text(path)):
        cells = _cells(m.group(1))
        if len(cells) < 3 or all(set(c) <= set("-: ") for c in cells):
            continue

        # 马原式：首列 `四章·第3题`
        m1 = re.match(r"^([一二三四五六七八九十]+)章\s*[·・]?\s*第?(\d+)题", cells[0])
        if m1:
            key = f"{chapter_number('第' + m1.group(1) + '章')}章·{m1.group(2)}"
            table.setdefault(key, {})["action"] = cells[-1]
            continue

        # 毛中特式：第二列章 + 第三列题号，末列为错法
        m2 = re.match(r"^([一二三四五六七八九十]+)章?第?\s*(\d+)\s*题", cells[2])
        if m2 and re.fullmatch(r"[一二三四五六七八九十]+", cells[1]) and cells[-1] in ERROR_KINDS:
            key = f"{chapter_number('第' + m2.group(1) + '章')}章·{m2.group(2)}"
            table.setdefault(key, {})["errorType"] = cells[-1]
    return table


def parse_handbook(path: Path, module: str) -> dict:
    text = read_text(path)
    title = next((t for lvl, t, _ in split_all_headings(text) if lvl == 1), path.stem)
    sections = [{"title": head, "html": md_to_html(body)}
                for lvl, head, body in split_all_headings(text) if lvl == 1]
    return {
        "module": module,
        "title": clean(title),
        "sections": sections,
        "html": md_to_html(text),
        "sourceFile": path.name,
    }


def main() -> None:
    students, all_items, handbooks = [], [], []
    for meta in STUDENTS:
        folder = ROOT / meta["dir"]
        if not folder.exists():
            continue
        items: list[dict] = []
        for path in sorted(folder.glob("回访清单*.md")):
            module = module_of(path.name, "回访清单")
            if module:
                items.extend(parse_recall(path, module))

        details = parse_details(folder / "错题明细.md")
        for item in items:
            item["studentCode"] = meta["code"]
            detail = details.get(f"{item['chapterNo']}章·{item['sourceNo']}", {})
            item["action"] = detail.get("action", "")
            if detail.get("errorType"):
                item["errorType"] = detail["errorType"]

        detail = folder / "错题明细.md"
        students.append({
            "code": meta["code"],
            "name": meta["name"],
            "relation": meta["relation"],
            "itemCount": len(items),
            "detailHtml": md_to_html(read_text(detail)) if detail.exists() else "",
        })
        all_items.extend(items)

        for path in sorted(folder.glob("提分手册*.md")):
            module = module_of(path.name, "提分手册")
            if not module:
                continue
            hb = parse_handbook(path, module)
            hb["studentCode"] = meta["code"]
            handbooks.append(hb)

    OUT.mkdir(parents=True, exist_ok=True)
    (OUT / "mistakes.json").write_text(
        json.dumps({"students": students, "items": all_items, "handbooks": handbooks},
                   ensure_ascii=False, indent=1),
        encoding="utf-8",
    )
    print(f"students={len(students)} items={len(all_items)} handbooks={len(handbooks)}")
    for s in students:
        print(" ", s["code"], s["name"], s["itemCount"])
    print("  actions filled:", sum(1 for i in all_items if i["action"]))


if __name__ == "__main__":
    main()




