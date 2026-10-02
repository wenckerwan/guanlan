#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""验证「推送路径自己会算相似度」：挑一条 2026-10-01 的候选，把三列清零，
再用原 payload 单条重推，确认 exam_sim 由 upsertCandidates() 重新算出来
（而不是靠 shizheng:rescore 回填的）。只动这一行，跑完自动核对。

用法（服务器上）：cd /opt/shizheng && set -a && . ./.env && set +a && ./venv/bin/python deploy/check_push_path_scores.py
"""
import json
import os
import sys

sys.path.insert(0, "/opt/shizheng")
import requests  # noqa: E402

API = os.getenv("GUANLAN_API_BASE", "http://127.0.0.1:8080").rstrip("/")
DATE = "2026-10-01"


def sql(q):
    """跑一条 SQL（口令从容器自身的 env 取，绝不出容器）。
    语句经 -e 环境变量传递：拼进 sh -c 字符串时引号会被两层 shell 吃掉（实测踩过）。"""
    import subprocess
    r = subprocess.run(["docker", "exec", "-e", f"Q={q}", "guanlan-mysql-1", "sh", "-c",
                        'mysql --default-character-set=utf8mb4 -uroot '
                        '-p"$MYSQL_ROOT_PASSWORD" guanlan -N -B -e "$Q"'],
                       capture_output=True, text=True)
    if r.returncode != 0:
        raise RuntimeError(r.stderr[:200])
    return [line.split("\t") for line in r.stdout.splitlines() if line.strip()]


def main() -> int:
    email = os.getenv("GUANLAN_ADMIN_EMAIL")
    pw = os.getenv("GUANLAN_ADMIN_PASSWORD")
    tok = requests.post(f"{API}/api/v1/auth/login",
                        json={"email": email, "password": pw}, timeout=30
                        ).json()["data"]["token"]
    h = {"Authorization": f"Bearer {tok}", "Content-Type": "application/json"}

    rows = sql(f"SELECT id, title FROM shizheng_candidates "
               f"WHERE publish_date='{DATE}' AND status='pending' LIMIT 1")
    if not rows:
        print("没有 pending 候选，跳过")
        return 1
    cid, title = rows[0][0], rows[0][1]
    payload = json.loads(sql(f"SELECT payload FROM shizheng_candidates WHERE id={cid}")[0][0])

    sql(f"UPDATE shizheng_candidates SET exam_sim=0, exam_affinity=0, exam_matches=NULL "
        f"WHERE id={cid}")
    print(f"目标行 id={cid} {title[:40]} → 三列已清零")
    print("清零后：", sql(f"SELECT exam_sim, exam_affinity, IFNULL(exam_matches,'NULL') "
                         f"FROM shizheng_candidates WHERE id={cid}")[0])

    r = requests.post(f"{API}/api/v1/admin/shizheng/candidates", headers=h,
                      json={"date": DATE, "items": [payload]}, timeout=120)
    print("\n重推返回：", r.status_code, r.json().get("data"))

    after = sql(f"SELECT exam_sim, exam_affinity, CHAR_LENGTH(IFNULL(exam_matches,'')) "
                f"FROM shizheng_candidates WHERE id={cid}")[0]
    print("重推后：", after)
    ok = float(after[0]) > 0 and int(after[2]) > 0
    print("\n结论：推送路径", "会" if ok else "不会", "自行计算相似度")
    if not ok:
        return 1
    print(f"（命中题示例：{json.loads(sql(f'SELECT exam_matches FROM shizheng_candidates WHERE id={cid}')[0][0])[0]}）")
    return 0


if __name__ == "__main__":
    sys.exit(main())
