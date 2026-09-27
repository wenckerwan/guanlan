#!/usr/bin/env bash
# 用法：./tools/deploy/deploy.sh [env-file]
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/common.sh"
resolve_env_file "${1:-}"

log()  { echo "[deploy] $*"; }
fail() { echo "FAIL: $1" >&2; exit 1; }

# 1. 前置：docker compose version
if ! docker compose version >/dev/null 2>&1; then
  fail "docker compose version check failed; install Docker Engine and Compose v2"
fi
log "docker compose version: $(docker compose version)"

# 2. 环境文件存在
[ -f "$ENV_FILE" ] || fail "env file not found: $ENV_FILE"

# 3. 至少 2 GB 可用磁盘
if command -v df >/dev/null 2>&1; then
  FREE_DISK_KB="$(df -Pk "$REPO_ROOT" | awk 'NR==2 {print $4}')"
  if [ -z "$FREE_DISK_KB" ] || [ "$FREE_DISK_KB" -lt 2097152 ]; then
    fail "at least 2 GB free disk required (have ${FREE_DISK_KB:-unknown} KB)"
  fi
  log "free disk check passed (at least 2 GB free disk)"
else
  log "SKIP: df unavailable; free disk not checked"
fi

# 4. 至少 3.5 GB 总内存
if command -v free >/dev/null 2>&1; then
  TOTAL_RAM_MB="$(free -m | awk '/^Mem:/ {print $2}')"
  if [ -z "$TOTAL_RAM_MB" ] || [ "$TOTAL_RAM_MB" -lt 3584 ]; then
    fail "at least 3.5 GB total RAM required (have ${TOTAL_RAM_MB:-unknown} MB)"
  fi
  log "total RAM check passed (at least 3.5 GB total RAM)"
else
  log "SKIP: free unavailable; total RAM not checked"
fi

# 5. 环境文件权限告警：group/other 可读时警告（不阻断，密钥文件应 chmod 600）
if [ "$(uname -s)" != "Darwin" ] && command -v stat >/dev/null 2>&1; then
  ENV_MODE="$(stat -c '%a' "$ENV_FILE" 2>/dev/null || true)"
  if [ -n "$ENV_MODE" ] && [[ "$ENV_MODE" =~ ^[0-7]+$ ]] && [ "$((8#$ENV_MODE & 077))" -ne 0 ]; then
    echo "[deploy] WARNING: env file is group/other readable ($ENV_MODE); run: chmod 600 $ENV_FILE" >&2
  fi
fi

# 6. Validate Compose configuration before building
log "Validate Compose configuration before building"
if ! compose config --quiet; then
  fail "compose config validation failed"
fi

# 7. 构建并启动
compose build
compose up -d --remove-orphans

# 8. 有界轮询健康检查：最多 30 次、每次间隔 2 秒
log "waiting for health check (up to 30 attempts, 2-second intervals)"
ATTEMPTS=30
INTERVAL=2
HEALTHY=0
for attempt in $(seq 1 "$ATTEMPTS"); do
  if "$SCRIPT_DIR/healthcheck.sh" "$ENV_FILE"; then
    HEALTHY=1
    break
  fi
  [ "$attempt" -lt "$ATTEMPTS" ] && sleep "$INTERVAL"
done

[ "$HEALTHY" -eq 1 ] || fail "health check failed after $ATTEMPTS attempts"

log "deployment healthy"