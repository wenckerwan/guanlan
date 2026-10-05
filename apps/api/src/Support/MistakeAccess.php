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

    /**
     * 编号比较口径：纯数字编号去掉前导零，兼容旧账号（如 "2"）与新编号（"000002"）。
     * 不做空白 trim：带空白的请求编号必须原样判为不匹配。
     */
    public static function normalizeCode(string $code): string
    {
        return ctype_digit($code) ? ltrim($code, '0') : $code;
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

        $bound = self::normalizeCode(trim((string) $user->mistake_code));
        if ($bound === '') {
            return false;
        }

        return hash_equals($bound, self::normalizeCode($code));
    }

    public static function canViewStudent(MistakeStudent $student, ?User $user = null): bool
    {
        return self::canViewCode((string) $student->code, $user);
    }

    /**
     * 错题写权限与读取权限分离：公开 A 仅管理员可写，其余错题仅所属账号或管理员可写。
     */
    public static function canWriteStudent(MistakeStudent $student, ?User $user = null): bool
    {
        $user ??= Auth::user();
        if (! $user instanceof User) {
            return false;
        }
        if (self::isAdmin($user)) {
            return true;
        }
        if ((string) $student->code === self::PUBLIC_CODE) {
            return false;
        }

        return (int) $user->id > 0
            && (int) ($student->owner_user_id ?? 0) === (int) $user->id;
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
                // 同时带上原始与归一化形式，命中新旧两种编号格式
                $codes[] = $bound;
                $codes[] = self::normalizeCode($bound);
            }
        }

        return array_values(array_unique($codes));
    }
}
