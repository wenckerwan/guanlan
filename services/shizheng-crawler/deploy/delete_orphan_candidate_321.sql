-- 删除 2026-09-29 候选池里的孤儿行 id=321「向新而生--文化--人民网」。
-- 成因：候选 upsert 以 (publish_date, title) 为键，标题被 `_clean_title()` 洗过之后
-- 重推会新建一行（id=394「向新而生」），旧的脏标题行留在池子里成为重复。
-- 由 deploy/check_candidate_orphans.py 只读核验：全池仅此 1 行孤儿，status=pending。
-- 跑前用 mysqldump 备份该行（deploy/run_delete_orphan_candidate.sh 会在备份为空时中止）。
START TRANSACTION;

SELECT id, title, status FROM shizheng_candidates
WHERE id = 321 AND publish_date = '2026-09-29' AND status <> 'published';

DELETE FROM shizheng_candidates
WHERE id = 321 AND publish_date = '2026-09-29' AND status <> 'published';

SELECT ROW_COUNT() AS deleted;
SELECT COUNT(*) AS remaining,
       SUM(status = 'published') AS published
FROM shizheng_candidates WHERE publish_date = '2026-09-29';

COMMIT;
