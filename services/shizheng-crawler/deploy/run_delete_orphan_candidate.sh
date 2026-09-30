#!/usr/bin/env bash
# 备份候选行 id=321，然后执行删除脚本。备份为空则中止。
set -uo pipefail

TS=$(date +%Y%m%d-%H%M)
BK="/opt/shizheng/deploy/backup_orphan_candidate_321_${TS}.sql"

docker exec guanlan-mysql-1 sh -c \
  "mysqldump --default-character-set=utf8mb4 -uroot -p\"\$MYSQL_ROOT_PASSWORD\" \
   --no-create-info --skip-extended-insert guanlan shizheng_candidates \
   --where=\"id=321\"" 2>/dev/null > "$BK"

echo "备份：$BK"
echo "INSERT 行数：$(grep -c 'INSERT INTO' "$BK")"
if [ "$(grep -c 'INSERT INTO' "$BK")" -lt 1 ]; then
  echo "备份为空，中止（不执行删除）"
  exit 1
fi

echo "--- 执行删除 ---"
docker exec -i guanlan-mysql-1 sh -c \
  'mysql --default-character-set=utf8mb4 -uroot -p"$MYSQL_ROOT_PASSWORD" guanlan -t' \
  < /opt/shizheng/deploy/delete_orphan_candidate_321.sql 2>&1 | grep -v "Using a password"
