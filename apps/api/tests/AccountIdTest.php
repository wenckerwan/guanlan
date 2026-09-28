<?php

declare(strict_types=1);

namespace {
    require dirname(__DIR__) . '/src/Support/AccountId.php';

    use AppSupportAccountId;

    $failures = [];
    $check = static function (string $label, mixed $actual, mixed $expected) use (&$failures): void {
        if ($actual !== $expected) {
            $failures[] = sprintf('%s: expected %s, got %s', $label, var_export($expected, true), var_export($actual, true));
        }
    };

    $check('admin account is 000001', AccountId::format(1), '000001');
    $check('first normal account follows admin', AccountId::format(2), '000002');
    $check('millionth id is rejected', (static function (): bool {
        try { AccountId::format(1000000); return false; } catch (InvalidArgumentException) { return true; }
    })(), true);

    $auth = (string) file_get_contents(dirname(__DIR__) . '/src/Service/AuthService.php');
    $accountService = (string) file_get_contents(dirname(__DIR__) . '/src/Service/MistakeAccountService.php');
    $migration = (string) file_get_contents(dirname(__DIR__) . '/migrations/2026_09_28_000001_create_mistake_accounts_table.php');
    $check('registration provisions account in transaction', str_contains($auth, 'Db::transaction') && str_contains($auth, 'mistakeAccounts->provision'), true);
    $check('account service creates account', str_contains($accountService, 'MistakeAccount::create'), true);
    $check('migration assigns admin first', str_contains($migration, "role = 'admin'") && str_contains($migration, '$number = 1'), true);
    $check('account migration exists', str_contains($migration, 'mistake_accounts'), true);

    if ($failures !== []) {
        fwrite(STDERR, "AccountIdTest: FAIL
" . implode("
", $failures) . "
");
        exit(1);
    }
    echo "AccountIdTest: PASS
";
}
