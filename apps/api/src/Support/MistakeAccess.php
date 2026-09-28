<?php

declare(strict_types=1);

namespace App\Support;

use App\Model\MistakeStudent;
use App\Model\User;

/**
 * 错题可见性：A 为公开模板，其余编号仅绑定账号与管理员可见。
 */
class MistakeAccess
{
    public const PUBLIC_CODE = 'A';

    public static function isAdmin(?User $user = null): bool
    {
        $user ??= Auth::user();

        return $user instanceof User && $user->isAdmin();
    }

    public static function isGuest(?User $user = null): bool
    {
        $user ??= Auth::user();

        return ! $user instanceof User;
    }

    public static function canViewCode(string $code, ?User $user = null): bool
    {
        $user ??= Auth::user();
        if (self::isAdmin($user)) {
            return true;
        }
        if ($code === self::PUBLIC_CODE) {
            return true;
        }
        if (! $user instanceof User) {
            return false;
        }

        $bound = trim((string) $user->mistake_code);

        return $bound !== '' && hash_equals($bound, $code);
    }

    public static function canViewStudent(MistakeStudent $student, ?User $user = null): bool
    {
        return self::canViewCode((string) $student->code, $user);
    }

    /**
     * 当前访问者可见的考生编号白名单；管理员返回 null 表示不受限。
     *
     * @return array<int, string>|null
     */
    public static function allowedCodes(?User $user = null): ?array
    {
        $user ??= Auth::user();
        if (self::isAdmin($user)) {
            return null;
        }

        $codes = [self::PUBLIC_CODE];
        if ($user instanceof User) {
            $bound = trim((string) $user->mistake_code);
            if ($bound !== '') {
                $codes[] = $bound;
            }
        }

        return array_values(array_unique($codes));
    }
}
