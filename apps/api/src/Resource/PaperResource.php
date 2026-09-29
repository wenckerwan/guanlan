<?php

declare(strict_types=1);

namespace App\Resource;

use App\Model\Paper;

class PaperResource
{
    public static function make(Paper $paper): array
    {
        return [
            'id' => (int) $paper->id,
            'pid' => (string) $paper->pid,
            'year' => (int) $paper->year,
            'label' => (string) $paper->label,
            'kind' => (string) $paper->kind,
            'questionCount' => (int) $paper->question_count,
            'totalScore' => (int) $paper->total_score,
            'answeredCount' => (int) $paper->answered_count,
            'sections' => array_values(array_map(
                static fn ($section) => [
                    'key' => (string) ($section['key'] ?? ''),
                    'per' => $section['per'] ?? null,
                    'total' => $section['total'] ?? null,
                    'raw' => (string) ($section['raw'] ?? ''),
                ],
                (array) ($paper->sections ?? [])
            )),
        ];
    }

    /** @param iterable<Paper> $papers */
    public static function collection(iterable $papers): array
    {
        $out = [];
        foreach ($papers as $paper) {
            $out[] = self::make($paper);
        }
        return $out;
    }
}
