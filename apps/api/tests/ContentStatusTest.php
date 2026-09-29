<?php

declare(strict_types=1);

/**
 * ContentStatus 纯逻辑测试：发布/隐藏状态归一化与前台可见性。
 * 不依赖容器与数据库，直接 require 支持类。
 */

require dirname(__DIR__) . '/src/Support/ContentStatus.php';

use App\Support\ContentStatus;

$failures = [];
$check = static function (string $label, mixed $actual, mixed $expected) use (&$failures): void {
    if ($actual !== $expected) {
        $failures[] = sprintf('%s: expected %s, got %s', $label, var_export($expected, true), var_export($actual, true));
    }
};

// normalize：后台写入口径
$check('normalize published', ContentStatus::normalize('published'), 'published');
$check('normalize hidden', ContentStatus::normalize('hidden'), 'hidden');
$check('normalize null falls back to published', ContentStatus::normalize(null), 'published');
$check('normalize empty falls back to published', ContentStatus::normalize(''), 'published');
$check('normalize garbage falls back to published', ContentStatus::normalize('HIDDEN'), 'published');
$check('normalize draft falls back to published', ContentStatus::normalize('draft'), 'published');

// isVisible：前台过滤口径
$check('published visible', ContentStatus::isVisible('published'), true);
$check('hidden invisible', ContentStatus::isVisible('hidden'), false);
$check('null visible (legacy rows)', ContentStatus::isVisible(null), true);
$check('unknown visible', ContentStatus::isVisible('draft'), true);

// ALL 常量完备
$check('all contains both', count(ContentStatus::ALL) === 2 && in_array('published', ContentStatus::ALL, true) && in_array('hidden', ContentStatus::ALL, true), true);

if ($failures !== []) {
    fwrite(STDERR, "ContentStatusTest: FAIL\n" . implode("\n", $failures) . "\n");
    exit(1);
}

echo "ContentStatusTest: PASS\n";
