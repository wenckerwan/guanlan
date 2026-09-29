# -*- coding: utf-8 -*-
"""主入口：python -m src.main --mode daily|backfill|stats"""
import argparse, logging, sys, os
from datetime import date, timedelta
import requests

from .core import db
from .sources import rmrb, people_sitemap
from .refine import extractor
from .core import filter as flt
from .render import md as mdrender
from .config import LOG_DIR


def setup_logging(verbose=False):
    LOG_DIR.mkdir(parents=True, exist_ok=True)
    handlers = [logging.StreamHandler(sys.stdout),
                logging.FileHandler(LOG_DIR / f"crawl_{date.today():%Y%m}.log",
                                    encoding="utf-8")]
    logging.basicConfig(
        level=logging.DEBUG if verbose else logging.INFO,
        format="%(asctime)s %(levelname)-7s %(name)-14s %(message)s",
        datefmt="%Y-%m-%d %H:%M:%S",
        handlers=handlers,
    )


def make_session():
    s = requests.Session()
    s.headers.update({"Connection": "keep-alive"})
    return s


def cmd_daily(args):
    """每天增量：抓昨天的报纸 + 人民网 sitemap"""
    db.init_db()
    rid = db.start_run()
    added = 0
    msg = []
    sess = make_session()
    try:
        y = date.today() - timedelta(days=args.lag)
        n1 = rmrb.crawl_day(y, sess)
        added += n1
        msg.append(f"rmrb={n1}")
        logging.info("人民日报 %s 新增 %d 篇", y, n1)

        if not args.no_people:
            n2 = people_sitemap.crawl(y, sess)
            added += n2
            msg.append(f"people={n2}")
            logging.info("人民网新增 %d 篇", n2)

        db.finish_run(rid, True, added, "; ".join(msg))
    except Exception as e:
        db.finish_run(rid, False, added, f"ERROR {type(e).__name__}: {e}")
        logging.exception("daily 失败")
        raise
    logging.info("本次新增合计 %d 篇", added)
    return added


def cmd_backfill(args):
    """补历史：--start 2026-09-01 --end 2026-09-27"""
    db.init_db()
    rid = db.start_run()
    sess = make_session()
    start = date.fromisoformat(args.start)
    end   = date.fromisoformat(args.end) if args.end else date.today()
    try:
        n = rmrb.crawl_range(start, end, sess, pages=args.pages)
        db.finish_run(rid, True, n, f"backfill {start}~{end}")
        logging.info("补抓完成，新增 %d 篇", n)
    except Exception as e:
        db.finish_run(rid, False, 0, f"ERROR {e}")
        raise



def cmd_refine(args):
    """提炼未处理的文章并生成 markdown"""
    db.init_db()
    rows = db.unrefined(limit=args.limit)
    if not rows:
        logging.info("没有待提炼的文章")
        return
    # 前置关键词过滤：降低 LLM 成本，避免副刊类内容进入
    kept, skipped = [], []
    for r in rows:
        if flt.is_relevant(r.get("title", ""), r.get("body") or ""):
            kept.append(r)
        else:
            skipped.append(r["id"])
    logging.info("关键词过滤：相关 %d 篇，跳过 %d 篇", len(kept), len(skipped))
    db.mark_refined(skipped)
    if not kept:
        logging.info("过滤后无可提炼材料")
        return
    rows = kept

    items, done_ids = [], []
    for r in rows:
        try:
            res = extractor.refine(r)
            if res.get("skip"):
                done_ids.append(r["id"])
                continue
            res.setdefault("title", r.get("title", ""))
            res["date"] = r.get("publish_date", "")
            res["source"] = r.get("source", "")
            res["channel"] = r.get("channel", "")
            items.append(res)
            done_ids.append(r["id"])
            logging.info("  提炼 %s", (r.get("title") or "")[:44])
        except Exception as e:
            logging.warning("  提炼失败 %s：%s", (r.get("title") or "")[:30], str(e)[:70])
    db.mark_refined(done_ids)
    if not items:
        logging.info("无可输出材料")
        return
    dates = sorted({i.get("date", "") for i in items if i.get("date")})
    dr = f"{dates[0]} — {dates[-1]}" if dates else ""
    content = mdrender.render_markdown(
        items, args.title, dr,
        {"sources": "人民网 / 人民日报电子版（自动抓取）"})
    out = mdrender.write_out(content, args.out)
    logging.info("完成：%s", out)


def cmd_stats(args):
    db.init_db()
    s = db.stats()
    print(f"库内文章总数: {s['total']}")
    print(f"最新发布日期: {s['latest']}")
    for r in s["by_source"]:
        print(f"  {r['source']:8s} {r['n']}")


def main():
    ap = argparse.ArgumentParser(description="人民网时政抓取")
    ap.add_argument("--mode", choices=["daily", "backfill", "refine", "stats"], default="daily")
    ap.add_argument("--start", help="backfill 起始日期 YYYY-MM-DD")
    ap.add_argument("--end",   help="backfill 结束日期 YYYY-MM-DD")
    ap.add_argument("--pages", type=int, default=None, help="强制版面数（默认自动探测）")
    ap.add_argument("--lag",   type=int, default=1, help="daily 抓几天前（默认1=昨天）")
    ap.add_argument("--no-people", action="store_true", help="跳过人民网 sitemap")
    ap.add_argument("--limit", type=int, default=60, help="refine 最多处理篇数")
    ap.add_argument("--title", default="时政考点（自动生成）", help="refine 输出标题")
    ap.add_argument("--out", default="时政考点_自动生成.md", help="输出文件名")
    ap.add_argument("-v", "--verbose", action="store_true")
    args = ap.parse_args()
    setup_logging(args.verbose)
    if args.mode == "daily":
        cmd_daily(args)
    elif args.mode == "refine":
        cmd_refine(args)
    elif args.mode == "backfill":
        if not args.start:
            ap.error("backfill 需要 --start")
        cmd_backfill(args)
    else:
        cmd_stats(args)


if __name__ == "__main__":
    main()
