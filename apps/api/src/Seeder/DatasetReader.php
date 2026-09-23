<?php

declare(strict_types=1);

namespace App\Seeder;

use Hyperf\DbConnection\Db;

/**
 * 数据集读取助手：storage/dataset/*.json（由 tools/ingest/build_all.py 生成）。
 */
class DatasetReader
{
    /** @return array<int, array<string, mixed>> */
    public static function list(string $name): array
    {
        $path = BASE_PATH . '/storage/dataset/' . $name;
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string, mixed> */
    public static function map(string $name): array
    {
        $data = self::list($name);
        return $data;
    }

    public static function missing(string $name): bool
    {
        return ! is_file(BASE_PATH . '/storage/dataset/' . $name);
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE) ?: 'null';
    }

    public static function truncate(string $table): void
    {
        Db::table($table)->delete();
    }
}
