#!/usr/bin/env bash
# 用法：./tools/deploy/healthcheck.sh [env-file]
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/common.sh"
resolve_env_file "${1:-}"

# 失败时输出最近的 Compose 日志并以非零退出。
fail() {
  echo "FAIL: $1" >&2
  if compose ps >/dev/null 2>&1; then
    echo "--- recent logs (last 30 minutes) ---" >&2
    compose logs --since 30m --tail 80 >&2 2>/dev/null || true
  fi
  exit 1
}

# 1. 读取 APP_PORT
[ -f "$ENV_FILE" ] || fail "env file not found: $ENV_FILE"
APP_PORT="$(sed -n 's/^[[:space:]]*APP_PORT[[:space:]]*=[[:space:]]*\([^[:space:]#]*\).*$/\1/p' "$ENV_FILE" | tail -n 1)"
APP_PORT="${APP_PORT:-8080}"
BASE="http://127.0.0.1:$APP_PORT"

# 2. Compose 服务健康状态
compose ps >/dev/null 2>&1 || fail "compose ps failed; is the stack deployed?"
if compose ps --format json | grep -q '"Health": *"unhealthy"'; then
  compose ps >&2
  fail "one or more Compose services are unhealthy"
fi

# 3. HTTP 断言：只访问 127.0.0.1
require_status() {
  local path="$1"
  local expected="$2"
  local label="$3"
  local status
  if command -v curl >/dev/null 2>&1; then
    status="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 10 "$BASE$path")" || true
  elif command -v wget >/dev/null 2>&1; then
    status="$(wget -q -O /dev/null -S --timeout=10 "$BASE$path" 2>&1 | sed -n '1{s/.*HTTP\/[0-9.]* \([0-9][0-9][0-9]\).*/\1/p}' | tail -n 1)" || true
  else
    fail "neither curl nor wget available"
  fi
  [ "$status" = "$expected" ] || fail "$label expected HTTP $expected, got ${status:-no-response}"
  echo "[health] $label -> HTTP $status"
}

require_status "/api/v1/health" "200" "/api/v1/health"
require_status "/" "200" "home /"
require_status "/api/v1/auth/me" "401" "unauthenticated /api/v1/auth/me"

# 4. 全部通过
echo "[health] OK"