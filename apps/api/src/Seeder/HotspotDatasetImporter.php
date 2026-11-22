<?php

declare(strict_types=1);

namespace App\Seeder;

use App\Model\Hotspot;
use App\Support\ContentStatus;
use Hyperf\DbConnection\Db;

/**
 * 时政长文（事件条目）导入：清空 slug 非空的旧条目后整表重灌。
 *
 * 幂等：按 slug 唯一；保留 dev.2 遗留的卡片式热点（其 slug 为 NULL）。
 * 只写 hotspots 表，不触碰真题分析 / 时政预测，因此可以单独跑（见 shizheng:import）。
 */
final class HotspotDatasetImporter
{
    /**
     * 事件条目的主考模块 → 学科 slug。
     * 时政改为一事件一篇后，学科由 dataset 的 module 字段决定。
     */
    private const MODULE_SUBJECTS = [
        '习思想' => 'xi-jinping-thought',
        '毛中特' => 'maoism',
        '史纲' => 'modern-china-history',
        '马原' => 'marxism',
        '思法' => 'ideology-law',
        '当代' => 'world-politics',
    ];

    /** 未配置 period 时首页卡片字段的兜底（沿用 dev.2 的 slug）。 */
    private const CARD_MAP = [
        '2026年1-6月' => ['slug' => 'xi-jinping-thought', 'level' => 'S', 'type' => '理论与政策', 'tag' => '上半年主线'],
        '2026年7-8月' => ['slug' => 'xi-jinping-thought', 'level' => 'S', 'type' => '理论与政策', 'tag' => '七一与上合'],
        '2026年9月' => ['slug' => 'xi-jinping-thought', 'level' => 'S', 'type' => '理论与政策', 'tag' => '9月更新'],
        '2026年9月下_0927更新' => ['slug' => 'xi-jinping-thought', 'level' => 'S', 'type' => '理论与政策', 'tag' => '9月增补'],
        '2026年10月' => ['slug' => 'xi-jinping-thought', 'level' => 'S', 'type' => '理论与政策', 'tag' => '10月补更'],
        '2026年11月' => ['slug' => 'xi-jinping-thought', 'level' => 'S', 'type' => '理论与政策', 'tag' => '五中全会'],
    ];

    /**
     * @return int 导入后的条目数
     */
    public function import(): int
    {
        $rows = DatasetReader::list('hotspots.json');
        if ($rows === []) {
            echo '[HotspotDatasetImporter] 跳过时政热点：dataset 缺失' . PHP_EOL;
            return 0;
        }

        $subjectIds = $this->subjectIds();

        // 清空时政长文（保留 dev.2 的卡片式热点：其 slug 为 NULL）
        Hotspot::query()->whereNotNull('slug')->delete();

        $now = DatasetReader::now();

        foreach ($rows as $row) {
            $period = (string) ($row['period'] ?? '');
            $card = self::CARD_MAP[$period] ?? ['slug' => 'xi-jinping-thought', 'level' => 'A', 'type' => '形势与政策', 'tag' => '时政'];
            $module = (string) ($row['module'] ?? '');

            // 本地审核后才产出 dataset，入库即发布（无云端筛选态）
            Hotspot::create([
                'slug' => (string) $row['slug'],
                'title' => (string) $row['title'],
                'level' => (string) ($row['level'] ?? $card['level']),
                'priority' => (string) ($row['priority'] ?? 'A'),
                'period' => $period,
                'summary' => mb_substr((string) ($row['summary'] ?? ''), 0, 500),
                'type' => (string) ($row['type'] ?? $card['type']),
                'tag' => $module !== '' ? $module : (string) ($row['tag'] ?? $card['tag']),
                'html' => (string) ($row['html'] ?? ''),
                'outline' => $row['outline'] ?? [],
                'word_count' => (int) ($row['word_count'] ?? 0),
                'source_file' => (string) ($row['source_file'] ?? ''),
                'published_at' => $this->eventDate($row, $period),
                'subject_id' => $subjectIds[self::MODULE_SUBJECTS[$module] ?? $card['slug']] ?? 1,
                'status' => ContentStatus::PUBLISHED,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return Hotspot::query()->whereNotNull('slug')->count();
    }

    /** @return array<string, int> slug => subject_id */
    private function subjectIds(): array
    {
        $out = [];
        foreach (Db::table('subjects')->select('id', 'slug')->get() as $row) {
            $out[(string) $row->slug] = (int) $row->id;
        }
        return $out;
    }

    /**
     * 事件条目的发生日期：dataset 的 date 优先（YYYY-MM-DD），缺失时退回 period 的当月 1 日。
     *
     * @param array<string, mixed> $row
     */
    private function eventDate(array $row, string $period): string
    {
        $date = trim((string) ($row['date'] ?? ''));
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) === 1) {
            return $date;
        }
        return $this->periodToDate($period);
    }

    private function periodToDate(string $period): string
    {
        if (preg_match('/(\d{4})年(\d{1,2})月/u', $period, $m)) {
            return sprintf('%04d-%02d-01', (int) $m[1], (int) $m[2]);
        }
        return date('Y-m-d');
    }
}
