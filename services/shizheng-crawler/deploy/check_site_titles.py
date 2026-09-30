# -*- coding: utf-8 -*-
"""检查网站侧 hotspots / shizheng_candidates 的标题质量。只读，不改任何数据。

检查项：
  1. 行数与样例；
  2. 编码乱码（chardet 猜错字符集留下的西里尔/数学符号）；
  3. 站点后缀残留（「×××--理论-中国共产党新闻网」「×××--教育--人民网」）。

DB 口令取 mysql 容器自身的 `MYSQL_ROOT_PASSWORD` 环境变量，脚本里不落任何凭据。
"""
import re
import shlex
import subprocess

CONTAINER = "guanlan-mysql-1"
DATABASE = "guanlan"
TABLES = ("hotspots", "shizheng_candidates")

RE_MOJIBAKE = re.compile(r"[\u0400-\u04ff\u2200-\u22ff\u00c0-\u00ff]{3,}")
RE_SUFFIX = re.compile(r"(人民网|中国共产党新闻网|中共新闻网|人民日报)\s*$")


def mysql(sql: str) -> list:
    """在容器里跑一条 SQL，返回 [(id, title)]。

    必须显式 `--default-character-set=utf8mb4`：容器里 mysql 客户端默认按
    latin1 输出，中文会全变成 `?`（2026-09-30 实测踩到，导致乱码检查形同虚设）。
    """
    out = subprocess.run(
        ["docker", "exec", CONTAINER, "sh", "-c",
         'mysql --default-character-set=utf8mb4 -uroot -p"$MYSQL_ROOT_PASSWORD" '
         + DATABASE + " -N -B -e " + shlex.quote(sql)],
        capture_output=True, text=True, encoding="utf-8", errors="replace")
    if out.returncode != 0:
        print(f"  !! 查询失败：{(out.stderr or '').strip()[:200]}")
        return []
    rows = []
    for line in out.stdout.splitlines():
        if "Using a password" in line or "\t" not in line:
            continue
        rid, _, title = line.partition("\t")
        rows.append((rid.strip(), title.strip()))
    return rows


def main() -> None:
    for table in TABLES:
        rows = mysql(f"SELECT id, title FROM {table} ORDER BY id;")
        bad_enc = [r for r in rows if RE_MOJIBAKE.search(r[1])]
        bad_sfx = [r for r in rows if RE_SUFFIX.search(r[1])]
        print(f"[{table}] 共 {len(rows)} 行；乱码标题 {len(bad_enc)}；带站点后缀 {len(bad_sfx)}")
        show = rows if table == "hotspots" else rows[:8]
        for rid, title in show:
            print(f"    id={rid} {title[:80]}")
        if len(rows) > len(show):
            print(f"    ...（另有 {len(rows) - len(show)} 行）")
        for rid, title in bad_enc[:5]:
            print(f"    !! 乱码 id={rid} {title[:60]}")
        for rid, title in bad_sfx[:8]:
            print(f"    !! 后缀 id={rid} {title[:70]}")
        print()


if __name__ == "__main__":
    main()
