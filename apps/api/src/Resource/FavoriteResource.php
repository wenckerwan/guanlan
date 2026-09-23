<?php

declare(strict_types=1);

namespace App\Resource;

use App\Model\Favorite;

class FavoriteResource
{
    public static function make(Favorite $favorite): array
    {
        return [
            'id' => (int) $favorite->id,
            'targetType' => (string) $favorite->target_type,
            'targetId' => (string) $favorite->target_id,
            'title' => (string) $favorite->title,
            'url' => (string) $favorite->url,
            'createdAt' => (string) ($favorite->created_at ?? ''),
        ];
    }

    /** @param iterable<Favorite> $favorites */
    public static function collection(iterable $favorites): array
    {
        $out = [];
        foreach ($favorites as $favorite) {
            $out[] = self::make($favorite);
        }
        return $out;
    }
}
