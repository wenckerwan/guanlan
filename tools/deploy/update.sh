#!/usr/bin/env bash
# 用法：./tools/deploy/update.sh [env-file]
# 更新生产环境：拒绝脏工作树，备份当前状态，快进拉取 main，
# 重建并启动，健康检查失败时回滚到旧 SHA 并重建旧镜像。
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/common.sh"
resolve_env_file "${1:-}"

log()  { echo "[update] $*"; }
fail() { echo "FAIL: $1" >&2; exit 1; }

[ -f "$ENV_FILE" ] || fail "env file not found: $ENV_FILE"

# 1. 拒绝脏 Git 工作树。
if [ -n "$(cd "$REPO_ROOT" && git status --porcelain)" ]; then
  fail "Git working tree is dirty; commit or stash changes before updating"
fi

# 2. 记录当前 HEAD。
OLD_SHA="$(cd "$REPO_ROOT" && git rev-parse HEAD)"
log "current HEAD: $OLD_SHA"

# 3. 拉取前先备份。
UPDATE_BACKUP="$( "$SCRIPT_DIR/backup.sh" "$ENV_FILE" "${BACKUP_SAFETY_DIR:-/www/backup/guanlan}" )"
[ -n "$UPDATE_BACKUP" ] && [ -f "$UPDATE_BACKUP" ] || fail "pre-update backup failed"
log "pre-update backup created: $UPDATE_BACKUP"

rollback() {
  log "ROLLBACK: resetting repository to recorded SHA $OLD_SHA"
  (cd "$REPO_ROOT" && git reset --hard "$OLD_SHA") || log "WARN: reset failed; manual recovery required"
  log "ROLLBACK: removing untracked files left by the failed update"
  (cd "$REPO_ROOT" && git clean -fd) || log "WARN: clean failed; manual recovery required"
  compose build || log "WARN: rebuild of previous images failed"
  compose up -d --remove-orphans || log "WARN: previous stack start failed"
}

# 4. 快进拉取 main。
if ! (cd "$REPO_ROOT" && git pull --ff-only origin main); then
  log "git pull did not fast-forward; rolling back"
  rollback
  NEW_SHA="$(cd "$REPO_ROOT" && git rev-parse HEAD)"
  fail "update failed: git pull did not fast-forward; failed SHA $NEW_SHA, restored SHA $OLD_SHA"
fi

NEW_SHA="$(cd "$REPO_ROOT" && git rev-parse HEAD)"
log "updated to HEAD: $NEW_SHA"

# 5. 重建、启动并健康检查。
if ! (compose build && compose up -d --remove-orphans && "$SCRIPT_DIR/healthcheck.sh" "$ENV_FILE"); then
  log "build/start/health check failed; rolling back"
  rollback
  fail "update failed: health check did not pass; failed SHA $NEW_SHA, restored SHA $OLD_SHA"
fi

log "update healthy: failed SHA none, restored SHA $OLD_SHA (HEAD $NEW_SHA)"
