"""生成 mocks.json（模拟押题卷）与 stats.json（图表数据）。"""
from __future__ import annotations

import csv
import json
import re
from pathlib import Path

from common import DATASET_ROOT, RAW_ROOT, clean, md_to_html, read_text, slugify, split_all_headings

ROOT = RAW_ROOT
SHIP = ROOT / "船"
V2 = ROOT / "真题库v2" / "data"
OUT = DATASET_ROOT

QUESTION_RE = re.compile(r"^###\s*(\d+)\.\s*(.*)$", re.M)
OPTION_RE = re.compile(r"^([A-D])[.．、]\s*(.*)$")


def parse_paper(path: Path) -> list[dict]:
    """解析模拟卷 Markdown：`### N.` 题干 + `A. …` 选项。"""
    text = read_text(path)
    marks = list(QUESTION_RE.finditer(text))
    items = []
    for i, m in enumerate(marks):
        end = marks[i + 1].start() if i + 1 < len(marks) else len(text)
        block = text[m.end():end]
        stem_lines, options = [], {}
        for line in block.splitlines():
            s = line.strip()
            if not s:
                continue
            om = OPTION_RE.match(s)
            if om:
                options[om.group(1)] = clean(om.group(2))
            elif not options:
                stem_lines.append(s)
        heading = clean(m.group(2))
        stem = clean(" ".join(stem_lines))
        if heading and not options:
            stem = clean(f"{heading} {stem}")
        items.append({"no": int(m.group(1)), "stem": stem, "options": options})
    return items


def parse_answers(path: Path) -> dict[int, str]:
    """解析答案表：`| 答案 | B | C | …` 按题号表头轮转。"""
    text = read_text(path)
    answers: dict[int, str] = {}
    header: list[int] = []
    for line in text.splitlines():
        cells = [clean(c) for c in line.strip().strip("|").split("|")]
        if len(cells) < 3 or "---" in line:
            continue
        if cells[0] == "题号":
            header = [int(c) for c in cells[1:] if c.isdigit()]
            continue
        if cells[0] == "答案" and header:
            for num, letter in zip(header, cells[1:]):
                if re.fullmatch(r"[A-D]+", letter):
                    answers[num] = letter
    return answers


def qtype_of(no: int) -> str:
    if no <= 16:
        return "single"
    if no <= 33:
        return "multi"
    return "analyse"


def score_of(no: int) -> int:
    return 1 if no <= 16 else (2 if no <= 33 else 10)


def build_mocks() -> list[dict]:
    mocks = []
    paper_dir = sorted(SHIP.glob("*模拟卷*.md"))
    for path in paper_dir:
        if "答案" in path.name:
            continue
        title = next((t for lvl, t, _ in split_all_headings(read_text(path)) if lvl == 1), path.stem)
        items = parse_paper(path)
        answer_path = path.with_name(path.stem + "答案与解析.md")
        answers = parse_answers(answer_path) if answer_path.exists() else {}
        questions = []
        for item in items:
            no = item["no"]
            questions.append({
                "no": no,
                "type": qtype_of(no),
                "typeCn": {"single": "单项选择", "multi": "多项选择", "analyse": "材料分析题"}[qtype_of(no)],
                "score": score_of(no),
                "module": "",
                "moduleName": "",
                "kaodian": "",
                "stem": item["stem"],
                "options": item["options"],
                "answer": answers.get(no, ""),
                "analysis": "",
                "material": "",
            })
        mocks.append({
            "slug": slugify(title, "mock"),
            "title": title,
            "summary": "按 2012—2026 真题规律与 2026 版 720 题命制，含 16 单选 + 17 多选 + 5 分析题。",
            "totalScore": 100,
            "durationMinutes": 180,
            "questionCount": len(questions),
            "answeredCount": len(answers),
            "sourceFile": path.name,
            "questions": questions,
        })
    return mocks


def read_csv(path: Path) -> list[dict]:
    if not path.exists():
        return []
    with path.open(encoding="utf-8-sig", newline="") as handle:
        return [dict(row) for row in csv.DictReader(handle)]


def build_stats() -> dict:
    questions = json.loads((OUT / "questions.json").read_text(encoding="utf-8"))
    papers = json.loads((OUT / "papers.json").read_text(encoding="utf-8"))

    by_module: dict[str, int] = {}
    for q in questions:
        by_module[q["module_name"]] = by_module.get(q["module_name"], 0) + 1

    year_counts: list[dict] = []
    for paper in papers:
        year_counts.append({"year": paper["year"], "pid": paper["pid"], "count": paper["question_count"]})

    wording_single = read_csv(V2 / "stats_wording_单选.csv")
    wording_multi = read_csv(V2 / "stats_wording_多选.csv")

    return {
        "totals": {
            "questions": len(questions),
            "papers": len(papers),
            "years": f"{min(p['year'] for p in papers)}—{max(p['year'] for p in papers)}",
            "withAnswer": sum(1 for q in questions if q["answer"]),
            "withKaodian": sum(1 for q in questions if q["kaodian"]),
            "withAnalysis": sum(1 for q in questions if q["analysis"]),
        },
        "moduleTotal": read_csv(V2 / "stats_module_total.csv"),
        "kaodianTop": read_csv(V2 / "stats_kaodian_top.csv")[:30],
        "multiCombo": read_csv(V2 / "stats_multi_combo.csv"),
        "shishiTopics": read_csv(V2 / "stats_shishi_topics.csv"),
        "structureByYear": read_csv(V2 / "stats_structure_by_year.csv"),
        "moduleByYear": read_csv(V2 / "stats_module_by_year.csv"),
        "difficultyHard": read_csv(V2 / "stats_difficulty_hard.csv"),
        "wordingSingle": wording_single,
        "wordingMulti": wording_multi,
        "byModule": [{"name": k, "count": v} for k, v in sorted(by_module.items(), key=lambda x: -x[1])],
        "yearCounts": year_counts,
    }


def main() -> None:
    OUT.mkdir(parents=True, exist_ok=True)
    mocks = build_mocks()
    (OUT / "mocks.json").write_text(json.dumps(mocks, ensure_ascii=False, indent=1), encoding="utf-8")
    stats = build_stats()
    (OUT / "stats.json").write_text(json.dumps(stats, ensure_ascii=False, indent=1), encoding="utf-8")
    print("mocks", len(mocks), [m["questionCount"] for m in mocks], [m["answeredCount"] for m in mocks])
    print("stats keys", list(stats.keys()))


if __name__ == "__main__":
    main()

