<?php

declare(strict_types=1);

namespace App\Resource;

use App\Model\Subject;

/**
 * 学科详情 DTO（camelCase，与前端 Subject 类型 1:1）。
 */
class SubjectResource
{
    public static function make(Subject $subject): array
    {
        return [
            'slug' => (string) $subject->slug,
            'name' => (string) $subject->name,
            'short' => (string) $subject->short,
            'tone' => (string) $subject->tone,
            'detail' => (string) $subject->detail,
            'intro' => (string) $subject->intro,
            'chapters' => ChapterResource::collection($subject->chapters->all()),
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
