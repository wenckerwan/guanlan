-- 修掉 hotspots id=322 摘要/正文里的版式残留
-- 「2026年09月28日08:35 来源：光明日报222\n」——它来自旧爬虫未过滤的日期来源行。
-- 跑前先用 mysqldump 备份该行（deploy/backup_hotspot_322_*.sql）。
START TRANSACTION;

UPDATE hotspots
SET summary = REGEXP_REPLACE(
        summary,
        '^[0-9]{4}年[0-9]{1,2}月[0-9]{1,2}日[ ]*[0-9]{1,2}:[0-9]{2}[ ]*来源：[^\\n]*\\n?',
        ''),
    html = REGEXP_REPLACE(
        html,
        '[0-9]{4}年[0-9]{1,2}月[0-9]{1,2}日[ ]*[0-9]{1,2}:[0-9]{2}[ ]*来源：[^<\\n]*\\n?',
        '')
WHERE id = 322;

SELECT ROW_COUNT() AS affected;
SELECT id, LEFT(summary, 60) AS summary_head,
       (summary LIKE '%来源：%' OR html LIKE '%来源：光明日报%') AS still_dirty
FROM hotspots WHERE id = 322;

COMMIT;
