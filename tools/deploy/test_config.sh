#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

DEPLOY_DIR="$ROOT/tools/deploy"
SCRIPTS=(common.sh deploy.sh healthcheck.sh test_config.sh)

fail() { echo "FAIL: $1"; exit 1; }

# --- 静态契约：所有部署脚本必须存在且通过 bash -n ---
for script in "${SCRIPTS[@]}"; do
  file="$DEPLOY_DIR/$script"
  [ -f "$file" ] || fail "missing deployment script $script"
  bash -n "$file" || fail "syntax error in $script"
done

# --- deploy.sh 契约断言 ---
grep -q 'docker compose version' "$DEPLOY_DIR/deploy.sh" \
  || fail "deploy.sh must validate docker compose version"
grep -q '2 GB free disk' "$DEPLOY_DIR/deploy.sh" \
  || fail "deploy.sh must check at least 2 GB free disk"
grep -q '3.5 GB total RAM' "$DEPLOY_DIR/deploy.sh" \
  || fail "deploy.sh must check at least 3.5 GB total RAM"
grep -q 'group/other readable' "$DEPLOY_DIR/deploy.sh" \
  || fail "deploy.sh must warn when env file is group/other readable"
grep -q 'Validate Compose configuration before building' "$DEPLOY_DIR/deploy.sh" \
  || fail "deploy.sh must validate Compose config before build"
grep -q '30 attempts' "$DEPLOY_DIR/deploy.sh" \
  || fail "deploy.sh must poll health with bounded attempts"
grep -q '2-second' "$DEPLOY_DIR/deploy.sh" \
  || fail "deploy.sh must poll health with 2-second intervals"

# --- healthcheck.sh 契约断言 ---
grep -q '127.0.0.1' "$DEPLOY_DIR/healthcheck.sh" \
  || fail "healthcheck.sh must call only 127.0.0.1"
grep -q '/api/v1/health' "$DEPLOY_DIR/healthcheck.sh" \
  || fail "healthcheck.sh must validate /api/v1/health 200"
grep -q '/api/v1/auth/me' "$DEPLOY_DIR/healthcheck.sh" \
  || fail "healthcheck.sh must validate unauthenticated /api/v1/auth/me 401"
grep -q '200' "$DEPLOY_DIR/healthcheck.sh" \
  || fail "healthcheck.sh must assert HTTP 200 for health and home"
grep -q '401' "$DEPLOY_DIR/healthcheck.sh" \
  || fail "healthcheck.sh must assert HTTP 401 for unauthenticated /api/v1/auth/me"

# --- Docker 相关断言（Task 2 保留） ---
if ! command -v docker >/dev/null 2>&1; then
  echo "PASS: deployment script contracts valid (docker unavailable; compose assertions skipped)"
  exit 0
fi

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

RENDERED="$(docker compose -f compose.production.yml --env-file "$TMP_ENV" config)"

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

echo "PASS: deployment scripts and production compose config valid"