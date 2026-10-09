<?php
declare(strict_types=1);
namespace App\Support;

use Hyperf\DbConnection\Db;

/** Serializes admin maintenance and destructive dataset imports. */
class ContentMaintenance
{
    private static function lock(array $tables): array
    {
        sort($tables);
        $rows = [];
        foreach (array_unique($tables) as $table) {
            $row = Db::table('content_maintenance')->where('table_name', $table)->lockForUpdate()->first();
            if (!$row) throw new \RuntimeException('缺少内容维护迁移：' . $table);
            $rows[] = $row;
        }
        return $rows;
    }

    public static function write(string $table, callable $operation): mixed
    {
        return Db::transaction(function () use ($table, $operation) {
            self::lock([$table]);
            $result = $operation();
            if ($result !== null && $result !== false && !(is_array($result) && isset($result['blocked']))) {
                Db::table('content_maintenance')->where('table_name', $table)->update(['maintained' => 1, 'updated_at' => date('Y-m-d H:i:s')]);
            }
            return $result;
        });
    }

    public static function assertImportAllowed(array $tables): void
    {
        foreach (self::lock($tables) as $row) {
            if ($row->maintained) throw new \RuntimeException('拒绝覆盖后台维护内容：' . $row->table_name . '。请先备份并对账源数据与数据库，再由维护人员解除导入保护。');
        }
    }

    public static function import(array $tables, callable $operation): void
    {
        Db::transaction(function () use ($tables, $operation) {
            self::assertImportAllowed($tables);
            $operation();
        });
    }
}
