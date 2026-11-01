#!/usr/bin/env bash
set -euo pipefail
umask 077
backup=/www/backup/guanlan/admin-e2e-20261010-01
mkdir -p "$backup"
[ ! -s "$backup/database.sql" ] || { echo 'Refusing to overwrite completed backup'; exit 1; }
docker exec guanlan-mysql-1 sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump --single-transaction --routines --triggers "$MYSQL_DATABASE"' > "$backup/database.sql"
test -s "$backup/database.sql"
docker exec guanlan-api-1 php /tmp/content-digest.php > "$backup/content-before.json"
(cd "$backup" && sha256sum database.sql content-before.json > SHA256SUMS && sha256sum -c SHA256SUMS)
