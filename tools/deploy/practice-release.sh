#!/usr/bin/env bash
set -euo pipefail
cd /www/wwwroot/guanlan
release=current-affairs-20261011-01
backup=/www/backup/guanlan/$release
expected=6797c3ab009225f11ad23c54f40103e499448c96
compose() { docker compose --env-file .env.production -f compose.production.yml -f compose.ui-release.yml "$@"; }
case "${1:-}" in
prepare)
  umask 077
  [ ! -e "$backup/database.sql" ] || { echo 'Refusing to overwrite release backup'; exit 1; }
  mkdir -p "$backup"
  docker exec guanlan-mysql-1 sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump --single-transaction --routines --triggers "$MYSQL_DATABASE"' > "$backup/database.sql"
  test -s "$backup/database.sql"
  grep -q 'CREATE TABLE' "$backup/database.sql"
  git rev-parse HEAD > "$backup/git-sha.txt"
  git archive HEAD > "$backup/source.tar"
  cp compose.ui-release.yml "$backup/compose.ui-release.yml"
  cp docker/nginx/production.conf "$backup/gateway.conf"
  cp storage/dataset-manifest.json "$backup/dataset-manifest.json"
  docker inspect --format='{{.Image}}' guanlan-api-1 > "$backup/api-image.txt"
  docker inspect --format='{{.Image}}' guanlan-web-1 > "$backup/web-image.txt"
  docker inspect --format='{{.Name}} {{.Id}}' guanlan-mysql-1 guanlan-redis-1 guanlan-mayuan-api-1 guanlan-mayuan-web-1 > "$backup/data-containers.txt"
  docker tag "$(cat "$backup/api-image.txt")" guanlan-api:rollback-practice-20261011
  docker tag "$(cat "$backup/web-image.txt")" guanlan-web:rollback-practice-20261011
  docker cp guanlan-web-1:/app/public/_nuxt/builds/latest.json "$backup/web-latest.json"
  docker cp /tmp/practice-content-digest.php guanlan-api-1:/tmp/practice-content-digest.php
  docker exec guanlan-api-1 php /tmp/practice-content-digest.php > "$backup/content-before.json"
  (cd "$backup" && sha256sum database.sql source.tar git-sha.txt compose.ui-release.yml gateway.conf dataset-manifest.json api-image.txt web-image.txt data-containers.txt web-latest.json content-before.json > SHA256SUMS && sha256sum -c SHA256SUMS)
  stat -c 'DATABASE_BACKUP_BYTES=%s' "$backup/database.sql"
  git fetch origin refs/heads/main:refs/remotes/origin/main
  [ "$(git rev-parse origin/main)" = "$expected" ]
  git merge --ff-only origin/main
  docker build --build-arg BASE_IMAGE=guanlan-api:rollback-practice-20261011 -f tools/deploy/practice-compat.Dockerfile -t guanlan-api:$release . > "$backup/api-build.log" 2>&1 || { tail -40 "$backup/api-build.log"; exit 1; }
  umask 022
  docker build -t guanlan-web:$release apps/web > "$backup/web-build.log" 2>&1 || { tail -40 "$backup/web-build.log"; exit 1; }
  echo PRACTICE_BUILD_COMPLETE
  ;;
activate)
  [ "$(git rev-parse HEAD)" = "$expected" ]
  (cd "$backup" && sha256sum -c SHA256SUMS)
  docker image inspect guanlan-api:$release guanlan-web:$release >/dev/null
  umask 077
  cat > "$backup/new-images.yml" <<YAML
services:
  api:
    image: guanlan-api:$release
  web:
    image: guanlan-web:$release
YAML
  cat > "$backup/rollback-images.yml" <<'YAML'
services:
  api:
    image: guanlan-api:rollback-practice-20261011
    command: ["php", "bin/hyperf.php", "start"]
  web:
    image: guanlan-web:rollback-practice-20261011
YAML
  cat > "$backup/rollback.sh" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
cd /www/wwwroot/guanlan
backup=/www/backup/guanlan/current-affairs-20261011-01
cp "$backup/compose.ui-release.yml" compose.ui-release.yml
docker compose --env-file .env.production -f compose.production.yml -f compose.ui-release.yml -f "$backup/rollback-images.yml" up -d --no-deps api web
for attempt in $(seq 1 40); do
  if [ "$(docker inspect --format='{{.State.Health.Status}}' guanlan-api-1)" = healthy ] && [ "$(docker inspect --format='{{.State.Health.Status}}' guanlan-web-1)" = healthy ]; then
    docker exec guanlan-gateway-1 nginx -t
    docker exec guanlan-gateway-1 nginx -s reload
    exit 0
  fi
  sleep 2
done
exit 1
# Retain all practice tables and learning data. No schema rollback or database restore.
SH
  chmod 700 "$backup/rollback.sh"
  new_compose() { docker compose --env-file .env.production -f compose.production.yml -f compose.ui-release.yml -f "$backup/new-images.yml" "$@"; }
  new_compose config --quiet
  new_compose run --rm --no-deps -T --entrypoint php api bin/hyperf.php migrate --force
  new_compose run --rm --no-deps -T --entrypoint php api bin/import-practice-bank.php
  new_compose run --rm --no-deps -T -v /tmp/practice-bank-check.php:/tmp/practice-bank-check.php:ro --entrypoint php api /tmp/practice-bank-check.php
  new_compose run --rm --no-deps -T --entrypoint php api bin/import-practice-bank.php --publish
  cp "$backup/new-images.yml" compose.ui-release.yml
  trap 'echo ACTIVATION_FAILED_ROLLING_BACK; bash "$backup/rollback.sh"' ERR
  compose up -d --no-deps api web
  healthy=false
  for attempt in $(seq 1 40); do
    if [ "$(docker inspect --format='{{.State.Health.Status}}' guanlan-api-1)" = healthy ] && [ "$(docker inspect --format='{{.State.Health.Status}}' guanlan-web-1)" = healthy ]; then healthy=true; break; fi
    sleep 2
  done
  [ "$healthy" = true ]
  docker exec guanlan-gateway-1 nginx -t
  docker exec guanlan-gateway-1 nginx -s reload
  bash tools/deploy/healthcheck.sh
  docker cp /tmp/practice-content-digest.php guanlan-api-1:/tmp/practice-content-digest.php
  docker exec guanlan-api-1 php /tmp/practice-content-digest.php > "$backup/content-after.json"
  cmp "$backup/content-before.json" "$backup/content-after.json"
  docker inspect --format='{{.Name}} {{.Id}}' guanlan-mysql-1 guanlan-redis-1 guanlan-mayuan-api-1 guanlan-mayuan-web-1 > "$backup/data-containers-after.txt"
  cmp "$backup/data-containers.txt" "$backup/data-containers-after.txt"
  docker cp guanlan-web-1:/app/public/_nuxt/builds/latest.json "$backup/web-latest-after.json"
  docker inspect --format='{{.Name}} {{.Config.Image}} {{.State.Health.Status}}' guanlan-api-1 guanlan-web-1
  trap - ERR
  echo PRACTICE_DEPLOY_COMPLETE
  ;;
*) echo 'Usage: practice-release.sh prepare|activate'; exit 2 ;;
esac
