#!/usr/bin/env bash
# 用法：./tools/deploy/backup.sh [env-file] [backup-dir]
# 生成生产数据库一致性备份归档，并打印归档绝对路径。
# 归档成员：database.sql、VERSION、git-sha.txt、dataset-manifest.json、SHA256SUMS。
# 安全约束：默认备份目录 /www/backup/guanlan，umask 077，临时目录由 trap 清理，
# 保留最新 7 份归档，绝不复制 .env.production（exclude .env.production from backups）。
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/common.sh"
resolve_env_file "${1:-}"

log()  { echo "[backup] $*"; }
fail() { echo "FAIL: $1" >&2; exit 1; }

BACKUP_DIR="${2:-/www/backup/guanlan}"
umask 077

[ -f "$ENV_FILE" ] || fail "env file not found: $ENV_FILE"
[ -f "$REPO_ROOT/VERSION" ] || fail "VERSION file not found"
[ -f "$REPO_ROOT/storage/dataset-manifest.json" ] || fail "storage/dataset-manifest.json not found"

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

mkdir -p "$BACKUP_DIR"

log "dumping MySQL with mysqldump --single-transaction --routines --triggers"
compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump --single-transaction --routines --triggers "$MYSQL_DATABASE"' \
  > "$TMP_DIR/database.sql"

cp "$REPO_ROOT/VERSION" "$TMP_DIR/VERSION"
git -C "$REPO_ROOT" rev-parse HEAD > "$TMP_DIR/git-sha.txt"
cp "$REPO_ROOT/storage/dataset-manifest.json" "$TMP_DIR/dataset-manifest.json"

( cd "$TMP_DIR" && sha256sum database.sql VERSION git-sha.txt dataset-manifest.json > SHA256SUMS )

STAMP="$(date +%Y%m%d-%H%M%S)"
ARCHIVE="$BACKUP_DIR/guanlan-$STAMP.tar.gz"

tar -czf "$ARCHIVE" -C "$TMP_DIR" database.sql VERSION git-sha.txt dataset-manifest.json SHA256SUMS
sha256sum "$ARCHIVE" > "$ARCHIVE.sha256"

# 保留最新 7 份归档（按名称排序，删除超出部分）。
mapfile -t KEEP < <(find "$BACKUP_DIR" -maxdepth 1 -type f -name 'guanlan-*.tar.gz' | sort -r | head -n 7)
find "$BACKUP_DIR" -maxdepth 1 -type f -name 'guanlan-*.tar.gz' | while read -r f; do
  is_keep=0
  for keep in "${KEEP[@]}"; do
    [ "$keep" = "$f" ] && { is_keep=1; break; }
  done
  [ "$is_keep" -eq 0 ] && { log "pruning old archive $f"; rm -f "$f" "$f.sha256"; }
done

log "backup complete"
echo "$ARCHIVE"
