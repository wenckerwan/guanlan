#!/usr/bin/env bash
set -euo pipefail
if [ "$#" -ne 2 ]; then
  echo "Usage: bash tools/deploy/check_analysis_images.sh API_IMAGE WEB_IMAGE" >&2
  exit 2
fi
export ANALYSIS_GATE_API="$1" ANALYSIS_GATE_WEB="$2"
root=$(cd "$(dirname "$0")/../.." && pwd)
scratch=$(mktemp -d)
project="analysis-gate-$(basename "$scratch" | tr '[:upper:]' '[:lower:]' | tr -cd '[:alnum:]')"
compose=(docker compose --env-file /dev/null -p "$project" -f "$root/tools/deploy/compose.analysis-gateway-check.yml")
cleanup() {
  code=$?
  if [ "$code" -ne 0 ]; then "${compose[@]}" logs --tail 30 >&2 || true; fi
  "${compose[@]}" down || true
  rmdir "$scratch"
}
trap cleanup EXIT
docker image inspect "$ANALYSIS_GATE_API" "$ANALYSIS_GATE_WEB" >/dev/null
"${compose[@]}" up -d --wait --wait-timeout 120 api
# The disposable database has no production data or volumes.
"${compose[@]}" exec -T api php bin/hyperf.php db:seed --force --path=seeders/ArticleSeeder.php
"${compose[@]}" up -d --wait --wait-timeout 90 web gateway
address=$("${compose[@]}" port gateway 80)
python3 "$root/tools/deploy/check_analysis_gateway.py" "http://$address"
