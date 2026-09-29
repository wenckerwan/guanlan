<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 用户组体系：guest（未注册）不落库，仅登录用户有 user_group。
 * 管理员由 role=admin 判定，展示层级高于所有组。
 */
class UserGroup
{
    public const USER = 'user';
    public const VIP = 'vip';
    public const SVIP = 'svip';
    public const SSSVIP = 'sssvip';

    public const ALL = [self::USER, self::VIP, self::SVIP, self::SSSVIP];

    /** 组 → 权益（前端按 features 隐藏/置灰入口） */
    private const FEATURES = [
        self::USER => ['aiReportDailyLimit' => 5],
        self::VIP => ['aiReportDailyLimit' => 20],
        self::SVIP => ['aiReportDailyLimit' => 50],
        self::SSSVIP => ['aiReportDailyLimit' => 200],
    ];

    public static function normalize(?string $group): string
    {
        return in_array($group, self::ALL, true) ? $group : self::USER;
    }

    public static function features(string $group): array
    {
        return self::FEATURES[self::normalize($group)] ?? self::FEATURES[self::USER];
    }
}
