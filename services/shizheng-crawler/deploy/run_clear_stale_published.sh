#!/usr/bin/env bash
# 备份 29 条悬空的 published 候选，然后执行清理脚本。备份为空则中止（不删不改）。
# 用法：bash deploy/run_clear_stale_published.sh
set -uo pipefail

TS=$(date +%Y%m%d-%H%M)
BK="/root/clear_stale_published_backups_${TS}.sql"

# 备份这批行（用 IN 子查询列出 hotspot_id 找不到对应 hotspots 的 published 行）
SQL="SELECT c.* FROM shizheng_candidates c LEFT JOIN hotspots h ON h.id=c.hotspot_id \
WHERE c.status='published' AND h.id IS NULL"
docker exec -e Q="$SQL" guanlan-mysql-1 sh -c \
  'mysql --default-character-set=utf8mb4 -uroot -p"$MYSQL_ROOT_PASSWORD" guanlan -N -B -e "$Q"' \
  2>/dev/null > "$BK"

echo "备份：$BK（$(wc -l < "$BK") 行原始记录）"
if [ "$(wc -l < "$BK")" -lt 1 ]; then
  echo "备份为空，中止（不做任何改动）"
  exit 1
fi
# 同时留一份可回滚的 INSERT dump（按日期整批导，便于误清后还原）
docker exec guanlan-mysql-1 sh -c \
  "mysqldump --default-character-set=utf8mb4 -uroot -p\"\$MYSQL_ROOT_PASSWORD\" \
   --no-create-info --skip-extended-insert guanlan shizheng_candidates \
   --where=\"status='published'\"" 2>/dev/null \
  > "/root/shizheng_candidates_published_${TS}.sql"
echo "整批 published 行 dump：/root/shizheng_candidates_published_${TS}.sql（INSERT $(grep -c 'INSERT INTO' "/root/shizheng_candidates_published_${TS}.sql") 行）"

echo "--- 执行清理 ---"
docker exec -i guanlan-mysql-1 sh -c \
  'mysql --default-character-set=utf8mb4 -uroot -p"$MYSQL_ROOT_PASSWORD" guanlan -t' \
  < /opt/shizheng/deploy/clear_stale_published_candidates.sql 2>&1 | grep -v "Using a password"

echo "--- 复核 ---"
CHECK="SELECT c.publish_date, COUNT(*) AS published,
        SUM(h.id IS NULL) AS 仍悬空
       FROM shizheng_candidates c LEFT JOIN hotspots h ON h.id=c.hotspot_id
       WHERE c.status='published' GROUP BY c.publish_date;"
docker exec -e Q="$CHECK" guanlan-mysql-1 sh -c \
  'mysql --default-character-set=utf8mb4 -uroot -p"$MYSQL_ROOT_PASSWORD" guanlan -t -e "$Q"' \
  2>&1 | grep -v "Using a password"
docker exec -e Q="SELECT status, COUNT(*) n FROM shizheng_candidates GROUP BY status;" \
  guanlan-mysql-1 sh -c \
  'mysql --default-character-set=utf8mb4 -uroot -p"$MYSQL_ROOT_PASSWORD" guanlan -t -e "$Q"' \
  2>&1 | grep -v "Using a password"
