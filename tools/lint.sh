#!/bin/bash
cd ~/guanlan
docker run --rm -v "$PWD/apps/api:/app" composer:2 sh -c 'cd /app && n=0; fail=0; for f in $(find src config migrations seeders -name "*.php"); do n=$((n+1)); out=$(php -l "$f" 2>&1) || { fail=$((fail+1)); echo "FAIL $f: $out"; }; done; echo "checked=$n failed=$fail"'