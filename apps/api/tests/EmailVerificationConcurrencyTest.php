<?php

declare(strict_types=1);

// Standalone real-MySQL test, following the existing *_admin_test convention.
// No .env loading and no SMTP traffic; only the mail transport is replaced.
namespace App\Support {
    class Mailer
    {
        public static bool $sent = true;
        public static int $calls = 0;
        public static function enabled(): bool { return true; }
        public static function send(string $to, string $subject, string $html): bool
        {
            ++self::$calls;
            if (\Hyperf\DbConnection\Db::transactionLevel() !== 0) {
                throw new \RuntimeException('SMTP executed inside transaction');
            }
            // A separate connection must already see the committed reservation.
            $query = \verificationTestPdo()->prepare('SELECT COUNT(*) FROM email_verifications WHERE email = ?');
            $query->execute([$to]);
            if ((int) $query->fetchColumn() < 1) {
                throw new \RuntimeException('SMTP ran before reservation commit');
            }
            return self::$sent;
        }
    }
}
namespace {
    if (! str_ends_with((string) getenv('DB_DATABASE'), '_admin_test')) {
        throw new RuntimeException('Disposable _admin_test database required');
    }
    define('BASE_PATH', dirname(__DIR__));
    require BASE_PATH . '/vendor/autoload.php';
    $container = require BASE_PATH . '/config/container.php';
    Hyperf\Database\Model\Register::setConnectionResolver($container->get(Hyperf\Database\ConnectionResolverInterface::class));

    function verificationTestPdo(): PDO
    {
        return new PDO('mysql:host=' . getenv('DB_HOST') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . getenv('DB_DATABASE'),
            (string) getenv('DB_USERNAME'), (string) getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
    function verificationTestService(): App\Service\AuthService
    {
        // Neither operation under test uses account provisioning.
        $accounts = (new ReflectionClass(App\Service\MistakeAccountService::class))->newInstanceWithoutConstructor();
        return new App\Service\AuthService($accounts);
    }
    function verificationTestCheck(bool $ok, string $message): void
    {
        if (! $ok) throw new RuntimeException($message);
    }
    function verificationTestRace(array $requests): array
    {
        $directory = sys_get_temp_dir() . '/verification-race-' . bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700)) throw new RuntimeException('Cannot create race barrier');
        $workers = [];
        try {
            foreach ($requests as $index => $request) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, __FILE__, '--worker', base64_encode(json_encode($request, JSON_THROW_ON_ERROR)), $directory, (string) $index],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                if (! is_resource($process)) throw new RuntimeException('Cannot start worker');
                fclose($pipes[0]);
                $workers[] = [$process, $pipes];
            }
            $deadline = microtime(true) + 30;
            while (count(glob($directory . '/ready-*') ?: []) !== count($requests)) {
                if (microtime(true) > $deadline) throw new RuntimeException('Worker readiness timed out');
                usleep(1000);
            }
            touch($directory . '/go');
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                $status = proc_close($process);
                verificationTestCheck($status === 0, 'Worker failed: ' . $error);
                $results[] = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
            }
            $workers = [];
            return $results;
        } finally {
            foreach ($workers as [$process, $pipes]) {
                if (is_resource($process)) { proc_terminate($process); proc_close($process); }
                foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            }
            foreach (glob($directory . '/*') ?: [] as $path) unlink($path);
            rmdir($directory);
        }
    }

    if (($argv[1] ?? '') === '--worker') {
        $request = json_decode(base64_decode($argv[2], true), true, 512, JSON_THROW_ON_ERROR);
        touch($argv[3] . '/ready-' . $argv[4]);
        $deadline = microtime(true) + 30;
        while (! is_file($argv[3] . '/go')) {
            if (microtime(true) > $deadline) throw new RuntimeException('Race start timed out');
            usleep(1000);
        }
        Swoole\Coroutine\run(static function () use ($request): void {
            $service = verificationTestService();
            $result = $request['operation'] === 'verify'
                ? $service->verifyRegisterCode($request['email'], $request['code'])
                : $service->sendRegisterCode($request['email'], $request['ip']);
            echo json_encode(['result' => $result], JSON_THROW_ON_ERROR) . PHP_EOL;
        });
        exit(0);
    }

    $failed = false;
    Swoole\Coroutine\run(static function () use (&$failed): void {
        try {
            $pdo = verificationTestPdo();
            foreach ([
                'email_verifications' => ['2026_09_30_000007_create_email_verifications_table.php', 'CreateEmailVerificationsTable'],
                'email_verification_locks' => ['2026_11_04_000001_create_email_verification_locks_table.php', 'CreateEmailVerificationLocksTable'],
            ] as $table => [$file, $class]) {
                $query = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
                $query->execute([$table]);
                if ((int) $query->fetchColumn() === 0) {
                    require BASE_PATH . '/migrations/' . $file;
                    (new $class())->up();
                }
                $query = $pdo->prepare('SELECT ENGINE FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
                $query->execute([$table]);
                verificationTestCheck($query->fetchColumn() === 'InnoDB', 'InnoDB required for ' . $table);
            }
            $reset = static function () use ($pdo): void {
                $pdo->exec('DELETE FROM email_verifications');
                $pdo->exec('DELETE FROM email_verification_locks');
            };
            $seed = static function (string $email, string $code, int $age = 120, ?string $ip = null) use ($pdo): void {
                $query = $pdo->prepare('INSERT INTO email_verifications (email,purpose,code_hash,attempts,expires_at,ip,created_at,updated_at) VALUES (?,\'register\',?,0,?,?,?,?)');
                $query->execute([$email, hash('sha256', $code . $email), date('Y-m-d H:i:s', time() + 600), $ip, date('Y-m-d H:i:s', time() - $age), date('Y-m-d H:i:s')]);
            };
            $send = static fn (string $email, string $ip) => ['operation' => 'send', 'email' => $email, 'ip' => $ip];
            $verify = static fn (string $code) => ['operation' => 'verify', 'email' => 'code@example.test', 'code' => $code];
            $successes = static fn (array $results): int => count(array_filter($results, static fn ($row) => ($row['result']['ok'] ?? false) === true));

            $reset();
            $results = verificationTestRace(array_fill(0, 8, $send('first@example.test', '192.0.2.1')));
            verificationTestCheck($successes($results) === 1 && (int) $pdo->query('SELECT COUNT(*) FROM email_verifications')->fetchColumn() === 1, 'First-ever concurrent send bypassed cooldown');
            echo "PASS first send, absent lock rows and cooldown\n";

            $reset();
            for ($i = 0; $i < 4; ++$i) $seed('quota@example.test', '123456', 120 + $i);
            $results = verificationTestRace(array_map(static fn ($i) => $send('quota@example.test', '192.0.2.' . ($i + 2)), range(0, 7)));
            verificationTestCheck($successes($results) === 1 && (int) $pdo->query('SELECT COUNT(*) FROM email_verifications')->fetchColumn() === 5, 'Email quota exceeded five');
            // Age the last reservation past cooldown so this checks the hourly
            // limit itself, not only the per-email cooldown branch.
            $pdo->exec('UPDATE email_verifications SET created_at = NOW() - INTERVAL 120 SECOND');
            $result = verificationTestService()->sendRegisterCode('quota@example.test', '192.0.2.99');
            verificationTestCheck(($result['error'] ?? '') === '该邮箱验证码请求过于频繁，请一小时后再试', 'Email hourly quota not enforced after cooldown');
            echo "PASS email hourly quota\n";

            $reset();
            for ($i = 0; $i < 59; ++$i) $seed('seed-' . $i . '@example.test', '123456', 120, '192.0.2.10');
            $results = verificationTestRace(array_map(static fn ($i) => $send('ip-' . $i . '@example.test', '192.0.2.10'), range(0, 7)));
            verificationTestCheck($successes($results) === 1 && (int) $pdo->query('SELECT COUNT(*) FROM email_verifications')->fetchColumn() === 60, 'IP quota exceeded sixty');
            echo "PASS shared IP hourly quota\n";

            $reset(); $seed('code@example.test', '123456');
            verificationTestRace(array_fill(0, 8, $verify('000000')));
            verificationTestCheck((int) $pdo->query('SELECT attempts FROM email_verifications')->fetchColumn() === 5, 'Wrong attempts lost or exceeded cap');
            verificationTestCheck(verificationTestService()->verifyRegisterCode('code@example.test', '123456') !== null, 'Exhausted code accepted');
            echo "PASS concurrent wrong attempts persist and cap at five\n";

            $reset(); $seed('code@example.test', '654321'); $seed('code@example.test', '123456');
            $results = verificationTestRace(array_fill(0, 8, $verify('123456')));
            verificationTestCheck(count(array_filter($results, static fn ($row) => $row['result'] === null)) === 1, 'Code successfully reused');
            verificationTestCheck(verificationTestService()->verifyRegisterCode('code@example.test', '654321') !== null, 'Consumed newest code fell back to old code');
            echo "PASS exactly one consume and no fallback to older code\n";

            $reset(); $seed('code@example.test', '123456');
            $pdo->exec('UPDATE email_verifications SET expires_at = NOW() - INTERVAL 1 SECOND');
            verificationTestCheck(verificationTestService()->verifyRegisterCode('code@example.test', '123456') !== null, 'Expired code accepted');
            echo "PASS expiry\n";

            $reset(); App\Support\Mailer::$sent = false;
            $result = verificationTestService()->sendRegisterCode('failure@example.test', '192.0.2.20');
            verificationTestCheck(($result['status'] ?? null) === 503 && App\Support\Mailer::$calls === 1, 'Expected transport failure');
            verificationTestCheck((int) $pdo->query('SELECT COUNT(*) FROM email_verifications')->fetchColumn() === 1, 'Failure refunded quota');
            $result = verificationTestService()->sendRegisterCode('failure@example.test', '192.0.2.20');
            verificationTestCheck(isset($result['error']) && App\Support\Mailer::$calls === 1, 'Failed mail bypassed cooldown');
            echo "PASS SMTP after commit, failure retains quota\nRESULT PASS\n";
        } catch (Throwable $error) {
            $failed = true;
            fwrite(STDERR, 'FAIL ' . $error->getMessage() . PHP_EOL);
        }
    });
    exit($failed ? 1 : 0);
}
