#!/usr/bin/env bash
set -euo pipefail
repo=$(cd "$(dirname "$0")/../.." && pwd)
suffix="$(date +%s)-$$"
network="guanlan-admin-test-$suffix"
database="guanlan-admin-db-$suffix"
image="${ADMIN_TEST_IMAGE:-guanlan-api}"
cleanup() {
  docker rm -f "$database" >/dev/null 2>&1 || true
  docker network rm "$network" >/dev/null 2>&1 || true
}
trap cleanup EXIT
docker network create "$network" >/dev/null
docker run -d --name "$database" --network "$network" --tmpfs /var/lib/mysql \
  -e MYSQL_ROOT_PASSWORD=admin-test-only -e MYSQL_DATABASE=guanlan_admin_test mysql:8.4 >/dev/null
ready=false
for attempt in $(seq 1 45); do
  if docker exec "$database" mysql -uroot -padmin-test-only -e 'SELECT 1' >/dev/null 2>&1; then ready=true; break; fi
  sleep 1
done
if [ "$ready" != true ]; then docker logs "$database"; exit 1; fi
for test_file in AdminReliabilityIntegrationTest.php AdminUsersIntegrationTest.php AdminReviewFixesIntegrationTest.php AdminContentListsIntegrationTest.php AdminArticlesIntegrationTest.php; do
docker run --rm --network "$network" \
  -e DB_HOST="$database" -e DB_DATABASE=guanlan_admin_test -e DB_USERNAME=root -e DB_PASSWORD=admin-test-only \
  -v "$repo/apps/api/src:/opt/guanlan/src:ro" \
  -v "$repo/apps/api/tests:/opt/guanlan/tests:ro" \
  -v "$repo/apps/api/migrations:/opt/guanlan/migrations:ro" \
  -v "$repo/apps/api/seeders:/opt/guanlan/seeders:ro" \
  --entrypoint php "$image" "/opt/guanlan/tests/$test_file"
done
