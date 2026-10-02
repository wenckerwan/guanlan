#!/usr/bin/env bash
# P0 诊断：备份 2026-09-30 候选行 → 干跑筛选（auto=false，不发布）→ 打印返回结构。
# 目的：确认 AI 路径是否可用、降级是否可复现。不改动 hotspots。
set -uo pipefail

DATE="${1:-2026-09-30}"
TS=$(date +%Y%m%d-%H%M)
BK="/root/shizheng_candidates_${DATE}_${TS}.sql"

docker exec guanlan-mysql-1 sh -c \
  "mysqldump --default-character-set=utf8mb4 -uroot -p\"\$MYSQL_ROOT_PASSWORD\" \
   --no-create-info --skip-extended-insert guanlan shizheng_candidates \
   --where=\"publish_date='$DATE'\"" 2>/dev/null > "$BK"
echo "备份：$BK  INSERT 行数：$(grep -c 'INSERT INTO' "$BK")"

PW=$(cat /root/.shizheng-bot-pw)
T=$(curl -sS -m 20 -X POST http://127.0.0.1:8080/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d "{\"email\":\"shizheng-bot@guanlan.local\",\"password\":\"$PW\"}" \
  | python3 -c 'import sys,json;print(json.load(sys.stdin).get("data",{}).get("token",""))')
if [ ${#T} -lt 10 ]; then echo "登录失败"; exit 1; fi

echo "=== 干跑筛选（auto=false）==="
curl -sS -m 300 -X POST "http://127.0.0.1:8080/api/v1/admin/shizheng/screen" \
  -H "Authorization: Bearer $T" -H 'Content-Type: application/json' \
  -d "{\"date\":\"$DATE\",\"top\":10,\"auto\":false}" \
  | python3 -c '
import sys, json
d = (json.load(sys.stdin).get("data")) or {}
print("total:", d.get("total"), " fallback:", d.get("fallback"),
      " selected:", len(d.get("selected") or []), " published:", d.get("published"))
for s in (d.get("selected") or [])[:12]:
    print("  ", s)'
