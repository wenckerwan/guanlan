-- 清掉「已发布」标记指向不存在的 hotspots 的陈旧候选行。
--
-- 背景：hotspots 表里自动发布的 09-29/09-30/10-01 三批（共 29 条）已被删除，
-- 站表现存仅 349-352 四条人工导入的「时政考点」系列。但 shizheng_candidates 里
-- 这 29 行仍是 status='published' + 指向已不存在行的 hotspot_id ——
-- 后台会显示「已发布」，而点进去没有对应文章；再次筛选该天时 publish() 的
-- 「标题已存在则跳过」也不会挡住重发，标记本身就是错的。
--
-- 只清 status 与 hotspot_id 两个字段：ai_priority / ai_module / ai_reason 保留，
-- 那是当时筛选的结论，仍是人工复核的信息。
-- 跑前用 mysqldump 备份这些行（见 run_clear_stale_published.sh，备份为空即中止）。
START TRANSACTION;

-- 先看要动哪些行（应为 29 行，全是 hotspot_id 找不到对应 hotspots 的）
SELECT c.id, c.publish_date, c.hotspot_id AS 指向的hotspot, LEFT(c.title, 40) AS title
FROM shizheng_candidates c
LEFT JOIN hotspots h ON h.id = c.hotspot_id
WHERE c.status = 'published' AND h.id IS NULL
ORDER BY c.publish_date, c.id;

UPDATE shizheng_candidates c
LEFT JOIN hotspots h ON h.id = c.hotspot_id
SET c.status = 'pending',
    c.hotspot_id = NULL,
    c.updated_at = NOW()
WHERE c.status = 'published' AND h.id IS NULL;

SELECT ROW_COUNT() AS 已清理;

-- 核对：不该再有悬空的 published；仍 published 的行必须指向真实存在的 hotspot
SELECT COUNT(*) AS 仍悬空
FROM shizheng_candidates c
LEFT JOIN hotspots h ON h.id = c.hotspot_id
WHERE c.status = 'published' AND h.id IS NULL;

SELECT c.publish_date, COUNT(*) AS published, SUM(h.id IS NULL) AS dangling
FROM shizheng_candidates c
LEFT JOIN hotspots h ON h.id = c.hotspot_id
WHERE c.status = 'published'
GROUP BY c.publish_date ORDER BY c.publish_date;

COMMIT;
