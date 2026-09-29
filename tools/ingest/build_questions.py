"""生成 storage/dataset/papers.json 与 questions.json（来源：真题库v2）。"""
from __future__ import annotations

import json
import re
from common import DATASET_ROOT, RAW_ROOT, clean, read_text

SOURCE = RAW_ROOT / "真题库v2"
OUT = DATASET_ROOT

MODULE_LABEL = {
    "my": "马克思主义基本原理",
    "mzt": "毛泽东思想和中国特色社会主义理论体系概论",
    "sg": "中国近现代史纲要",
    "sx": "习近平新时代中国特色社会主义思想概论",
    "sc": "思想道德与法治",
    "xs": "形势与政策以及当代世界经济与政治",
    "un": "未归类",
}

QUESTION_FIELDS = [
    "pid", "year", "label", "no", "type", "type_cn", "sec", "score",
    "module", "module_name", "super", "super_name", "module_conf", "module_topic",
    "kaodian", "answer", "trap", "n_opt", "options", "stem", "material",
    "answer_text", "analysis", "accuracy", "n_tried", "q_src", "flag", "in_stats",
]

# 源数据的选项/题干尾部常混入答案与解析块（OCR/整理残留），需剥出
MARK_RE = re.compile(r"【(?:答案|解析|分析)】")
ANSWER_LETTER_RE = re.compile(r"【答案】\s*([A-D]{1,4})")
SUB_QUESTION_RE = re.compile(r"\n?\s*（\d+）[^【]*\Z")


def split_marked(text: str) -> tuple[str, str]:
    """按【答案】/【解析】/【分析】把尾部材料从正文剥出，返回 (正文, 尾部)。"""
    match = MARK_RE.search(text)
    if not match:
        return text.strip(), ""
    return text[: match.start()].strip(), text[match.start() :].strip()


def split_reference_answers(stem: str) -> tuple[str, str]:
    """把题干里的【参考答案】块抽到解析里，保留子问题小节在题干的顺序。"""
    chunks = re.split(r"【参考答案】", stem)
    if len(chunks) == 1:
        return stem.strip(), ""
    question_parts = [chunks[0].strip()]
    answer_parts = []
    for chunk in chunks[1:]:
        # 答案块末尾若出现下一小节的子题干（（2）……（5 分）），拆回题干
        match = SUB_QUESTION_RE.search(chunk)
        if match:
            answer_parts.append(chunk[: match.start()].strip())
            question_parts.append(chunk[match.start() :].strip())
        else:
            answer_parts.append(chunk.strip())
    return "\n".join(question_parts), "\n".join(answer_parts)


def normalize(record: dict) -> dict:
    out = {k: record.get(k) for k in QUESTION_FIELDS}
    out["module_name"] = MODULE_LABEL.get(record.get("module"), record.get("module_name") or "未归类")
    out["material"] = clean(record.get("material"))
    out["kaodian"] = clean(record.get("kaodian"))
    out["answer"] = clean(record.get("answer"))
    analysis_extra: list[str] = []

    # 选择题题干尾部可能带【答案】X【分析】…，剥出进解析
    stem, tail = split_marked(clean(record.get("stem")))
    if tail:
        analysis_extra.append(tail)
        letter = ANSWER_LETTER_RE.search(tail)
        if letter and not out["answer"]:
            out["answer"] = letter.group(1).upper()
    # 分析题题干混排【参考答案】块，拆分为题干 + 解析
    if "【参考答案】" in stem:
        stem, ref_answers = split_reference_answers(stem)
        if ref_answers:
            analysis_extra.insert(0, ref_answers)
    out["stem"] = stem

    options = record.get("options") or {}
    cleaned_options: dict[str, str] = {}
    if isinstance(options, dict):
        for key, value in options.items():
            body, tail = split_marked(clean(value))
            if tail:
                analysis_extra.append(tail)
                letter = ANSWER_LETTER_RE.search(tail)
                if letter and not out["answer"]:
                    out["answer"] = letter.group(1).upper()
            cleaned_options[key] = body
    out["options"] = cleaned_options

    analysis = clean(record.get("analysis"))
    if analysis:
        analysis_extra.append(analysis)
    if analysis_extra:
        analysis = "\n\n".join(analysis_extra)
    out["analysis"] = analysis
    return out


def main() -> None:
    rows = [json.loads(line) for line in read_text(SOURCE / "data" / "questions.jsonl").splitlines() if line.strip()]
    questions = [normalize(r) for r in rows]

    index = json.loads(read_text(SOURCE / "papers" / "_index.json"))
    by_pid: dict[str, list[dict]] = {}
    for q in questions:
        by_pid.setdefault(q["pid"], []).append(q)

    papers = []
    for order, meta in enumerate(index):
        pid = meta["pid"]
        items = by_pid.get(pid, [])
        papers.append({
            "pid": pid,
            "year": meta["year"],
            "label": meta.get("label") or "",
            "kind": meta.get("kind") or "",
            "question_count": len(items),
            "total_score": sum(q["score"] or 0 for q in items),
            "answered_count": sum(1 for q in items if q.get("answer")),
            "sections": meta.get("sections") or [],
            "sort_order": order,
        })

    OUT.mkdir(parents=True, exist_ok=True)
    (OUT / "questions.json").write_text(json.dumps(questions, ensure_ascii=False), encoding="utf-8")
    (OUT / "papers.json").write_text(json.dumps(papers, ensure_ascii=False, indent=1), encoding="utf-8")
    print(f"papers={len(papers)} questions={len(questions)}")


if __name__ == "__main__":
    main()
