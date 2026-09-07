<?php

declare(strict_types=1);

namespace App\Controller;

use Psr\Http\Message\ResponseInterface;

final class HealthController
{
    public function index(): array
    {
        return ['status' => 'ok', 'service' => 'guanlan-api'];
    }

    public function home(): array
    {
        return [
            'brand' => ['name' => '观澜', 'subtitle' => '考研政治知识库'],
            'updated_at' => '2026-09-06',
            'hotspots' => [
                ['level' => 'S', 'title' => '十五五规划与开局之年', 'tag' => '重点命题包'],
                ['level' => 'S', 'title' => '党的二十届五中全会', 'tag' => '持续跟踪'],
                ['level' => 'S', 'title' => '长征胜利 90 周年', 'tag' => '史纲重点'],
                ['level' => 'A', 'title' => '2026 APEC 中国主场', 'tag' => '11月会议'],
            ],
        ];
    }
}
