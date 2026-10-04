<?php

declare(strict_types=1);

use App\Model\AnalysisArticle;
use App\Model\Hotspot;
use App\Model\Prediction;
use App\Seeder\DatasetReader;
use App\Seeder\DatasetManifestVerifier;
use Hyperf\Database\Seeders\Seeder;

/**
 * 时政热点 / 时政预测 / 真题分析 三张文章表。
 * hotspots 表同时承担「首页卡片」，故卡片字段（level/summary/type/tag）与长文并存。
 */
class ArticleSeeder extends Seeder
{
    /**
     * 首页卡片用到的学科映射（沿用 dev.2 的 slug）。
     * 键需与 dataset/hotspots.json 的 period 完全一致（数据里是 ASCII 连字符）。
     */
    private const CARD_MAP = [
        '2026年1-6月' => ['slug' => 'xi-jinping-thought', 'level' => 'S', 'type' => '理论与政策', 'tag' => '上半年主线'],
        '2026年7-8月' => ['slug' => 'xi-jinping-thought', 'level' => 'S', 'type' => '理论与政策', 'tag' => '七一与上合'],
        '2026年9月' => ['slug' => 'xi-jinping-thought', 'level' => 'S', 'type' => '理论与政策', 'tag' => '最新月份'],
        '2026年9月下_0927更新' => ['slug' => 'xi-jinping-thought', 'level' => 'S', 'type' => '理论与政策', 'tag' => '9月增补'],
    ];

    public function run(): void
    {
        (new DatasetManifestVerifier())->verify();
        // 顺序保护：db:seed 按类名字母序执行，ArticleSeeder 早于 SubjectSeeder，
        // 而 hotspots.subject_id / predictions.subject_id 有外键约束，必须先确保学科存在。
        if ((int) \Hyperf\DbConnection\Db::table('subjects')->count() === 0) {
            require_once __DIR__ . '/SubjectSeeder.php';
            (new SubjectSeeder())->run();
        }

        $this->seedAnalysis();
        $this->seedHotspots();
        $this->seedPredictions();
    }

    private function seedAnalysis(): void
    {
        $rows = DatasetReader::list('analysis_articles.json');
        if ($rows === []) {
            echo '[ArticleSeeder] 跳过真题分析：dataset 缺失' . PHP_EOL;
            return;
        }

        DatasetReader::truncate('analysis_articles');
        $now = DatasetReader::now();

        foreach ($rows as $row) {
            AnalysisArticle::create([
                'slug' => (string) $row['slug'],
                'title' => (string) $row['title'],
                'category' => (string) ($row['category'] ?? ''),
                'release' => (bool) ($row['release'] ?? false),
                'priority' => (string) ($row['priority'] ?? 'B'),
                'summary' => mb_substr((string) ($row['summary'] ?? ''), 0, 500),
                'html' => (string) ($row['html'] ?? ''),
                'outline' => $row['outline'] ?? [],
                'source_file' => (string) ($row['source_file'] ?? ''),
                'word_count' => (int) ($row['word_count'] ?? 0),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        echo '[ArticleSeeder] 真题分析 ' . AnalysisArticle::query()->count() . ' 篇' . PHP_EOL;
    }

    private function seedHotspots(): void
    {
        $rows = DatasetReader::list('hotspots.json');
        if ($rows === []) {
            echo '[ArticleSeeder] 跳过时政热点：dataset 缺失' . PHP_EOL;
            return;
        }

        $subjectIds = $this->subjectIds();

        // 清空时政长文（保留 dev.2 的卡片式热点：其 slug 为 NULL）
        Hotspot::query()->whereNotNull('slug')->delete();

        $now = DatasetReader::now();

        foreach ($rows as $row) {
            $period = (string) ($row['period'] ?? '');
            $card = self::CARD_MAP[$period] ?? ['slug' => 'xi-jinping-thought', 'level' => 'A', 'type' => '形势与政策', 'tag' => '时政'];

            // 本地审核后才产出 dataset，入库即发布（无云端筛选态）
            Hotspot::create([
                'slug' => (string) $row['slug'],
                'title' => (string) $row['title'],
                'level' => $card['level'],
                'priority' => (string) ($row['priority'] ?? 'A'),
                'period' => $period,
                'summary' => mb_substr((string) ($row['summary'] ?? ''), 0, 500),
                'type' => $card['type'],
                'tag' => $card['tag'],
                'html' => (string) ($row['html'] ?? ''),
                'outline' => $row['outline'] ?? [],
                'word_count' => (int) ($row['word_count'] ?? 0),
                'source_file' => (string) ($row['source_file'] ?? ''),
                'published_at' => $this->periodToDate($period),
                'subject_id' => $subjectIds[$card['slug']] ?? 1,
                'status' => \App\Support\ContentStatus::PUBLISHED,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        echo '[ArticleSeeder] 时政热点 ' . Hotspot::query()->whereNotNull('slug')->count() . ' 期' . PHP_EOL;
    }

    private function seedPredictions(): void
    {
        $rows = DatasetReader::list('predictions.json');
        if ($rows === []) {
            echo '[ArticleSeeder] 跳过时政预测：dataset 缺失' . PHP_EOL;
            return;
        }

        DatasetReader::truncate('predictions');
        $now = DatasetReader::now();

        foreach ($rows as $row) {
            Prediction::create([
                'slug' => (string) $row['slug'],
                'title' => (string) $row['title'],
                'layer' => (string) ($row['layer'] ?? ''),
                'priority' => (string) ($row['priority'] ?? 'A'),
                'summary' => mb_substr((string) ($row['summary'] ?? ''), 0, 500),
                'html' => (string) ($row['html'] ?? ''),
                'outline' => $row['outline'] ?? [],
                'source_file' => (string) ($row['source_file'] ?? ''),
                'word_count' => (int) ($row['word_count'] ?? 0),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        echo '[ArticleSeeder] 时政预测 ' . Prediction::query()->count() . ' 篇' . PHP_EOL;
    }

    /** @return array<string, int> slug => subject_id */
    private function subjectIds(): array
    {
        $out = [];
        $rows = \Hyperf\DbConnection\Db::table('subjects')->select('id', 'slug')->get();
        foreach ($rows as $row) {
            $out[(string) $row->slug] = (int) $row->id;
        }
        return $out;
    }

    private function periodToDate(string $period): string
    {
        if (preg_match('/(\d{4})年(\d{1,2})月/u', $period, $m)) {
            return sprintf('%04d-%02d-01', (int) $m[1], (int) $m[2]);
        }
        return date('Y-m-d');
    }
}
