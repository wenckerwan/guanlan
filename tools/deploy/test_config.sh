#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

DEPLOY_DIR="$ROOT/tools/deploy"
SCRIPTS=(common.sh deploy.sh healthcheck.sh backup.sh restore.sh update.sh test_config.sh)

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

# --- backup.sh 契约断言 ---
grep -q 'mysqldump --single-transaction --routines --triggers' "$DEPLOY_DIR/backup.sh" \
  || fail "backup.sh must use mysqldump --single-transaction --routines --triggers"
for member in database.sql VERSION git-sha.txt dataset-manifest.json; do
  grep -q "$member" "$DEPLOY_DIR/backup.sh" \
    || fail "backup.sh must include archive member $member"
done
grep -q 'exclude .env.production' "$DEPLOY_DIR/backup.sh" \
  || fail "backup.sh must document that .env.production is excluded"
if grep -qE 'cp[[:space:]]+"?\$ENV_FILE' "$DEPLOY_DIR/backup.sh"; then
  fail "backup.sh must not copy the env file into the archive"
fi

# --- restore.sh 契约断言 ---
grep -q 'restore requires an archive argument' "$DEPLOY_DIR/restore.sh" \
  || fail "restore.sh must require an archive argument"
grep -q '"$SCRIPT_DIR/backup.sh"' "$DEPLOY_DIR/restore.sh" \
  || fail "restore.sh must run a safety backup"
grep -q 'compose exec -T mysql' "$DEPLOY_DIR/restore.sh" \
  || fail "restore.sh must pipe database.sql into the MySQL container"
SAFETY_LINE="$(grep -n '"$SCRIPT_DIR/backup.sh"' "$DEPLOY_DIR/restore.sh" | head -n 1 | cut -d: -f1)"
MYSQL_LINE="$(grep -n 'compose exec -T mysql' "$DEPLOY_DIR/restore.sh" | head -n 1 | cut -d: -f1)"
[ -n "$SAFETY_LINE" ] && [ -n "$MYSQL_LINE" ] && [ "$SAFETY_LINE" -lt "$MYSQL_LINE" ] \
  || fail "restore.sh must run a safety backup before touching MySQL"

# --- update.sh 契约断言 ---
grep -q 'git rev-parse HEAD' "$DEPLOY_DIR/update.sh" \
  || fail "update.sh must record git rev-parse HEAD"
grep -q 'git pull --ff-only origin main' "$DEPLOY_DIR/update.sh" \
  || fail "update.sh must pull with --ff-only origin main"
grep -q 'git reset --hard "$OLD_SHA"' "$DEPLOY_DIR/update.sh" \
  || fail "update.sh must restore the old SHA on failure"
grep -q 'healthcheck.sh' "$DEPLOY_DIR/update.sh" \
  || fail "update.sh must health-check before completing"
UPDATE_BACKUP_LINE="$(grep -n '"$SCRIPT_DIR/backup.sh"' "$DEPLOY_DIR/update.sh" | head -n 1 | cut -d: -f1)"
UPDATE_PULL_LINE="$(grep -n 'git pull --ff-only origin main' "$DEPLOY_DIR/update.sh" | head -n 1 | cut -d: -f1)"
[ -n "$UPDATE_BACKUP_LINE" ] && [ -n "$UPDATE_PULL_LINE" ] && [ "$UPDATE_BACKUP_LINE" -lt "$UPDATE_PULL_LINE" ] \
  || fail "update.sh must back up before git pull --ff-only origin main"

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
