#!/usr/bin/env bash
# 只读：打印 2026-09-29 这批热点（id 316-325）的 summary 开头，
# 看有多少条以「日期时间 来源：xxx」版式行开头。
set -uo pipefail

docker exec -e Q="SELECT id, REPLACE(LEFT(summary, 70), '\n', ' ⏎ ')
FROM hotspots WHERE id BETWEEN 316 AND 325 ORDER BY id;" guanlan-mysql-1 sh -c \
  'mysql --default-character-set=utf8mb4 -uroot -p"$MYSQL_ROOT_PASSWORD" guanlan -N -B -e "$Q"' \
  2>&1 | grep -v "Using a password"
