<?php

declare(strict_types=1);

namespace App\Resource;

use App\Model\Hotspot;
use DateTimeInterface;

/**
 * 热点 DTO：updatedAt 输出 Y-m-d；subjectSlug / chapterId 由关联解析。
 */
class HotspotResource
{
    public static function make(Hotspot $hotspot): array
    {
        $updatedAt = $hotspot->updated_at;
        if ($updatedAt instanceof DateTimeInterface) {
            $updatedAtStr = $updatedAt->format('Y-m-d');
        } else {
            $updatedAtStr = $updatedAt ? substr((string) $updatedAt, 0, 10) : '';
        }

        return [
            'level' => (string) $hotspot->level,
            'title' => (string) $hotspot->title,
            'summary' => (string) $hotspot->summary,
            'type' => (string) $hotspot->type,
            'updatedAt' => $updatedAtStr,
            'tag' => (string) $hotspot->tag,
            'subjectSlug' => $hotspot->subject ? (string) $hotspot->subject->slug : '',
            'chapterId' => $hotspot->chapter ? (string) $hotspot->chapter->chapter_key : '',
        ];
    }

    /**
     * @param iterable<Hotspot> $hotspots
     * @return array<int, array>
     */
    public static function collection(iterable $hotspots): array
    {
        $result = [];
        foreach ($hotspots as $hotspot) {
            $result[] = self::make($hotspot);
        }
        return $result;
    }
}
