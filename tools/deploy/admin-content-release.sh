#!/usr/bin/env bash
set -euo pipefail
cd /www/wwwroot/guanlan
release=admin-content-20261010-01
backup=/www/backup/guanlan/$release
expected=51436bb
compose() { docker compose --env-file .env.production -f compose.production.yml -f compose.ui-release.yml "$@"; }
case "${1:-}" in
prepare|resume)
  umask 077
  mkdir -p "$backup"
  if [ "$1" = prepare ]; then
  [ ! -e "$backup/database.sql" ] || { echo 'Refusing to overwrite backup'; exit 1; }
  docker exec guanlan-mysql-1 sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump --single-transaction --routines --triggers "$MYSQL_DATABASE"' > "$backup/database.sql"
  test -s "$backup/database.sql"
  grep -q 'CREATE TABLE' "$backup/database.sql"
  git rev-parse HEAD > "$backup/git-sha.txt"
  git archive HEAD > "$backup/source.tar"
  cp compose.ui-release.yml "$backup/compose.ui-release.yml"
  cp storage/dataset-manifest.json "$backup/dataset-manifest.json"
  docker inspect --format='{{.Image}}' guanlan-api-1 > "$backup/api-image.txt"
  docker inspect --format='{{.Image}}' guanlan-web-1 > "$backup/web-image.txt"
  docker inspect --format='{{.Name}} {{.Id}}' guanlan-mysql-1 guanlan-redis-1 > "$backup/data-containers.txt"
  docker tag "$(cat "$backup/api-image.txt")" guanlan-api:rollback-content-20261010
  docker tag "$(cat "$backup/web-image.txt")" guanlan-web:rollback-content-20261010
  else
    [ "$(git rev-parse HEAD)" = "$(cat "$backup/git-sha.txt")" ]
    test -s "$backup/database.sql"
    grep -q 'CREATE TABLE' "$backup/database.sql"
    for name in source.tar compose.ui-release.yml dataset-manifest.json api-image.txt web-image.txt data-containers.txt; do test -s "$backup/$name"; done
  fi
  docker cp guanlan-web-1:/app/public/_nuxt/builds/latest.json "$backup/web-latest.json"
  docker cp /tmp/guanlan-content-digest.php guanlan-api-1:/tmp/content-digest.php
  docker exec guanlan-api-1 php /tmp/content-digest.php > "$backup/content-before.json"
  (cd "$backup" && sha256sum database.sql source.tar git-sha.txt compose.ui-release.yml dataset-manifest.json content-before.json api-image.txt web-image.txt data-containers.txt web-latest.json > SHA256SUMS && sha256sum -c SHA256SUMS)
  stat -c 'DATABASE_BACKUP_BYTES=%s' "$backup/database.sql"
  git fetch origin refs/heads/main:refs/remotes/origin/main
  [ "$(git rev-parse --short=7 origin/main)" = "$expected" ]
  git merge --ff-only origin/main
  docker build --build-arg BASE_IMAGE=guanlan-api:rollback-content-20261010 -f tools/deploy/admin-reliability.Dockerfile -t guanlan-api:$release . > "$backup/api-build.log" 2>&1 || { tail -60 "$backup/api-build.log"; exit 1; }
  umask 022
  docker build -t guanlan-web:$release apps/web > "$backup/web-build.log" 2>&1 || { tail -60 "$backup/web-build.log"; exit 1; }
  echo BUILD_COMPLETE
  ;;
activate)
  [ "$(git rev-parse --short=7 HEAD)" = "$expected" ]
  (cd "$backup" && sha256sum -c SHA256SUMS)
  docker image inspect guanlan-api:$release guanlan-web:$release >/dev/null
  umask 077
  cat > "$backup/rollback-api.yml" <<'YAML'
services:
  api:
    image: guanlan-api:rollback-content-20261010
    command: ["php", "bin/hyperf.php", "start"]
YAML
  cat > "$backup/rollback.sh" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
cd /www/wwwroot/guanlan
backup=/www/backup/guanlan/admin-content-20261010-01
cp "$backup/compose.ui-release.yml" compose.ui-release.yml
docker compose --env-file .env.production -f compose.production.yml -f compose.ui-release.yml -f "$backup/rollback-api.yml" up -d --no-deps api web
sleep 8
docker exec guanlan-gateway-1 nginx -t
docker exec guanlan-gateway-1 nginx -s reload
# Keep the additive schema and new revisions; do not restore the database over live learning records.
SH
  chmod 700 "$backup/rollback.sh"
  cat > compose.ui-release.yml <<YAML
services:
  api:
    image: guanlan-api:$release
  web:
    image: guanlan-web:$release
YAML
  compose config --quiet
  compose run --rm --no-deps -T --entrypoint php api bin/hyperf.php migrate --force
  compose up -d --no-deps api web
  healthy=false
  for attempt in $(seq 1 40); do
    if [ "$(docker inspect --format='{{.State.Health.Status}}' guanlan-api-1)" = healthy ] && [ "$(docker inspect --format='{{.State.Health.Status}}' guanlan-web-1)" = healthy ]; then healthy=true; break; fi
    sleep 2
  done
  if [ "$healthy" != true ]; then docker logs --tail 35 guanlan-api-1; docker logs --tail 20 guanlan-web-1; exit 1; fi
  docker exec guanlan-gateway-1 nginx -t
  docker exec guanlan-gateway-1 nginx -s reload
  bash tools/deploy/healthcheck.sh
  docker cp /tmp/guanlan-content-digest.php guanlan-api-1:/tmp/content-digest.php
  docker exec guanlan-api-1 php /tmp/content-digest.php > "$backup/content-after.json"
  cmp "$backup/content-before.json" "$backup/content-after.json"
  docker inspect --format='{{.Name}} {{.Id}}' guanlan-mysql-1 guanlan-redis-1 > "$backup/data-containers-after.txt"
  cmp "$backup/data-containers.txt" "$backup/data-containers-after.txt"
  docker inspect --format='{{.Name}} {{.Config.Image}} {{.State.Health.Status}}' guanlan-api-1 guanlan-web-1
  echo DEPLOY_COMPLETE
  ;;
*) echo 'Usage: admin-content-release.sh prepare|resume|activate'; exit 2 ;;
esac
