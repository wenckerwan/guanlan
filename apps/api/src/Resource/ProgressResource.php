<?php

declare(strict_types=1);

namespace App\Resource;

use App\Model\StudyProgress;

class ProgressResource
{
    public static function make(StudyProgress $progress): array
    {
        return [
            'id' => (int) $progress->id,
            'scope' => (string) $progress->scope,
            'ref' => (string) $progress->ref,
            'label' => (string) $progress->label,
            'status' => (string) $progress->status,
            'progress' => (int) $progress->progress,
            'correctCount' => (int) $progress->correct_count,
            'wrongCount' => (int) $progress->wrong_count,
            'lastSeenAt' => (string) ($progress->last_seen_at ?? ''),
        ];
    }

    /** @param iterable<StudyProgress> $items */
    public static function collection(iterable $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $out[] = self::make($item);
        }
        return $out;
    }
}
