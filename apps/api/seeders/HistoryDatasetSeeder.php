<?php

declare(strict_types=1);

use App\Model\HistoryDataset;
use Hyperf\Database\Seeders\Seeder;

/**
 * 史纲（近现代史时间实验室）数据集导入。
 * 数据源：storage/history/history.v1.json（上游子项目构建产物，单向同步）。
 * 按 version upsert；已有同 version 则跳过，新 version 插入一条 published 记录。
 * 不调用 DatasetManifestVerifier（史纲不属于 8 个真题数据集清单）。
 */
class HistoryDatasetSeeder extends Seeder
{
    public function run(): void
    {
        $path = BASE_PATH . '/storage/history/history.v1.json';
        if (! is_file($path)) {
            $this->log('跳过：storage/history/history.v1.json 不存在。请从史纲上游仓库同步 data/history.v1.json。');
            return;
        }
        $payload = json_decode((string) file_get_contents($path), true);
        if (! is_array($payload) || ! isset($payload['version'], $payload['events'])) {
            $this->log('跳过：history.v1.json 缺少 version/events 字段，疑似无效数据集。');
            return;
        }
        $version = (string) $payload['version'];
        $exists = HistoryDataset::query()->where('version', $version)->exists();
        if ($exists) {
            $this->log("已存在版本 {$version}，跳过导入。");
            return;
        }
        $now = date('Y-m-d H:i:s');
        HistoryDataset::query()->insert([
            'version' => $version,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'status' => 'published',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->log("已导入史纲数据集 {$version}（events=" . count((array) $payload['events']) . '）。');
    }

    private function log(string $message): void
    {
        echo '[HistoryDatasetSeeder] ' . $message . PHP_EOL;
    }
}
