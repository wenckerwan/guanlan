#!/usr/bin/env bash
# 把云端时政候选池导出为 TSV（供本地同步/出题用）。只读，不改动任何数据。
# 用法：bash deploy/export_shizheng_candidates.sh [起始日期]
set -uo pipefail

SINCE="${1:-2026-01-01}"
OUT="/tmp/shizheng_candidates.tsv"

SQL="SELECT c.publish_date, c.id, c.title, c.source, c.channel, c.url,
             ROUND(c.exam_sim,4), ROUND(c.exam_affinity,4),
             IFNULL(c.exam_matches,'[]'), IFNULL(c.status,'pending'), c.payload
      FROM shizheng_candidates c
      WHERE c.publish_date >= '$SINCE'
      ORDER BY c.publish_date, c.exam_sim DESC, c.id;"

docker exec -e Q="$SQL" guanlan-mysql-1 sh -c \
  'mysql --default-character-set=utf8mb4 -uroot -p"$MYSQL_ROOT_PASSWORD" guanlan -N -B -e "$Q"' \
  2>/dev/null > "$OUT"

echo "导出 $(wc -l < "$OUT") 行 → $OUT"
echo "日期分布："
cut -f1 "$OUT" | sort | uniq -c
