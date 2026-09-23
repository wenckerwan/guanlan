#!/bin/bash
echo "=== direct api: /hotspots ==="
docker exec guanlan-api-1 curl -s -w '\nHTTP=%{http_code}\n' 'http://localhost:9501/api/v1/hotspots' | head -c 600
echo
echo "=== direct api: /analysis ==="
docker exec guanlan-api-1 curl -s -o /dev/null -w 'HTTP=%{http_code}\n' 'http://localhost:9501/api/v1/analysis'
echo "=== nginx: /hotspots ==="
curl -s -o /dev/null -w 'HTTP=%{http_code}\n' 'http://localhost:8080/api/v1/hotspots'
echo "=== api errors ==="
cd ~/guanlan && docker compose logs api --tail 40 2>&1 | grep -iE 'critical|error|exception' | tail -8