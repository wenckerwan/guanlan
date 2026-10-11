<?php
declare(strict_types=1);

if (! str_ends_with((string) getenv('DB_DATABASE'), '_admin_test')) {
    throw new RuntimeException('Disposable _admin_test database required');
}
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';
$container = require BASE_PATH . '/config/container.php';
Hyperf\Database\Model\Register::setConnectionResolver($container->get(Hyperf\Database\ConnectionResolverInterface::class));

$failed = false;
Swoole\Coroutine\run(static function () use (&$failed): void {
    $pdo = new PDO('mysql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (['practice_attempts', 'practice_questions', 'practice_banks'] as $table) {
        $pdo->exec('DROP TABLE IF EXISTS ' . $table);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    require BASE_PATH . '/migrations/2026_10_11_000001_create_practice_tables.php';
    (new CreatePracticeTables())->up();
    $pdo->exec("CREATE TABLE IF NOT EXISTS favorites (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED, target_type VARCHAR(32), target_id VARCHAR(191))");
    $package = App\Service\PracticeBankImporter::readPackage(BASE_PATH . '/resources/private/current-affairs-2026.json');
    $importer = new App\Service\PracticeBankImporter();
    $service = new App\Service\PracticeStudyService();
    $check = static function (bool $ok, string $message): void {
        if (! $ok) {
            throw new RuntimeException($message);
        }
    };
    $expectError = static function (callable $fn, int $code) use ($check): void {
        try {
            $fn();
        } catch (RuntimeException $error) {
            $check($error->getCode() === $code, 'wrong error code: ' . $error->getCode());
            return;
        }
        throw new RuntimeException('expected error ' . $code);
    };
    $checks = [];
    $run = static function (string $name, callable $fn) use (&$checks): void {
        try { $fn(); echo "PASS $name\n"; } catch (Throwable $error) { $checks[] = $name . ': ' . $error->getMessage(); echo "FAIL " . end($checks) . "\n"; }
    };
    $run('source package canonical and complete', static function () use ($package, $check): void {
        $check(count($package['questions']) === 101, 'question count');
        $check(array_sum(array_map(static fn ($q) => count($q['options']), $package['questions'])) === 404, 'option count');
        $check(array_count_values(array_column($package['questions'], 'type')) === ['single' => 52, 'multi' => 49], 'type counts');
        foreach ($package['questions'] as $question) {
            $check(App\Service\PracticeBankImporter::questionVersion($question) === $question['questionVersion'], 'hash mismatch ' . $question['questionRef']);
        }
    });
    $run('default draft and repeat import retain 101 records', static function () use ($importer, $package, $pdo, $check, $service, $expectError): void {
        $result = $importer->import($package);
        $check($result['status'] === 'draft', 'default publish changed');
        $check((int) $pdo->query('SELECT COUNT(*) FROM practice_questions')->fetchColumn() === 101, 'first import count');
        $importer->import($package);
        $check((int) $pdo->query('SELECT COUNT(*) FROM practice_questions')->fetchColumn() === 101, 'duplicate import');
        $expectError(static fn () => $service->bank('current-affairs-2026', null), 404);
    });
    $importer->import($package, true);
    $userA = App\Model\User::create(['email' => 'practice-a-' . bin2hex(random_bytes(4)) . '@example.test', 'display_name' => 'Practice A', 'password_hash' => 'test', 'role' => 'user', 'status' => 'active']);
    $userB = App\Model\User::create(['email' => 'practice-b-' . bin2hex(random_bytes(4)) . '@example.test', 'display_name' => 'Practice B', 'password_hash' => 'test', 'role' => 'user', 'status' => 'active']);
    $byRef = array_column($package['questions'], null, 'questionRef');
    $event = static fn () => sprintf('%08x-%04x-4%03x-a%03x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0, 0xfff), random_int(0, 0xffffffffffff));
    $input = static fn (string $ref, array $chosen, string $uuid) => ['bankId' => 'current-affairs-2026', 'questionRef' => $ref, 'questionVersion' => $byRef[$ref]['questionVersion'], 'eventId' => $uuid, 'chosen' => $chosen];
    $run('guest sees six and user sees 101', static function () use ($service, $userA, $check): void {
        $guest = $service->bank('current-affairs-2026', null);
        $full = $service->bank('current-affairs-2026', $userA);
        $check(count($guest['questions']) === 6 && count($full['questions']) === 101, 'visibility');
    });
    $run('first wrong then right; retry remains one event', static function () use ($service, $userA, $input, $event, $check, $pdo, $expectError): void {
        $wrong = $service->submit($userA, $input('SZ08-1', ['A'], $event()));
        $id = $event();
        $request = $input('SZ08-1', ['C'], $id);
        $right = $service->submit($userA, $request);
        $retry = $service->submit($userA, $request);
        $s = $service->state($userA, 'current-affairs-2026')['summary'];
        $check($wrong['isRight'] === false && $right['isRight'] === true && $retry['duplicate'] === true, 'judging/retry');
        $check($s['submissionCount'] === 2 && $s['uniqueQuestionCount'] === 1 && $s['firstAccuracy'] === 0.0 && $s['latestAccuracy'] === 100.0 && $s['repeatCount'] === 1, 'metrics');
        $check((int) $pdo->query('SELECT COUNT(*) FROM practice_attempts')->fetchColumn() === 2, 'duplicate inserted');
        $expectError(static fn () => $service->submit($userA, $input('SZ08-1', ['B'], $id)), 409);
    });
    $run('multi partial and reordered full set', static function () use ($service, $userA, $input, $event, $check): void {
        $partial = $service->submit($userA, $input('SZ08-2', ['A', 'C'], $event()));
        $full = $service->submit($userA, $input('SZ08-2', ['C', 'B', 'A'], $event()));
        $check($partial['isRight'] === false && $full['isRight'] === true, 'multi grading');
    });
    $run('invalid submissions and user isolation', static function () use ($service, $userA, $userB, $input, $event, $expectError, $check): void {
        $expectError(static fn () => $service->submit($userA, $input('SZ08-1', [], $event())), 422);
        $expectError(static fn () => $service->submit($userA, $input('SZ08-1', ['E'], $event())), 422);
        $expectError(static fn () => $service->submit($userA, $input('SZ08-1', ['A', 'B'], $event())), 422);
        $check($service->history($userB)['total'] === 0, 'history leak');
        $check($service->summary($userB)['total'] === 0, 'aggregate leak');
    });
    $run('saved retry survives version replacement and unpublish', static function () use ($service, $userA, $input, $event, $package, $importer, $check, $pdo, $expectError): void {
        $uuid = $event();
        $request = $input('SZ08-1', ['C'], $uuid);
        $first = $service->submit($userA, $request);
        $changed = $package;
        $changed['questions'][0]['answer'] = 'A';
        $changed['questions'][0]['questionVersion'] = App\Service\PracticeBankImporter::questionVersion($changed['questions'][0]);
        $importer->import($changed, true);
        $retry = $service->submit($userA, $request);
        $check($retry['attemptId'] === $first['attemptId'] && $retry['isRight'] === true && $retry['duplicate'] === true, 'version change lost saved result');
        $expectError(static fn () => $service->submit($userA, $input('SZ08-1', ['C'], $event())), 409);
        $pdo->exec("UPDATE practice_banks SET status='draft' WHERE slug='current-affairs-2026'");
        $retry = $service->submit($userA, $request);
        $check($retry['attemptId'] === $first['attemptId'] && $retry['duplicate'] === true, 'unpublish lost saved result');
    });
    echo 'RESULT ' . (count($checks) === 0 ? 'PASS' : implode('; ', $checks)) . "\n";
    $failed = $checks !== [];
});
exit($failed ? 1 : 0);
