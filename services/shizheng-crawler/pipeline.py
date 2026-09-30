# -*- coding: utf-8 -*-
"""每日时政流水线：提炼当天文章 → 推送候选 → 服务端 AI 筛选并发布。

流程：
  1. 提炼目标日期未处理的文章（LLM 或规则兜底）
  2. 全部候选存档 JSON 并推送到观澜候选池（需网站 shizheng 补丁）
  3. 触发服务端 AI 筛选（auto：筛完自动发布到 hotspots）
  4. 补丁未部署（404）时回退：本地选 top N 直接推 hotspots（--legacy 强制此路径）

用法：
  python pipeline.py                      # 处理昨天（与 daily 抓取配套）
  python pipeline.py --date 2026-09-28    # 指定日期
  python pipeline.py --no-push            # 只生成 JSON 存档，不推网站
  python pipeline.py --legacy             # 强制旧的本地筛选直推流程
  python pipeline.py --top 10             # 入选条数（默认读 DAILY_TOP_N 或 10）

退出码：0 正常；2 当天无文章或无候选（可能网站改版/未出报，应告警）；1 异常。
"""
import argparse
import json
import logging
import os
import sys
from datetime import date, timedelta

import requests

from src.config import (DAILY_TOP_N, GUANLAN_API_BASE, GUANLAN_ADMIN_EMAIL,
                        GUANLAN_ADMIN_PASSWORD, GUANLAN_SUBJECT_ID, OUT_DIR)
from src.core import db
from src.core import filter as flt
from src.main import setup_logging
from src.refine import extractor
from bridge.render_html import to_hotspot_payload
from bridge.select_top10 import select_top

log = logging.getLogger("pipeline")


def articles_for_date(d: str) -> list:
    with db.conn() as c:
        return [dict(r) for r in c.execute(
            "SELECT * FROM articles WHERE publish_date=? AND refined=0 "
            "ORDER BY id", (d,)).fetchall()]


def refine_for_date(d: str) -> list:
    """提炼指定日期的未处理文章，返回结构化条目（含 url/source/channel）"""
    rows = articles_for_date(d)
    if not rows:
        log.warning("%s 无待提炼文章", d)
        return []
    kept, skipped = [], []
    for r in rows:
        (kept if flt.is_relevant(r.get("title", ""), r.get("body") or "") else skipped).append(r)
    db.mark_refined([r["id"] for r in skipped])
    log.info("关键词过滤：相关 %d 篇，跳过 %d 篇", len(kept), len(skipped))

    items, done = [], []
    for r in kept:
        try:
            res = extractor.refine(r)
            done.append(r["id"])
            if res.get("skip"):
                continue
            res.setdefault("title", r.get("title", ""))
            res["date"] = r.get("publish_date", "")
            res["source"] = r.get("source", "")
            res["channel"] = r.get("channel", "")
            res["url"] = r.get("url", "")
            items.append(res)
            log.info("  提炼 %s", (r.get("title") or "")[:44])
        except Exception as e:
            log.warning("  提炼失败 %s：%s", (r.get("title") or "")[:30], str(e)[:70])
    db.mark_refined(done)
    return items


def alert(message: str):
    """空结果/失败告警：配置了 ALERT_WEBHOOK 则推送（钉钉/企业微信/飞书机器人通用格式）"""
    log.error("ALERT %s", message)
    url = os.getenv("ALERT_WEBHOOK", "")
    if not url:
        return
    try:
        requests.post(url, json={"msgtype": "text",
                                 "text": {"content": f"[时政抓取] {message}"}},
                      timeout=10)
    except Exception as e:
        log.warning("告警发送失败：%s", e)


def main() -> int:
    ap = argparse.ArgumentParser(description="每日时政 → 观澜网站")
    ap.add_argument("--date", help="处理哪天 YYYY-MM-DD（默认昨天）")
    ap.add_argument("--top", type=int, default=DAILY_TOP_N)
    ap.add_argument("--no-push", action="store_true", help="只存档 JSON，不推网站")
    ap.add_argument("--no-screen", action="store_true",
                    help="只刷新候选池，不触发服务端 AI 筛选/发布"
                         "（已发布条目与 ai_* 结果不受影响）")
    ap.add_argument("--legacy", action="store_true",
                    help="强制旧流程：本地选条直推 hotspots（跳过服务端筛选）")
    ap.add_argument("-v", "--verbose", action="store_true")
    args = ap.parse_args()
    setup_logging(args.verbose)

    target = args.date or (date.today() - timedelta(days=1)).isoformat()
    db.init_db()

    items = refine_for_date(target)
    log.info("%s 提炼完成，候选 %d 条", target, len(items))

    archive = OUT_DIR / f"top10_{target}.json"
    archive.parent.mkdir(parents=True, exist_ok=True)
    archive.write_text(json.dumps(items, ensure_ascii=False, indent=2),
                       encoding="utf-8")
    log.info("已存档 %s", archive)

    if not items:
        alert(f"{target} 候选 0 条（当天无文章或全部无关），请检查数据源")
        return 2

    if args.no_push:
        log.info("--no-push，跳过网站推送")
        return 0

    if not (GUANLAN_API_BASE and GUANLAN_ADMIN_EMAIL and GUANLAN_ADMIN_PASSWORD):
        alert("未配置 GUANLAN_API_BASE / GUANLAN_ADMIN_EMAIL / GUANLAN_ADMIN_PASSWORD，无法推送")
        return 1

    from bridge.push_hotspots import GuanlanClient
    client = GuanlanClient(GUANLAN_API_BASE, GUANLAN_ADMIN_EMAIL, GUANLAN_ADMIN_PASSWORD)

    if not args.legacy:
        # 新流程：推全部候选 → 服务端 AI 筛选并发布（需观澜 shizheng 补丁）
        ok, _ = client.push_candidates(target, items)
        if ok:
            if args.no_screen:
                log.info("--no-screen：候选池已刷新，跳过服务端筛选/发布")
                return 0
            try:
                client.screen(target, args.top, auto=True)
                return 0
            except Exception as e:
                alert(f"{target} 服务端筛选失败：{e}")
                return 1
        log.warning("服务端筛选接口不可用（补丁未部署？HTTP 404），回退本地筛选直推")

    if args.no_screen:
        # 走到这里说明服务端候选接口不可用（或显式 --legacy）。
        # --no-screen 的语义是「绝不发布」，所以本地直推也要一并跳过。
        log.info("--no-screen：跳过本地直推 hotspots（本次不发布任何内容）")
        return 0

    # 旧流程（兜底）：本地选条 → 直接写 hotspots
    picked = select_top(items, args.top)
    log.info("%s 提炼 %d 条，本地入选 %d 条", target, len(items), len(picked))
    if not picked:
        alert(f"{target} 入选 0 条（全部无关），请检查数据源")
        return 2
    from bridge.push_hotspots import push_items
    payloads = [to_hotspot_payload(it, GUANLAN_SUBJECT_ID) for it in picked]
    result = push_items(client, payloads)
    log.info("推送完成：新增 %(created)d，跳过 %(skipped)d，失败 %(failed)d", result)
    if result["failed"]:
        alert(f"{target} 有 {result['failed']} 条推送失败")
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
