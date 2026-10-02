#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""回放验收：用 2026-09-30 那天的 51 条候选，验证「真题相关度」确实纠正了漏选/误发。

方案 §3 的验收标准：
  必须进 top10：#413 党建思想研讨会 / #427 文化赋能 / #415 高水平安全护航 / #410 中日四个政治文件
  必须跌出：   #404 增值税留抵退税 / #403 央行货币政策工具

只干跑（auto=false 不发布），因此不会改动线上已发布内容；但会重写该日期候选的
ai_* 与 status 标记，跑完建议再用 strategy=ai 跑一次把 AI 口径恢复回去。

用法（在服务器上）：
  GUANLAN_ADMIN_EMAIL=... GUANLAN_ADMIN_PASSWORD=... python3 deploy/check_exam_replay.py [2026-09-30]
凭据留空时自动读 /opt/shizheng/.env。
"""
import json
import os
import sys

import requests

API = os.getenv("GUANLAN_API_BASE", "http://127.0.0.1:8080")
MUST_IN = {413: "党建思想研讨会", 427: "文化赋能", 415: "高水平安全护航", 410: "中日四个政治文件"}
MUST_OUT = {404: "增值税留抵退税", 403: "央行货币政策工具"}


def creds():
    email = os.getenv("GUANLAN_ADMIN_EMAIL", "")
    pw = os.getenv("GUANLAN_ADMIN_PASSWORD", "")
    if email and pw:
        return email, pw
    env = {}
    for line in open("/opt/shizheng/.env", encoding="utf-8"):
        if "=" in line and not line.strip().startswith("#"):
            k, _, v = line.partition("=")
            env[k.strip()] = v.strip()
    return env["GUANLAN_ADMIN_EMAIL"], env["GUANLAN_ADMIN_PASSWORD"]


def token():
    email, pw = creds()
    r = requests.post(f"{API}/api/v1/auth/login",
                      json={"email": email, "password": pw}, timeout=30)
    r.raise_for_status()
    return r.json()["data"]["token"]


def main() -> int:
    date = sys.argv[1] if len(sys.argv) > 1 else "2026-09-30"
    h = {"Authorization": f"Bearer {token()}"}

    rows = requests.get(f"{API}/api/v1/admin/shizheng/candidates",
                        headers=h, params={"date": date}, timeout=60).json()["data"]
    by_id = {r["id"]: r for r in rows}
    print(f"{date} 候选 {len(rows)} 条")
    missing = [i for i in list(MUST_IN) + list(MUST_OUT) if i not in by_id]
    if missing:
        print(f"!! 候选 id 不在池里：{missing}（日期选错了？）")
        return 2

    print("\n相似度落列情况：")
    for i, name in {**MUST_IN, **MUST_OUT}.items():
        r = by_id[i]
        mark = "该选" if i in MUST_IN else "不该发"
        print(f"  #{i} sim={r['examSim']:.4f} affinity={r['examAffinity']:.4f} "
              f"top={len(r['examMatches'])}  {name}（{mark}）")

    rc = 0
    for strategy in ("sim_only", "hybrid", "ai"):
        res = requests.post(f"{API}/api/v1/admin/shizheng/screen", headers=h,
                            json={"date": date, "top": 10, "auto": False,
                                  "strategy": strategy}, timeout=300).json()["data"]
        if "selected" not in res:
            print(f"\n[{strategy}] 返回异常：{res}")
            return 1
        picked = [s["index"] for s in res["selected"]]
        ids = sorted(by_id.keys())
        chosen = {ids[p] for p in picked if p < len(ids)}
        print(f"\n[{strategy}] 入选 10 条 fallback={res['fallback']} "
              f"reason={res.get('fallbackReason')}")
        for i, name in MUST_IN.items():
            hit = i in chosen
            rc |= 0 if hit else 1
            print(f"   {'✓' if hit else '✗'} 应进 #{i} {name}")
        for i, name in MUST_OUT.items():
            out = i not in chosen
            rc |= 0 if out else 1
            print(f"   {'✓' if out else '✗'} 应出 #{i} {name}")

    print("\n结论：", "全部满足" if rc == 0 else "有未达项")
    return rc


if __name__ == "__main__":
    sys.exit(main())
