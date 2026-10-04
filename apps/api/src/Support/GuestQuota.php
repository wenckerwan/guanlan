<?php

declare(strict_types=1);

namespace App\Support;

use App\Model\User;

/**
 * 访客文章配额：真题分析 / 时政热点 / 时政预测各自可免费阅读的篇数。
 */
class GuestQuota
{
    public const FREE_PER_SECTION = 3;

    public static function isGuest(): bool
    {
        return ! Auth::user() instanceof User;
    }

    /** 游客且序号超出配额时锁定。 */
    public static function locked(int $index): bool
    {
        return self::isGuest() && $index >= self::FREE_PER_SECTION;
    }
}
