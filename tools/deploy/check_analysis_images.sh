#!/usr/bin/env bash
set -euo pipefail
if [ "$#" -ne 2 ]; then
  echo "Usage: bash tools/deploy/check_analysis_images.sh API_IMAGE WEB_IMAGE" >&2
  exit 2
fi
export ANALYSIS_GATE_API="$1" ANALYSIS_GATE_WEB="$2"
root=$(cd "$(dirname "$0")/../.." && pwd)
scratch=$(mktemp -d)
export ANALYSIS_GATE_STORAGE="$scratch/storage"
project="analysis-gate-$(basename "$scratch" | tr '[:upper:]' '[:lower:]' | tr -cd '[:alnum:]')"
compose=(docker compose --env-file /dev/null -p "$project" -f "$root/tools/deploy/compose.analysis-gateway-check.yml")
cleanup() {
  code=$?
  if [ "$code" -ne 0 ]; then "${compose[@]}" logs --tail 30 >&2 || true; fi
  "${compose[@]}" down || true
  rm -rf -- "$scratch"
}
trap cleanup EXIT
docker image inspect "$ANALYSIS_GATE_API" "$ANALYSIS_GATE_WEB" >/dev/null
mkdir -p "$ANALYSIS_GATE_STORAGE/dataset"
cp "$root/storage/import-manifest.json" "$ANALYSIS_GATE_STORAGE/"
for file in mistakes mocks papers questions stats; do
  cp "$root/storage/dataset/$file.json" "$ANALYSIS_GATE_STORAGE/dataset/"
done
python3 "$root/tools/deploy/build_analysis_fixture.py" "$scratch"
"${compose[@]}" up -d --wait --wait-timeout 120 api
"${compose[@]}" cp "$scratch/legacy-analysis.json" api:/tmp/legacy-analysis.json
"${compose[@]}" exec -T -e DB_HOST=analysis-qa-db api \
  php tests/AnalysisReleaseIntegrationTest.php /tmp/legacy-analysis.json

"${compose[@]}" up -d --wait --wait-timeout 90 web gateway
address=$("${compose[@]}" port gateway 80)
python3 "$root/tools/deploy/check_analysis_gateway.py" "http://$address"
