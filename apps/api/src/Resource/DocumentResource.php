<?php

declare(strict_types=1);

namespace App\Resource;

use App\Model\Document;

/**
 * 资料卡 DTO（与前端 recentDocuments 字段一致）。
 */
class DocumentResource
{
    public static function make(Document $document): array
    {
        return [
            'title' => (string) $document->title,
            'meta' => (string) $document->meta,
            'progress' => (int) $document->progress,
            'cover' => (string) $document->cover,
            'tone' => (string) $document->tone,
        ];
    }

    /**
     * @param iterable<Document> $documents
     * @return array<int, array>
     */
    public static function collection(iterable $documents): array
    {
        $result = [];
        foreach ($documents as $document) {
            $result[] = self::make($document);
        }
        return $result;
    }
}
