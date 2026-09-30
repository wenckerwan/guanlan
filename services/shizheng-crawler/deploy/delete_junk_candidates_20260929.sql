-- 删除 2026-09-29 候选池里 6 条「正文全是页脚噪声」的垃圾候选。
-- 来源：edu.people.com.cn 的「每日一闻 / 每日一句」条目，页面里没有可用 <p>，
-- 旧爬虫把整块页脚（社概况链接堆 + 许可证号 + 版权行，521 字 / 211 汉字）当正文存下，
-- 并顺着候选 payload 进了网站审核池。爬虫侧对应 6 行已删（deploy/delete_junk_articles.py，
-- 导出 /opt/shizheng/data/deleted_articles_20261001-013729.json）。
-- 跑前已用 mysqldump 备份这些行（deploy/backup_junk_candidates_*.sql）。
-- 双重保险：status='published' 的行一律不动。
START TRANSACTION;

SELECT id, title, status FROM shizheng_candidates
WHERE publish_date = '2026-09-29'
  AND status <> 'published'
  AND title IN (
    '每日一闻丨超强厄尔尼诺事件预计将于11月前后形成',
    '每日一句丨每个人都可以在心里种下一棵树',
    '每日一句丨与其等待，不如起而行之',
    '每日一闻丨田径一日四金 乒乓六金收官',
    '每日一闻丨大熊猫“平平”“福双”抵达美国',
    '每日一句丨留白是把更多故事和想象留给观看的人'
  );

DELETE FROM shizheng_candidates
WHERE publish_date = '2026-09-29'
  AND status <> 'published'
  AND title IN (
    '每日一闻丨超强厄尔尼诺事件预计将于11月前后形成',
    '每日一句丨每个人都可以在心里种下一棵树',
    '每日一句丨与其等待，不如起而行之',
    '每日一闻丨田径一日四金 乒乓六金收官',
    '每日一闻丨大熊猫“平平”“福双”抵达美国',
    '每日一句丨留白是把更多故事和想象留给观看的人'
  );

SELECT ROW_COUNT() AS deleted;
SELECT status, COUNT(*) AS n FROM shizheng_candidates
WHERE publish_date = '2026-09-29' GROUP BY status;

COMMIT;
