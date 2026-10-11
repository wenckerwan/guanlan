#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
user_id=$(docker exec guanlan-practice-db-20261011 mysql -uroot -ppractice-test-only -N -e "SELECT id FROM guanlan_practice_admin_test.users WHERE email='practice-b@example.test'")
event=11111111-1111-4111-8111-111111111111
bash tools/tests/run-practice-isolated.sh php tests/PracticeConcurrentWorker.php "$user_id" "$event" >concurrent-a.log & a=$!
bash tools/tests/run-practice-isolated.sh php tests/PracticeConcurrentWorker.php "$user_id" "$event" >concurrent-b.log & b=$!
wait "$a"
wait "$b"
cat concurrent-a.log concurrent-b.log
count=$(docker exec guanlan-practice-db-20261011 mysql -uroot -ppractice-test-only -N -e "SELECT COUNT(*) FROM guanlan_practice_admin_test.practice_attempts WHERE user_id=$user_id AND client_event_id='$event'")
[ "$count" = 1 ]
echo CONCURRENT_EVENT_COUNT=1
