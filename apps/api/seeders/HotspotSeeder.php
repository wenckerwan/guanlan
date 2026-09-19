<?php

declare(strict_types=1);

use Hyperf\Database\Seeders\Seeder;
use Hyperf\DbConnection\Db;

class HotspotSeeder extends Seeder
{
    /**
     * 数据源：apps/web/data/home.ts 的 6 条热点。
     * subject_id 按 slug 查、chapter_id 按 (subject_id, chapter_key) 查；
     * 用查询构造器显式写 created_at/updated_at 为原日期；按 title 幂等。
     */
    public function run(): void
    {
        // 顺序保护：若 subjects 尚未灌入（db:seed 按类名字母序可能让本类先于 SubjectSeeder 执行），
        // 先确保学科/章节存在，否则 subject_id 会解析为 null 触发外键约束失败。
        if ((int) Db::table('subjects')->count() === 0) {
            require_once __DIR__ . '/SubjectSeeder.php';
            (new SubjectSeeder())->run();
        }

        $hotspots = [
            ['level' => 'S', 'title' => '十五五规划与开局之年', 'summary' => '高质量发展 · 新质生产力 · 共同富裕', 'type' => '理论与政策', 'updated_at' => '2026-09-06', 'tag' => '重点命题包', 'subject_slug' => 'xi-jinping-thought', 'chapter_key' => 'high-quality-development'],
            ['level' => 'S', 'title' => '党的二十届五中全会', 'summary' => '全面从严治党 · 长期执政能力 · 自我革命', 'type' => '理论与政策', 'updated_at' => '2026-09-05', 'tag' => '持续跟踪', 'subject_slug' => 'xi-jinping-thought', 'chapter_key' => 'new-era'],
            ['level' => 'S', 'title' => '长征胜利 90 周年', 'summary' => '遵义会议 · 独立自主 · 长征精神', 'type' => '中国近现代史纲要', 'updated_at' => '2026-09-04', 'tag' => '史纲重点', 'subject_slug' => 'modern-china-history', 'chapter_key' => 'revolution'],
            ['level' => 'A', 'title' => '2026 APEC 中国主场', 'summary' => '开放 · 创新 · 合作 · 全球治理', 'type' => '形势与政策', 'updated_at' => '2026-09-03', 'tag' => '11月会议', 'subject_slug' => 'world-politics', 'chapter_key' => 'international-pattern'],
            ['level' => 'A', 'title' => '人工智能与科技自立自强', 'summary' => '实践认识 · 生产力 · 发展与治理', 'type' => '马克思主义基本原理', 'updated_at' => '2026-09-02', 'tag' => '原理迁移', 'subject_slug' => 'marxism', 'chapter_key' => 'epistemology'],
            ['level' => 'A', 'title' => '乡村全面振兴与共同富裕', 'summary' => '粮食安全 · 城乡融合 · 农业强国', 'type' => '理论与政策', 'updated_at' => '2026-09-01', 'tag' => '政策主线', 'subject_slug' => 'maoism', 'chapter_key' => 'reform-opening'],
        ];

        foreach ($hotspots as $row) {
            $subjectId = Db::table('subjects')->where('slug', $row['subject_slug'])->value('id');
            $chapterId = Db::table('chapters')
                ->where('subject_id', $subjectId)
                ->where('chapter_key', $row['chapter_key'])
                ->value('id');

            $timestamp = $row['updated_at'] . ' 00:00:00';

            $exists = Db::table('hotspots')->where('title', $row['title'])->exists();
            if (! $exists) {
                Db::table('hotspots')->insert([
                    'title' => $row['title'],
                    'level' => $row['level'],
                    'summary' => $row['summary'],
                    'type' => $row['type'],
                    'tag' => $row['tag'],
                    'subject_id' => $subjectId,
                    'chapter_id' => $chapterId,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            }
        }
    }
}
