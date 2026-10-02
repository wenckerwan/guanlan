#!/usr/bin/env bash
# 只读：摸清时政真题池的列与 super_name 分布，为相似度打分器定语料口径。
set -uo pipefail

q() { docker exec -e Q="$1" guanlan-mysql-1 sh -c \
  'mysql --default-character-set=utf8mb4 -uroot -p"$MYSQL_ROOT_PASSWORD" guanlan -t -e "$Q"' \
  2>&1 | grep -v "Using a password"; }

echo "=== questions 列 ==="
q "SHOW COLUMNS FROM questions;"

echo
echo "=== super_name 分布（题量 Top 15）==="
q "SELECT super_name, COUNT(*) n FROM questions GROUP BY super_name ORDER BY n DESC LIMIT 15;"

echo
echo "=== 时政两类题池合计 + 年份分布 ==="
q "SELECT year, COUNT(*) n FROM questions
   WHERE super_name IN ('习思想与形策','形势与政策以及当代世界经济与政治')
   GROUP BY year ORDER BY year;"

echo
echo "=== 相似度可用字段的填充率（时政题池）==="
q "SELECT COUNT(*) total,
   SUM(stem IS NOT NULL AND stem<>'') has_stem,
   SUM(material IS NOT NULL AND material<>'') has_material,
   SUM(analysis IS NOT NULL AND analysis<>'') has_analysis,
   SUM(kaodian IS NOT NULL AND kaodian<>'') has_kaodian,
   SUM(trap IS NOT NULL AND trap<>'') has_trap,
   SUM(options IS NOT NULL AND options<>'') has_options
   FROM questions
   WHERE super_name IN ('习思想与形策','形势与政策以及当代世界经济与政治');"

echo
echo "=== 两条样例（看字段实际形态）==="
q "SELECT id, year, super_name, LEFT(stem,60) stem, LEFT(kaodian,40) kaodian,
   LEFT(trap,40) trap, LEFT(analysis,60) analysis
   FROM questions
   WHERE super_name IN ('习思想与形策','形势与政策以及当代世界经济与政治') LIMIT 2\G" 2>/dev/null || \
q "SELECT id, year, super_name, LEFT(stem,60) stem, LEFT(kaodian,40) kaodian,
   LEFT(trap,40) trap FROM questions
   WHERE super_name IN ('习思想与形策','形势与政策以及当代世界经济与政治') LIMIT 2;"
