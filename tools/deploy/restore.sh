#!/usr/bin/env bash
# 用法：./tools/deploy/restore.sh <archive> [env-file]
# 从备份归档恢复 MySQL（干净替换语义，可重复执行）：
# 校验归档完整性后才触碰数据库，且在灌入 database.sql 前先自动做一次安全备份；
# 导入前先 DROP DATABASE IF EXISTS 再 CREATE DATABASE，确保是替换而非累加。
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/common.sh"

log()  { echo "[restore] $*"; }
fail() { echo "FAIL: $1" >&2; exit 1; }

ARCHIVE="${1:-}"
if [ -z "$ARCHIVE" ]; then
  fail "restore requires an archive argument: ./tools/deploy/restore.sh <archive> [env-file]"
fi
resolve_env_file "${2:-}"

[ -f "$ARCHIVE" ] || fail "archive not found: $ARCHIVE"
[ -f "$ENV_FILE" ] || fail "env file not found: $ENV_FILE"

umask 077
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

# 1. 校验归档内嵌 SHA-256（SHA256SUMS）与成员完整性。
tar -xzf "$ARCHIVE" -C "$TMP_DIR" SHA256SUMS || fail "archive missing SHA256SUMS"
for member in database.sql VERSION git-sha.txt dataset-manifest.json; do
  tar -xzf "$ARCHIVE" -C "$TMP_DIR" "$member" || fail "archive missing required member $member"
done
# 清单必须明确覆盖 database.sql，防止只列了非 dump 成员的不完整清单通过。
grep -qE '^[0-9a-f]{64}[[:space:]]+database\.sql$' "$TMP_DIR/SHA256SUMS" \
  || fail "SHA256SUMS does not list database.sql"
( cd "$TMP_DIR" && sha256sum -c SHA256SUMS ) || fail "archive checksum validation failed"

# 2. 灌库前先做安全备份；只有备份成功才触碰 MySQL。
SAFETY_ARCHIVE="$("$SCRIPT_DIR/backup.sh" "$ENV_FILE" "${BACKUP_SAFETY_DIR:-/www/backup/guanlan}" | tail -n 1)"
[ -n "$SAFETY_ARCHIVE" ] && [ -f "$SAFETY_ARCHIVE" ] || fail "safety backup failed; aborting restore"
log "safety backup created: $SAFETY_ARCHIVE"

# 3. 先 DROP 后 CREATE 重建空库（可重复、干净的替换），再灌入 database.sql。
# 密码经 MYSQL_PWD 环境变量传入，不进入命令行参数。
compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -e "DROP DATABASE IF EXISTS $MYSQL_DATABASE; CREATE DATABASE $MYSQL_DATABASE;"' \
  || fail "failed to drop/recreate target database"

compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql "$MYSQL_DATABASE"' \
  < "$TMP_DIR/database.sql"

log "restore complete from $ARCHIVE"
