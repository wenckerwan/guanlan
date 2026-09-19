<?php

declare(strict_types=1);

namespace App\Resource;

use App\Model\Chapter;

/**
 * 章节 DTO：对外 id 使用 chapter_key。
 */
class ChapterResource
{
    public static function make(Chapter $chapter): array
    {
        return [
            'id' => (string) $chapter->chapter_key,
            'title' => (string) $chapter->title,
            'summary' => (string) $chapter->summary,
            'points' => KnowledgePointResource::collection($chapter->knowledgePoints->all()),
        ];
    }

    /**
     * @param iterable<Chapter> $chapters
     * @return array<int, array>
     */
    public static function collection(iterable $chapters): array
    {
        $result = [];
        foreach ($chapters as $chapter) {
            $result[] = self::make($chapter);
        }
        return $result;
    }
}
