-- 回滚 2026-09-30 22:27（UTC 14:27）那次抢跑 pipeline 的产物。
-- 备份：/opt/shizheng/data/site_hotspots_candidates_20260930-2232.sql
-- 保留：hotspots id 302-305（人工维护的时政考点条目）。
START TRANSACTION;

DELETE FROM shizheng_candidates WHERE publish_date = '2026-09-29';

DELETE FROM hotspots
 WHERE id BETWEEN 306 AND 315
   AND created_at = '2026-09-30 14:27:47';

COMMIT;

SELECT COUNT(*) AS hotspots_left FROM hotspots;
SELECT COUNT(*) AS candidates_left FROM shizheng_candidates;
SELECT id, LEFT(title, 50) AS title, created_at FROM hotspots ORDER BY id;
