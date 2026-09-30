#!/usr/bin/env bash
# 只读：定位噪声命中的具体位置，区分「payload 里的 source 元数据」与「正文页脚残留」。
set -uo pipefail

run() { docker exec -e Q="$1" guanlan-mysql-1 sh -c \
  'mysql --default-character-set=utf8mb4 -uroot -p"$MYSQL_ROOT_PASSWORD" guanlan -t -e "$Q"' \
  2>&1 | grep -v "Using a password"; }

# 单值查询（-N -B，便于在 shell 里直接取数）
run_n() { docker exec -e Q="$1" guanlan-mysql-1 sh -c \
  'mysql --default-character-set=utf8mb4 -uroot -p"$MYSQL_ROOT_PASSWORD" guanlan -N -B -e "$Q"' \
  2>&1 | grep -v "Using a password"; }

echo "=== 2026-09-29 候选（payload）与已发布热点（summary/html）噪声总量 ==="
run "
SELECT 'candidates' AS t, COUNT(*) AS total,
       SUM(payload LIKE '%许可证%' OR payload LIKE '%版权所有%'
           OR payload LIKE '%责编：%' OR payload LIKE '%来源：%'
           OR payload LIKE '%showPlayer%') AS dirty
FROM shizheng_candidates WHERE publish_date='2026-09-29'
UNION ALL
SELECT 'hotspots', COUNT(*),
       SUM(summary LIKE '%许可证%' OR summary LIKE '%版权所有%'
           OR summary LIKE '%责编：%' OR summary LIKE '%来源：%'
           OR summary LIKE '%showPlayer%'
           OR html LIKE '%许可证%' OR html LIKE '%版权所有%'
           OR html LIKE '%showPlayer%')
FROM hotspots WHERE id BETWEEN 316 AND 325;
"

echo "=== 已发布热点里命中噪声的那条（id 316-325）==="
run "SELECT id, title FROM hotspots
     WHERE id BETWEEN 316 AND 325
       AND (summary LIKE '%许可证%' OR summary LIKE '%版权所有%'
            OR summary LIKE '%责编：%' OR summary LIKE '%来源：%'
            OR summary LIKE '%showPlayer%'
            OR html LIKE '%许可证%' OR html LIKE '%版权所有%'
            OR html LIKE '%showPlayer%');"

echo
echo "=== 各噪声特征在 2026-09-29 候选 payload 里的命中数 ==="
for pat in '许可证' '版权所有' '责编：' '来源：' 'showPlayer'; do
  n=$(run_n "SELECT COUNT(*) FROM shizheng_candidates
           WHERE publish_date='2026-09-29' AND payload LIKE '%$pat%';")
  printf '%-12s %s\n' "$pat" "$n"
done

echo
echo "=== 候选中命中噪声的行（前 5）==="
run "SELECT id, LEFT(title,40) AS title FROM shizheng_candidates
     WHERE publish_date='2026-09-29' AND payload LIKE '%来源：%' LIMIT 5;"

