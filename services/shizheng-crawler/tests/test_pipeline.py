# -*- coding: utf-8 -*-
"""pipeline 集成测试：造临时库 → 跑 refine+选条+存档（不推送）。
用法：python -m tests.test_pipeline
"""
import json
import os
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

SAMPLES = [
    ("习近平主持中共中央政治局会议 部署进一步全面深化改革",
     "中共中央政治局9月28日召开会议，中共中央总书记习近平主持会议。"
     "会议指出，进一步全面深化改革必须坚持高质量发展，推进中国式现代化。"
     "会议强调，要坚持党的全面领导，坚持以人民为中心。"),
    ("中美举行新一轮经贸磋商",
     "中美双方9月28日举行经贸磋商，就关税、市场准入等问题交换意见。"
     "双方同意保持沟通，推动中美关系健康稳定发展。"),
    ("生态环境法典草案提请审议",
     "生态环境法典草案9月28日提请全国人大常委会审议。"
     "草案统筹山水林田湖草沙一体化保护和系统治理，聚焦绿色低碳发展。"),
    ("中国男足友谊赛获胜", "中国男足在友谊赛中2比1获胜，前锋梅开二度。"),
]


def main():
    tmp = tempfile.mkdtemp(prefix="shizheng_test_")
    os.environ["SHIZHENG_DATA_DIR"] = tmp        # 必须在 import src 之前生效
    os.environ.pop("LLM_API_KEY", None)
    os.environ.pop("OPENAI_API_KEY", None)
    env = dict(os.environ, PYTHONIOENCODING="utf-8")

    from src.core import db
    db.init_db()
    for title, body in SAMPLES:
        db.save_article(url=f"http://test.local/{abs(hash(title))}",
                        source="rmrb", title=title, publish_date="2026-09-28",
                        body=body, channel="第01版")

    r = subprocess.run([sys.executable, "pipeline.py", "--date", "2026-09-28",
                        "--no-push"], cwd=ROOT, env=env,
                       capture_output=True, text=True, encoding="utf-8")
    print(r.stdout[-2000:])
    assert r.returncode == 0, f"pipeline 退出码 {r.returncode}\n{r.stderr[-1500:]}"

    archive = Path(tmp) / "out" / "top10_2026-09-28.json"
    assert archive.is_file(), "未生成存档 JSON"
    items = json.loads(archive.read_text(encoding="utf-8"))
    titles = [i["title"] for i in items]
    print(f"入选 {len(items)} 条：{titles}")
    assert len(items) == 3, "应入选 3 条（体育新闻应被过滤）"
    assert not any("男足" in t for t in titles), "体育内容未被过滤"

    r2 = subprocess.run([sys.executable, "pipeline.py", "--date", "2026-09-28",
                         "--no-push"], cwd=ROOT, env=env,
                        capture_output=True, text=True, encoding="utf-8")
    assert r2.returncode == 2, "重跑应返回 2（文章已提炼，入选 0 条触发告警路径）"
    print("pipeline 集成测试全部通过（含重跑幂等/告警路径）")


if __name__ == "__main__":
    main()
