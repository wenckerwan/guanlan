<?php

declare(strict_types=1);

/**
 * 错题可见性与访客配额的纯逻辑测试。
 *
 * 不依赖 Hyperf 容器 / 数据库：用最小桩件替换 Auth 与模型，
 * 只验证「A 公开、其余仅绑定账号与管理员可见」「每栏目 3 篇」两条规则。
 */

namespace App\Model {
    class User
    {
        public function __construct(public string $role = 'user', public string $mistake_code = '')
        {
        }

        public function isAdmin(): bool
        {
            return $this->role === 'admin';
        }
    }

    class MistakeStudent
    {
        public function __construct(public string $code = '')
        {
        }
    }
}

namespace App\Support {
    use App\Model\User;

    class Auth
    {
        private static ?User $user = null;

        public static function setUser(?User $user): void
        {
            self::$user = $user;
        }

        public static function user(): ?User
        {
            return self::$user;
        }
    }

    require dirname(__DIR__) . '/src/Support/MistakeAccess.php';
    require dirname(__DIR__) . '/src/Support/GuestQuota.php';
}

namespace {

    use App\Model\MistakeStudent;
    use App\Model\User;
    use App\Support\Auth;
    use App\Support\GuestQuota;
    use App\Support\MistakeAccess;

    $failures = [];
    $check = static function (string $label, mixed $actual, mixed $expected) use (&$failures): void {
        if ($actual !== $expected) {
            $failures[] = sprintf('%s: expected %s, got %s', $label, var_export($expected, true), var_export($actual, true));
        }
    };

    $guest = null;
    $plain = new User('user', '');
    $bound7 = new User('user', '7');
    $admin = new User('admin', '');

    // A 是公开模板
    $check('guest sees A', MistakeAccess::canViewCode('A', $guest), true);
    $check('plain user sees A', MistakeAccess::canViewCode('A', $plain), true);
    $check('bound user sees A', MistakeAccess::canViewCode('A', $bound7), true);

    // 非 A 编号
    $check('guest blocked from 7', MistakeAccess::canViewCode('7', $guest), false);
    $check('unbound user blocked from 7', MistakeAccess::canViewCode('7', $plain), false);
    $check('bound user sees own 7', MistakeAccess::canViewCode('7', $bound7), true);
    $check('bound user blocked from 8', MistakeAccess::canViewCode('8', $bound7), false);
    $check('admin sees 7', MistakeAccess::canViewCode('7', $admin), true);
    $check('admin sees 999', MistakeAccess::canViewCode('999', $admin), true);
    // 大小写/空白不应误判为同一编号
    $check('bound user blocked from padded id', MistakeAccess::canViewCode(' 7 ', $bound7), false);
    // 旧账号编号（"7"）与新格式零填充编号（"000007"）应视为同一编号
    $check('bound user sees zero-padded own code', MistakeAccess::canViewCode('000007', $bound7), true);
    $boundPadded = new User('user', '000007');
    $check('zero-padded bound user sees short code', MistakeAccess::canViewCode('7', $boundPadded), true);
    $check('zero-padded bound user blocked from 8', MistakeAccess::canViewCode('8', $boundPadded), false);
    $check('bound user blocked from leading-zero variant of 8', MistakeAccess::canViewCode('08', $bound7), false);

    $student = new MistakeStudent('9');
    $check('student helper blocks guest', MistakeAccess::canViewStudent($student, $guest), false);
    $check('student helper allows admin', MistakeAccess::canViewStudent($student, $admin), true);

    $check('allowedCodes admin is unlimited', MistakeAccess::allowedCodes($admin), null);
    $codes = MistakeAccess::allowedCodes($guest);
    sort($codes);
    $check('allowedCodes guest is A only', $codes, ['A']);
    $codes = MistakeAccess::allowedCodes($bound7);
    sort($codes);
    $check('allowedCodes bound user is A + own', $codes, ['7', 'A']);

    // 访客配额：每栏目 3 篇
    Auth::setUser($guest);
    $check('guest index 0 unlocked', GuestQuota::locked(0), false);
    $check('guest index 2 unlocked', GuestQuota::locked(2), false);
    $check('guest index 3 locked', GuestQuota::locked(3), true);
    $check('guest index 99 locked', GuestQuota::locked(99), true);

    Auth::setUser($plain);
    $check('logged-in index 99 unlocked', GuestQuota::locked(99), false);

    if ($failures !== []) {
        fwrite(STDERR, "MistakeAccessTest: FAIL\n" . implode("\n", $failures) . "\n");
        exit(1);
    }

    echo "MistakeAccessTest: PASS\n";
}
