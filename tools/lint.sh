#!/bin/bash
cd ~/guanlan
docker run --rm -v "$PWD/apps/api:/app" -v "$PWD/tools:/tools" composer:2 sh -c 'cd /app && n=0; fail=0; for f in $(find src config migrations seeders -name "*.php"); do n=$((n+1)); out=$(php -l "$f" 2>&1) || { fail=$((fail+1)); echo "FAIL $f: $out"; }; done; php -d zend.assertions=1 -d assert.exception=1 tests/DatasetManifestVerifierTest.php || fail=$((fail+1)); echo "checked=$n failed=$fail"; test "$fail" -eq 0'
