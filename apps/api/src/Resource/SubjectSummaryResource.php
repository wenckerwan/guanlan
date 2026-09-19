<?php

declare(strict_types=1);

namespace App\Resource;

use App\Model\Subject;

/**
 * 首页学科摘要 DTO：count 取 asset_count（首页摘要数量）。
 */
class SubjectSummaryResource
{
    public static function make(Subject $subject): array
    {
        return [
            'slug' => (string) $subject->slug,
            'name' => (string) $subject->name,
            'short' => (string) $subject->short,
            'tone' => (string) $subject->tone,
            'detail' => (string) $subject->detail,
            'count' => (int) $subject->asset_count,
        ];
    }

    /**
     * @param iterable<Subject> $subjects
     * @return array<int, array>
     */
    public static function collection(iterable $subjects): array
    {
        $result = [];
        foreach ($subjects as $subject) {
            $result[] = self::make($subject);
        }
        return $result;
    }
}
