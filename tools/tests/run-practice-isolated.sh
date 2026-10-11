#!/usr/bin/env bash
set -euo pipefail
repo=$(cd "$(dirname "$0")/../.." && pwd)
network=guanlan-practice-test-20261011
database=guanlan-practice-db-20261011
api=guanlan-practice-api-20261011
image=${PRACTICE_TEST_IMAGE:-guanlan-api:admin-content-20261010-01}
db_name=${PRACTICE_TEST_DATABASE:-guanlan_practice_admin_test}
run_php() {
  docker run --rm --network "$network" \
    -e DB_HOST="$database" -e DB_DATABASE="$db_name" -e DB_USERNAME=root -e DB_PASSWORD=practice-test-only \
    -e APP_KEY=practice-isolated-test-only \
    -v "$repo/apps/api/src:/opt/guanlan/src:ro" -v "$repo/apps/api/config:/opt/guanlan/config:ro" \
    -v "$repo/apps/api/tests:/opt/guanlan/tests:ro" -v "$repo/apps/api/migrations:/opt/guanlan/migrations:ro" \
    -v "$repo/apps/api/resources:/opt/guanlan/resources:ro" -v "$repo/apps/api/bin/import-practice-bank.php:/opt/guanlan/bin/import-practice-bank.php:ro" \
    --entrypoint php "$image" "$@"
}
case "${1:-}" in
prepare)
  docker network create "$network" >/dev/null
  docker run -d --name "$database" --network "$network" --tmpfs /var/lib/mysql \
    -e MYSQL_ROOT_PASSWORD=practice-test-only -e MYSQL_DATABASE="$db_name" mysql:8.4 >/dev/null
  for attempt in $(seq 1 45); do
    if docker exec "$database" mysql -h127.0.0.1 -uroot -ppractice-test-only -e 'SELECT 1' >/dev/null 2>&1; then echo DISPOSABLE_MYSQL_READY; exit 0; fi
    sleep 1
  done
  docker logs --tail 20 "$database"; exit 1
  ;;
test)
  run_php tests/PracticeDataContractTest.php
  run_php tests/PracticeIntegrationTest.php
  run_php tests/PracticeHttpFixtures.php
  run_php tests/PracticeStatsIntegrationTest.php
  ;;
php)
  shift; run_php "$@"
  ;;
start)
  run_php bin/hyperf.php migrate --force
  run_php bin/import-practice-bank.php --publish
  run_php tests/PracticeHttpFixtures.php
  docker run -d --name "$api" --network "$network" -p 127.0.0.1:19511:9501 \
    -e DB_HOST="$database" -e DB_DATABASE="$db_name" -e DB_USERNAME=root -e DB_PASSWORD=practice-test-only \
    -e APP_KEY=practice-isolated-test-only \
    -v "$repo/apps/api/src:/opt/guanlan/src:ro" -v "$repo/apps/api/config:/opt/guanlan/config:ro" \
    -v "$repo/apps/api/tests:/opt/guanlan/tests:ro" -v "$repo/apps/api/resources:/opt/guanlan/resources:ro" \
    --entrypoint php "$image" bin/hyperf.php start >/dev/null
  for attempt in $(seq 1 30); do
    if docker exec "$api" php -r 'exit(@file_get_contents("http://127.0.0.1:9501/api/v1/health")===false?1:0);' >/dev/null 2>&1; then echo DISPOSABLE_API_READY; exit 0; fi
    sleep 1
  done
  docker logs --tail 30 "$api"; exit 1
  ;;
http)
  docker exec "$api" php tests/PracticeHttpSmokeTest.php
  ;;
stop)
  docker rm -f "$api" "$database" >/dev/null 2>&1 || true
  docker network rm "$network" >/dev/null 2>&1 || true
  ;;
*) echo 'Usage: run-practice-isolated.sh prepare|test|php ARGS|start|http|stop'; exit 2 ;;
esac
