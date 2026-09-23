<?php

declare(strict_types=1);

namespace App\Resource;

use App\Model\Note;

class NoteResource
{
    public static function make(Note $note): array
    {
        return [
            'id' => (int) $note->id,
            'targetType' => (string) $note->target_type,
            'targetId' => (string) $note->target_id,
            'title' => (string) $note->title,
            'content' => (string) $note->content,
            'createdAt' => (string) ($note->created_at ?? ''),
        ];
    }

    /** @param iterable<Note> $notes */
    public static function collection(iterable $notes): array
    {
        $out = [];
        foreach ($notes as $note) {
            $out[] = self::make($note);
        }
        return $out;
    }
}
