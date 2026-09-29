<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\Model;

class AdminSetting extends Model
{
    protected ?string $table = 'admin_settings';

    protected array $guarded = [];

    public static function getValue(string $key, ?string $default = null): ?string
    {
        $row = self::query()->where('key', $key)->first();
        return $row ? (string) $row->value : $default;
    }

    public static function putValue(string $key, string $value): void
    {
        $row = self::query()->firstOrNew(['key' => $key]);
        $row->value = $value;
        $row->save();
    }
}
