#!/usr/bin/env bash
set -euo pipefail
repo=$(cd "$(dirname "$0")/../.." && pwd)
suffix="$(date +%s)-$$"
network="guanlan-admin-test-$suffix"
database="guanlan-admin-db-$suffix"
api="guanlan-admin-http-$suffix"
image="${ADMIN_TEST_IMAGE:-guanlan-api}"
cleanup() {
  docker rm -f "$api" >/dev/null 2>&1 || true
  docker rm -f "$database" >/dev/null 2>&1 || true
  docker network rm "$network" >/dev/null 2>&1 || true
}
trap cleanup EXIT
docker network create "$network" >/dev/null
docker run -d --name "$database" --network "$network" --tmpfs /var/lib/mysql \
  -e MYSQL_ROOT_PASSWORD=admin-test-only -e MYSQL_DATABASE=guanlan_admin_test mysql:8.4 >/dev/null
ready=false
for attempt in $(seq 1 45); do
  if docker exec "$database" mysql -h127.0.0.1 -uroot -padmin-test-only -e 'SELECT 1' >/dev/null 2>&1; then ready=true; break; fi
  sleep 1
done

if [ "$ready" != true ]; then docker logs "$database"; exit 1; fi
for test_file in AdminReliabilityIntegrationTest.php AdminUsersIntegrationTest.php AdminReviewFixesIntegrationTest.php AdminContentListsIntegrationTest.php AdminArticlesIntegrationTest.php AdminArticleHistoryIntegrationTest.php AdminStatisticsIntegrationTest.php AdminArticleBatchIntegrationTest.php AdminModerationIntegrationTest.php AdminQuestionMaintenanceIntegrationTest.php; do
docker run --rm --network "$network" \
  -e DB_HOST="$database" -e DB_DATABASE=guanlan_admin_test -e DB_USERNAME=root -e DB_PASSWORD=admin-test-only \
  -v "$repo/apps/api/src:/opt/guanlan/src:ro" \
  -v "$repo/apps/api/tests:/opt/guanlan/tests:ro" \
  -v "$repo/apps/api/migrations:/opt/guanlan/migrations:ro" \
  -v "$repo/apps/api/seeders:/opt/guanlan/seeders:ro" \
  --entrypoint php "$image" "/opt/guanlan/tests/$test_file"
done

if [ "${RUN_ADMIN_HTTP_SMOKE:-0}" = 1 ]; then
  docker run -d --name "$api" --network "$network" -e DB_HOST="$database" -e DB_DATABASE=guanlan_admin_test -e DB_USERNAME=root -e DB_PASSWORD=admin-test-only -e APP_KEY=local-test-only \
    -v "$repo/apps/api/src:/opt/guanlan/src:ro" -v "$repo/apps/api/config:/opt/guanlan/config:ro" -v "$repo/apps/api/tests:/opt/guanlan/tests:ro" -v "$repo/storage:/opt/guanlan/storage:ro" \
    --entrypoint php "$image" bin/hyperf.php start >/dev/null
  http_ready=false
  for attempt in $(seq 1 30); do
    if docker exec "$api" php -r 'exit(@file_get_contents("http://127.0.0.1:9501/api/v1/health")===false?1:0);' >/dev/null 2>&1; then http_ready=true; break; fi
    sleep 1
  done
  if [ "$http_ready" != true ]; then docker logs --tail 30 "$api"; exit 1; fi
  docker exec "$api" php /opt/guanlan/tests/AdminHttpSmokeTest.php
fi
