#!/usr/bin/env bash
# P0 验收 §3.3：人为让 AI 请求失败，确认 fallbackReason 与日志都留痕，然后原样恢复配置。
# 只改 baseUrl 一个字段（apiKey 传掩码值即保留原 key），跑完立刻还原。
set -uo pipefail

API=http://127.0.0.1:8080/api/v1
DATE=2026-09-30
PW=$(cat /root/.shizheng-bot-pw)

# 先原样备份 AI 配置（含真实 key，只落盘不打印），万一还原失败可直接写回。
# 直接 dump 整张 admin_settings（就几行）：`key` 是保留字，反引号经两层 shell 会被吃掉，
# 实测那样备出 0 字节。
BAK="/root/shizheng_ai_config.bak.$(date +%Y%m%d-%H%M%S)"
docker exec guanlan-mysql-1 sh -c \
  'mysqldump --default-character-set=utf8mb4 -uroot -p"$MYSQL_ROOT_PASSWORD" \
   --no-create-info --skip-extended-insert guanlan admin_settings' 2>/dev/null > "$BAK"
echo "配置备份：$BAK（$(wc -c < "$BAK") 字节，INSERT $(grep -c 'INSERT INTO' "$BAK") 行）"
if [ "$(grep -c 'INSERT INTO' "$BAK")" -lt 1 ]; then
  echo "备份为空，中止（不改动线上 AI 配置）"
  exit 1
fi
[ "${1:-}" = "--backup-only" ] && { echo "仅备份，未改动任何配置"; exit 0; }

T=$(curl -sS -m 20 -X POST "$API/auth/login" -H 'Content-Type: application/json' \
  -d "{\"email\":\"shizheng-bot@guanlan.local\",\"password\":\"$PW\"}" \
  | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["token"])')
H="Authorization: Bearer $T"

echo "=== 原始配置（provider/model/subjectId/topN/baseUrl）==="
ORIG=$(curl -sS -m 20 "$API/admin/shizheng/config" -H "$H")
echo "$ORIG" | python3 -c 'import sys,json;d=json.load(sys.stdin)["data"];print({k:d[k] for k in ("provider","baseUrl","model","subjectId","topN","hasKey")})'
PROVIDER=$(echo "$ORIG" | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["provider"])')
MODEL=$(echo "$ORIG" | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["model"])')
SUB=$(echo "$ORIG" | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["subjectId"])')
TOPN=$(echo "$ORIG" | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["topN"])')

echo
echo "=== 改成不可达 baseUrl 并干跑筛选 ==="
curl -sS -m 20 -X PUT "$API/admin/shizheng/config" -H "$H" -H 'Content-Type: application/json' \
  -d "{\"provider\":\"$PROVIDER\",\"baseUrl\":\"https://guanlan-p0-probe.invalid\",\"model\":\"$MODEL\",\"subjectId\":$SUB,\"topN\":$TOPN,\"apiKey\":\"****\"}" \
  | python3 -c 'import sys,json;print("baseUrl →",json.load(sys.stdin)["data"]["baseUrl"])'

curl -sS -m 120 -X POST "$API/admin/shizheng/screen" -H "$H" -H 'Content-Type: application/json' \
  -d "{\"date\":\"$DATE\",\"top\":10,\"auto\":false,\"strategy\":\"ai\"}" \
  | python3 -c 'import sys,json;d=json.load(sys.stdin).get("data") or {};print("fallback:",d.get("fallback")," reason:",d.get("fallbackReason")," selected:",len(d.get("selected") or []))'

echo
echo "=== 容器日志里的降级留痕 ==="
docker logs --since 2m guanlan-api-1 2>&1 | grep -i "降级" | tail -3

echo
echo "=== 审计记录（最近一条 shizheng.screen）==="
docker exec guanlan-mysql-1 sh -c "mysql --default-character-set=utf8mb4 -uroot -p\"\$MYSQL_ROOT_PASSWORD\" guanlan -N -B -e \"SELECT action, detail, created_at FROM admin_audit_logs WHERE action='shizheng.screen' ORDER BY id DESC LIMIT 2\"" 2>/dev/null

echo
echo "=== 恢复原 baseUrl 并复测连通 ==="
curl -sS -m 20 -X PUT "$API/admin/shizheng/config" -H "$H" -H 'Content-Type: application/json' \
  -d "{\"provider\":\"$PROVIDER\",\"baseUrl\":\"$(echo "$ORIG" | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["baseUrl"])')\",\"model\":\"$MODEL\",\"subjectId\":$SUB,\"topN\":$TOPN,\"apiKey\":\"****\"}" \
  | python3 -c 'import sys,json;print("baseUrl →",json.load(sys.stdin)["data"]["baseUrl"])'
curl -sS -m 60 -X POST "$API/admin/shizheng/config/test" -H "$H" -H 'Content-Type: application/json' -d "{}"
echo
