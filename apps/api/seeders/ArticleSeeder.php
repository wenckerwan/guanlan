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
    public function run(): void
    {
        \App\Support\ContentMaintenance::import(['analysis_articles', 'hotspots', 'predictions'], function () { $this->importDataset(); });
    }

    private function importDataset(): void
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
        $count = (new \App\Seeder\HotspotDatasetImporter())->import();
        echo '[ArticleSeeder] 时政热点 ' . $count . ' 条' . PHP_EOL;
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
}
