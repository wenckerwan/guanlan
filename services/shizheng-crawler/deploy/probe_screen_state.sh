#!/usr/bin/env bash
# 只读：看 api 容器依赖 + 2026-09-30 候选的筛选结果分布。
set -uo pipefail

echo "=== 容器内 PHP/Guzzle ==="
docker exec guanlan-api-1 sh -c 'php -v | head -1; ls /opt/guanlan/vendor/guzzlehttp 2>/dev/null | head -3; echo "DB_HOST=$DB_HOST"'

echo
echo "=== 2026-09-30 候选：ai_reason / ai_priority 分布 ==="
SQL="SELECT ai_reason, ai_priority, COUNT(*) AS n
FROM shizheng_candidates WHERE publish_date='2026-09-30'
GROUP BY ai_reason, ai_priority ORDER BY n DESC;"
docker exec -e Q="$SQL" guanlan-mysql-1 sh -c \
  'mysql --default-character-set=utf8mb4 -uroot -p"$MYSQL_ROOT_PASSWORD" guanlan -t -e "$Q"' \
  2>&1 | grep -v "Using a password"

echo
echo "=== 当天状态分布 ==="
SQL2="SELECT status, COUNT(*) AS n FROM shizheng_candidates
WHERE publish_date='2026-09-30' GROUP BY status;"
docker exec -e Q="$SQL2" guanlan-mysql-1 sh -c \
  'mysql --default-character-set=utf8mb4 -uroot -p"$MYSQL_ROOT_PASSWORD" guanlan -t -e "$Q"' \
  2>&1 | grep -v "Using a password"

echo
echo "=== 已发布热点（09-30）==="
SQL3="SELECT h.id, LEFT(h.title,44) AS title, h.created_at FROM hotspots h
WHERE h.source_file='auto:shizheng-crawler' AND h.period='2026-09-30' ORDER BY h.id;"
docker exec -e Q="$SQL3" guanlan-mysql-1 sh -c \
  'mysql --default-character-set=utf8mb4 -uroot -p"$MYSQL_ROOT_PASSWORD" guanlan -t -e "$Q"' \
  2>&1 | grep -v "Using a password"
