#!/usr/bin/env bash
# 备份 2026-09-29 候选池里 6 条页脚噪声候选，然后执行删除脚本。
# 用法：bash deploy/run_delete_junk_candidates.sh
set -uo pipefail

TS=$(date +%Y%m%d-%H%M)
BK="/opt/shizheng/deploy/backup_junk_candidates_${TS}.sql"
WHERE="publish_date='2026-09-29' AND title LIKE '每日%丨%'"

docker exec guanlan-mysql-1 sh -c \
  "mysqldump --default-character-set=utf8mb4 -uroot -p\"\$MYSQL_ROOT_PASSWORD\" \
   --no-create-info --skip-extended-insert guanlan shizheng_candidates \
   --where=\"$WHERE\"" 2>/dev/null > "$BK"

echo "备份：$BK"
echo "INSERT 行数：$(grep -c 'INSERT INTO' "$BK")"
if [ "$(grep -c 'INSERT INTO' "$BK")" -lt 1 ]; then
  echo "备份为空，中止（不执行删除）"
  exit 1
fi

echo "--- 执行删除 ---"
docker exec -i guanlan-mysql-1 sh -c \
  'mysql --default-character-set=utf8mb4 -uroot -p"$MYSQL_ROOT_PASSWORD" guanlan -t' \
  < /opt/shizheng/deploy/delete_junk_candidates_20260929.sql 2>&1 | grep -v "Using a password"
