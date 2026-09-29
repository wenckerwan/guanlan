<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 后台可编辑内容（热点、真题分析）的发布状态。
 * 前台所有出口（列表 / 详情 / 首页卡片 / 搜索）统一按 isVisible 过滤。
 */
class ContentStatus
{
    public const PUBLISHED = 'published';
    public const HIDDEN = 'hidden';

    public const ALL = [self::PUBLISHED, self::HIDDEN];

    /**
     * 归一化后台传入的状态：非法或空值回退 published，避免把内容意外藏掉。
     */
    public static function normalize(?string $status): string
    {
        return in_array($status, self::ALL, true) ? $status : self::PUBLISHED;
    }

    /**
     * 前台可见性：仅 hidden 不可见；null / 未知值按可见处理（兼容迁移前的旧行）。
     */
    public static function isVisible(?string $status): bool
    {
        return $status !== self::HIDDEN;
    }
}
