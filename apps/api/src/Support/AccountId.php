<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final class AccountId
{
    public const WIDTH = 6;
    public const MAX = 999999;

    public static function format(int $number): string
    {
        if ($number < 1 || $number > self::MAX) {
            throw new InvalidArgumentException('账号 ID 必须在 1 到 999999 之间');
        }

        return str_pad((string) $number, self::WIDTH, '0', STR_PAD_LEFT);
    }
}
