<?php
declare(strict_types=1);

namespace App\Support;

/** Date labels are Beijing days; timestamp bounds are UTC, upper exclusive. */
final class AdminDateRange
{
    public readonly ?\DateTimeImmutable $start;
    public readonly ?\DateTimeImmutable $end;

    public function __construct(public readonly string $from, public readonly string $to)
    {
        $this->start = $from === '' ? null : self::parse($from);
        $this->end = $to === '' ? null : self::parse($to);
        if ($this->start && $this->end && ($this->start > $this->end || (int)$this->start->diff($this->end)->days > 365)) {
            throw new \RuntimeException('日期范围必须正序且不超过366天', 422);
        }
    }

    public static function overview(string $from, string $to): self
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Asia/Shanghai'));
        return new self($from === '' ? $today->modify('-13 days')->format('Y-m-d') : $from, $to === '' ? $today->format('Y-m-d') : $to);
    }

    private static function parse(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Asia/Shanghai'));
        if (!$date || $date->format('Y-m-d') !== $value || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) || $value < '1000-01-01' || $value > '9999-12-30') {
            throw new \RuntimeException('日期必须为有效的YYYY-MM-DD', 422);
        }
        return $date;
    }

    public function apply(object $query, string $column): object
    {
        $utc = new \DateTimeZone('UTC');
        if ($this->start) $query->where($column, '>=', $this->start->setTimezone($utc)->format('Y-m-d H:i:s'));
        if ($this->end) $query->where($column, '<', $this->end->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'));
        return $query;
    }

    /** @return list<string> */
    public function dates(): array
    {
        $dates=[];
        if (!$this->start || !$this->end) return $dates;
        for ($day=$this->start; $day <= $this->end; $day=$day->modify('+1 day')) $dates[]=$day->format('Y-m-d');
        return $dates;
    }

    public static function utcIso(?string $timestamp): ?string
    {
        return $timestamp === null || $timestamp === '' ? null : (new \DateTimeImmutable($timestamp, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
