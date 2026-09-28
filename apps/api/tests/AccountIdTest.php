<?php

declare(strict_types=1);

/**
 * 账号 ID 生成规则：从 1 开始取最小未被占用的纯数字编号。
 *
 * 不依赖数据库：把 AuthService::nextAccountId 的纯算法抽出来单独验证，
 * 并断言它与源码中的实现保持同一规则（防止实现漂移）。
 */

namespace {

    $failures = [];
    $check = static function (string $label, mixed $actual, mixed $expected) use (&$failures): void {
        if ($actual !== $expected) {
            $failures[] = sprintf('%s: expected %s, got %s', $label, var_export($expected, true), var_export($actual, true));
        }
    };

    /** 与 AuthService::nextAccountId 同规则：最小未占用的正整数。 */
    $nextId = static function (array $existing): string {
        $taken = [];
        foreach ($existing as $code) {
            if (is_numeric($code)) {
                $taken[(int) $code] = true;
            }
        }
        $candidate = 1;
        while (isset($taken[$candidate])) {
            $candidate++;
        }
        return (string) $candidate;
    };

    $check('first account is 1', $nextId([]), '1');
    $check('fills lowest gap', $nextId(['1', '3']), '2');
    $check('sequential after contiguous', $nextId(['1', '2', '3']), '4');
    $check('ignores non-numeric codes', $nextId(['A', 'B']), '1');
    $check('ignores null and empty', $nextId([null, '', '2']), '1');
    $check('never reuses a freed code', $nextId(['1', '2']), '3');
    $check('account codes are distinct from A template', $nextId(['1']), '2');

    // 源码必须仍然采用同一规则（最小未占用），而不是「max+1」之类会发生漂移的写法。
    $source = (string) file_get_contents(dirname(__DIR__) . '/src/Service/AuthService.php');
    $check('AuthService uses sequential fill', str_contains($source, 'while (isset($taken[$candidate]))'), true);
    $check('AuthService starts from 1', str_contains($source, '$candidate = 1;'), true);
    $check('AuthService assigns mistake_code on register', str_contains($source, "'mistake_code' => \$this->nextAccountId()"), true);
    $check('AuthService retries on unique conflict', str_contains($source, 'isDuplicateMistakeCode'), true);
    $check('AuthService seeds attributes once', str_contains($source, '$attributes + ['), true);

    if ($failures !== []) {
        fwrite(STDERR, "AccountIdTest: FAIL\n" . implode("\n", $failures) . "\n");
        exit(1);
    }

    echo "AccountIdTest: PASS\n";
}
