# -*- coding: utf-8 -*-
"""SQLite 存储层：去重表 + 文章表 + 运行记录"""
import sqlite3, hashlib
from datetime import datetime
from contextlib import contextmanager
from ..config import DB_PATH

SCHEMA = """
CREATE TABLE IF NOT EXISTS articles (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    url_hash     TEXT UNIQUE NOT NULL,   -- URL sha256，天然幂等
    url          TEXT NOT NULL,
    content_id   TEXT,                   -- 电子版稿件 ID，如 30183278
    source       TEXT NOT NULL,          -- rmrb / people
    channel      TEXT,                   -- 版面/频道，如 第01版、politics
    title        TEXT,
    publish_date TEXT,                   -- YYYY-MM-DD
    body         TEXT,                   -- 正文纯文本
    raw_path     TEXT,                   -- 原始 HTML 存档路径
    keywords     TEXT,                   -- 命中的关键词，逗号分隔
    refined      INTEGER DEFAULT 0,      -- 是否已提炼
    fetched_at   TEXT
);

CREATE TABLE IF NOT EXISTS runs (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    started_at TEXT, finished_at TEXT,
    ok         INTEGER, new_articles INTEGER,
    message    TEXT
);

CREATE INDEX IF NOT EXISTS idx_articles_date ON articles(publish_date);
CREATE INDEX IF NOT EXISTS idx_articles_src  ON articles(source, channel);
"""

def url_hash(u: str) -> str:
    return hashlib.sha256(u.encode("utf-8")).hexdigest()

@contextmanager
def conn():
    DB_PATH.parent.mkdir(parents=True, exist_ok=True)
    c = sqlite3.connect(DB_PATH, timeout=30)
    c.row_factory = sqlite3.Row
    try:
        yield c
        c.commit()
    finally:
        c.close()

def init_db():
    with conn() as c:
        c.executescript(SCHEMA)

def seen(url: str) -> bool:
    with conn() as c:
        r = c.execute("SELECT 1 FROM articles WHERE url_hash=?",
                      (url_hash(url),)).fetchone()
        return r is not None

def save_article(url, source, title, publish_date, body, raw_path="",
                 content_id="", channel="", keywords=""):
    """插入一篇文章；已存在返回 False（幂等）"""
    try:
        with conn() as c:
            c.execute(
                """INSERT INTO articles
                   (url_hash,url,content_id,source,channel,title,publish_date,
                    body,raw_path,keywords,fetched_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?)""",
                (url_hash(url), url, content_id, source, channel, title,
                 publish_date, body, raw_path, keywords,
                 datetime.now().isoformat(timespec="seconds")),
            )
        return True
    except sqlite3.IntegrityError:
        return False

def start_run() -> int:
    with conn() as c:
        cur = c.execute("INSERT INTO runs (started_at,ok) VALUES (?,0)",
                        (datetime.now().isoformat(timespec="seconds"),))
        return cur.lastrowid

def finish_run(rid: int, ok: bool, new_count: int, message: str = ""):
    with conn() as c:
        c.execute("UPDATE runs SET finished_at=?,ok=?,new_articles=?,message=? WHERE id=?",
                  (datetime.now().isoformat(timespec="seconds"),
                   1 if ok else 0, new_count, message[:500], rid))

def unrefined(limit: int = 50):
    with conn() as c:
        return [dict(r) for r in c.execute(
            "SELECT * FROM articles WHERE refined=0 ORDER BY publish_date DESC LIMIT ?",
            (limit,)).fetchall()]

def mark_refined(ids):
    if not ids:
        return
    with conn() as c:
        c.executemany("UPDATE articles SET refined=1 WHERE id=?", [(i,) for i in ids])

def stats():
    with conn() as c:
        total  = c.execute("SELECT COUNT(*) FROM articles").fetchone()[0]
        by_src = c.execute("SELECT source, COUNT(*) n FROM articles GROUP BY source").fetchall()
        latest = c.execute("SELECT MAX(publish_date) FROM articles").fetchone()[0]
        return {"total": total, "by_source": [dict(r) for r in by_src], "latest": latest}
