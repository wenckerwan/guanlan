"""生成 storage/dataset/papers.json 与 questions.json（来源：真题库v2）。"""
from __future__ import annotations

import json
from pathlib import Path

from common import clean, read_text

SOURCE = Path(r"F:\2027考研资料\考研政治\真题库v2")
OUT = Path(__file__).resolve().parents[2] / "storage" / "dataset"

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


def normalize(record: dict) -> dict:
    out = {k: record.get(k) for k in QUESTION_FIELDS}
    out["module_name"] = MODULE_LABEL.get(record.get("module"), record.get("module_name") or "未归类")
    out["stem"] = clean(record.get("stem"))
    out["material"] = clean(record.get("material"))
    out["analysis"] = clean(record.get("analysis"))
    out["kaodian"] = clean(record.get("kaodian"))
    out["answer"] = clean(record.get("answer"))
    options = record.get("options") or {}
    out["options"] = {k: clean(v) for k, v in options.items()} if isinstance(options, dict) else {}
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
