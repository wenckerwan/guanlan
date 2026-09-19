<?php

declare(strict_types=1);

namespace App\Resource;

use App\Model\KnowledgePoint;

/**
 * 知识点 DTO。
 */
class KnowledgePointResource
{
    public static function make(KnowledgePoint $point): array
    {
        return [
            'title' => (string) $point->title,
            'summary' => (string) $point->summary,
        ];
    }

    /**
     * @param iterable<KnowledgePoint> $points
     * @return array<int, array>
     */
    public static function collection(iterable $points): array
    {
        $result = [];
        foreach ($points as $point) {
            $result[] = self::make($point);
        }
        return $result;
    }
}
