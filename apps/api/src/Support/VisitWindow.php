<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

final class VisitWindow
{
    public static function shouldCount(?int $lastCountedAt, int $now): bool
    {
        return $lastCountedAt === null || $now - $lastCountedAt >= 3600;
    }

    public static function date(int $timestamp): string
    {
        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
    }
}
