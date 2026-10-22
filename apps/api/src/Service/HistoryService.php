<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\HistoryDataset;
use Hyperf\Contract\StdoutLoggerInterface;

/**
 * 史纲数据集读取：取最新已发布版本。
 * 进程内静态缓存，数据集不常变；缓存键为最新发布行指纹（id），发布新版本后指纹变化自动失效。
 */
class HistoryService
{
    private static ?array $cache = null;

    private static ?int $cacheId = null;

    public function __construct(private StdoutLoggerInterface $logger)
    {
    }

    /**
     * 返回最新已发布 HistoryDataset（顶层字段 version/title/range/topics/sources/events/comparisons/generatedAt）。
     * 发布数据包可能夹带审核材料；服务端只输出已公开事件和日期断言。
     */
    public function latestPublished(): ?array
    {
        $row = HistoryDataset::query()
            ->where('status', 'published')
            ->orderByDesc('id')
            ->first(['id', 'version', 'payload']);
        if (! $row) {
            return null;
        }
        $id = (int) $row->id;
        if (self::$cache !== null && self::$cacheId === $id) {
            return self::$cache;
        }
        $payload = $row->payload;
        if (! is_array($payload)) {
            $this->logger->warning('history dataset payload 非数组', ['id' => $id]);
            return null;
        }
        $isPublished = static fn ($item): bool => is_array($item)
            && in_array($item['publication'] ?? null, ['original', 'verified'], true);
        $payload['events'] = array_values(array_filter($payload['events'] ?? [], $isPublished));
        // Current comparison schema is editorial-only (publication=pending).
        // Never trust a top-level relabel to publish nested correction drafts.
        $payload['comparisons'] = [];
        foreach ($payload['events'] as &$event) {
            foreach (['original', 'textbook'] as $source) {
                $date = $event['dates'][$source] ?? null;
                if (!is_array($date) || !in_array($date['status'] ?? null, ['original', 'verified'], true)) {
                    $event['dates'][$source] = null;
                }
            }
        }
        unset($event);
        self::$cache = $payload;
        self::$cacheId = $id;

        return $payload;
    }
}
