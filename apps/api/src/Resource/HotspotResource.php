<?php

declare(strict_types=1);

namespace App\Resource;

use App\Model\Hotspot;
use DateTimeInterface;

/**
 * 热点 DTO：首页卡片与时政长文共用。
 * updatedAt 输出 Y-m-d；subjectSlug / chapterId 由关联解析，长文类热点无关联时为空串。
 */
class HotspotResource
{
    public static function make(Hotspot $hotspot): array
    {
        return [
            'slug' => (string) ($hotspot->slug ?? ''),
            'level' => (string) $hotspot->level,
            'priority' => (string) ($hotspot->priority ?? $hotspot->level),
            'title' => (string) $hotspot->title,
            'summary' => (string) $hotspot->summary,
            'type' => (string) $hotspot->type,
            'period' => (string) ($hotspot->period ?? ''),
            'updatedAt' => self::date($hotspot->updated_at),
            'tag' => (string) $hotspot->tag,
            'subjectSlug' => $hotspot->subject ? (string) $hotspot->subject->slug : '',
            'chapterId' => $hotspot->chapter ? (string) $hotspot->chapter->chapter_key : '',
            'url' => $hotspot->slug ? "/hotspots/{$hotspot->slug}" : '',
        ];
    }

    /** @param iterable<Hotspot> $hotspots */
    public static function collection(iterable $hotspots): array
    {
        $result = [];
        foreach ($hotspots as $hotspot) {
            $result[] = self::make($hotspot);
        }
        return $result;
    }

    private static function date(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        return $value ? substr((string) $value, 0, 10) : '';
    }
}
