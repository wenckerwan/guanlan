<?php

declare(strict_types=1);

namespace App\Seeder;

use App\Model\Prediction;

/**
 * 时政预测导入：清空 predictions 后整表重灌（按 slug 唯一，幂等）。
 *
 * 只写 predictions 表，不触碰真题分析与时政热点，因此可以单独跑（见 predict:import）。
 * 条目按题位拆：layer = 命题规律 / 选择题 / 分析题，sort_order 决定题位顺序。
 */
final class PredictionDatasetImporter
{
    /** @return int 导入后的条目数 */
    public function import(): int
    {
        $rows = DatasetReader::list('predictions.json');
        if ($rows === []) {
            echo '[PredictionDatasetImporter] 跳过时政预测：dataset 缺失' . PHP_EOL;
            return 0;
        }

        // 用 DELETE 而不是 TRUNCATE：TRUNCATE 是 DDL，会隐式提交并打断外层事务。
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

        return Prediction::query()->count();
    }
}
