<?php

declare(strict_types=1);

namespace App\Resource;

use App\Model\MistakeHandbook;
use App\Model\MistakeItem;
use App\Model\MistakeStudent;

class MistakeResource
{
    public static function student(MistakeStudent $student, array $moduleCounts = [], array $errorTypes = []): array
    {
        return [
            'code' => (string) $student->code,
            'name' => (string) $student->name,
            'relation' => (string) $student->relation,
            'itemCount' => (int) $student->items()->count(),
            'moduleCounts' => (object) $moduleCounts,
            'errorTypes' => (object) $errorTypes,
        ];
    }

    public static function item(MistakeItem $item, ?string $personalAction = null): array
    {
        return [
            'id' => (int) $item->id,
            'module' => (string) $item->module,
            'chapter' => (string) $item->chapter,
            'sourceNo' => (string) $item->source_no,
            'kaodian' => (string) $item->kaodian,
            'stem' => (string) $item->stem,
            'options' => array_values((array) ($item->options ?? [])),
            'myAnswer' => (string) $item->my_answer,
            'correctAnswer' => (string) $item->correct_answer,
            'qType' => (string) $item->q_type,
            'errorType' => (string) $item->error_type,
            'action' => (string) $item->action,
            'personalAction' => (string) ($personalAction ?? ''),
        ];
    }

    /** @param iterable<MistakeItem> $items @param array<int, string> $personalActions 以 mistake_item_id 为键 */
    public static function items(iterable $items, array $personalActions = []): array
    {
        $out = [];
        foreach ($items as $item) {
            $out[] = self::item($item, $personalActions[(int) $item->id] ?? null);
        }
        return $out;
    }

    public static function handbook(MistakeHandbook $handbook, bool $withHtml = false): array
    {
        $data = [
            'id' => (int) $handbook->id,
            'studentCode' => (string) ($handbook->student?->code ?? ''),
            'module' => (string) $handbook->module,
            'title' => (string) $handbook->title,
            'sectionCount' => count((array) ($handbook->sections ?? [])),
            'sections' => array_values(array_map(
                static fn ($section) => [
                    'title' => (string) ($section['title'] ?? ''),
                    'html' => (string) ($section['html'] ?? ''),
                ],
                (array) ($handbook->sections ?? [])
            )),
        ];

        if ($withHtml) {
            $data['html'] = (string) $handbook->html;
        }

        return $data;
    }

    /** @param iterable<MistakeHandbook> $handbooks */
    public static function handbooks(iterable $handbooks): array
    {
        $out = [];
        foreach ($handbooks as $handbook) {
            $out[] = self::handbook($handbook);
        }
        return $out;
    }
}
