#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

TMP_ENV="$(mktemp)"
trap 'rm -f "$TMP_ENV"' EXIT

cat > "$TMP_ENV" <<ENVEOF
APP_PORT=18080
APP_KEY=test-app-key-0123456789abcdef0123456789abcdef
APP_ENV=production
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=guanlan_test
DB_USERNAME=guanlan_test
DB_PASSWORD=test-db-password-0123456789
MYSQL_ROOT_PASSWORD=test-root-password-0123456789
REDIS_HOST=redis
REDIS_PORT=6379
ADMIN_EMAIL=admin@example.com
ADMIN_PASSWORD=test-admin-password-0123456789
ADMIN_DISPLAY_NAME=测试管理员
ENVEOF

if ! command -v docker >/dev/null 2>&1; then
  echo "SKIP: docker not available; cannot run docker compose config"
  exit 0
fi

RENDERED="$(docker compose -f compose.production.yml --env-file "$TMP_ENV" config)"

fail() { echo "FAIL: $1"; exit 1; }

# 端口绑定：compose config 会把短语法规范化为 host_ip/published/target 字段
if echo "$RENDERED" | grep -q '127.0.0.1:18080:80'; then
  :
elif echo "$RENDERED" | grep -q 'host_ip: 127.0.0.1' && echo "$RENDERED" | grep -q 'published: "18080"' && echo "$RENDERED" | grep -q 'target: 80'; then
  :
else
  fail "missing 127.0.0.1:18080:80 binding"
fi

if echo "$RENDERED" | grep -Eqi 'change-me|guanlan2027'; then
  fail "forbidden placeholder password found"
fi
if echo "$RENDERED" | grep -Eqi 'meilisearch'; then
  fail "meilisearch must not appear"
fi
echo "$RENDERED" | grep -q 'restart: unless-stopped' || fail "missing restart: unless-stopped"
echo "$RENDERED" | grep -q 'max-size: 10m' || fail "missing max-size: 10m"

DOCKERFILE=apps/web/Dockerfile
FROM_COUNT="$(grep -c '^FROM ' "$DOCKERFILE")"
[ "$FROM_COUNT" -ge 2 ] || fail "Dockerfile must have two FROM stages"
grep -q '^USER node' "$DOCKERFILE" || fail "Dockerfile must have non-root USER line"

echo "PASS: production compose config valid"