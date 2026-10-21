<?php
declare(strict_types=1);

// Run with php -n so the native Swoole class can be replaced with a protocol-level socket double.
namespace Swoole {
    class Coroutine {
        public static function getCid(): int { return 1; }
        public static function sleep(float $seconds): void {}
    }
}
namespace Swoole\Coroutine {
    class Socket {
        public static string $scenario = '';
        public static ?self $last = null;
        public array $events = [];
        public array $commands = [];
        public int $errCode = 0;
        public string $errMsg = '';
        private bool $configured = false;
        private bool $encrypted = false;
        private array $responses = [];
        public function __construct(...$args) { self::$last = $this; }
        public function setProtocol(array $settings): bool {
            $this->events[] = ['tls', $settings];
            $this->configured = ($settings['open_ssl'] ?? false) === true;
            return true;
        }
        public function connect(string $host, int $port, float $timeout): bool {
            $this->events[] = ['connect', $port];
            if ($port === 465 && !$this->configured) throw new \RuntimeException('Implicit TLS must be configured before connect');
            if ($port === 587 && $this->configured) throw new \RuntimeException('STARTTLS must begin with plaintext SMTP');
            if (self::$scenario === 'connect-failed') { $this->errMsg = 'Connection refused'; return false; }
            $this->encrypted = $port === 465;
            if (self::$scenario === 'delayed') $this->responses[] = false;
            if (self::$scenario === 'eof') $this->responses[] = '';
            if (self::$scenario === 'read-failed') $this->responses[] = ['error' => 104];
            $this->responses[] = "220 test SMTP ready\r\n";
            return true;
        }
        public function sslHandshake(): bool {
            $this->events[] = ['handshake'];
            if (!$this->configured || $this->encrypted) throw new \RuntimeException('Missing TLS context or duplicate TLS handshake');
            $this->encrypted = true;
            return true;
        }
        public function send(string $bytes): int {
            $command = rtrim($bytes, "\r\n");
            $this->commands[] = $command;
            if (str_starts_with($command, 'EHLO')) $this->responses[] = self::$scenario === 'no-starttls' ? "250 AUTH LOGIN\r\n" : "250-test\r\n250 STARTTLS\r\n";
            elseif ($command === 'STARTTLS') $this->responses[] = "220 Ready to start TLS\r\n";
            elseif ($command === 'AUTH LOGIN') {
                if (!$this->encrypted) throw new \RuntimeException('Credentials must not be sent without TLS');
                $this->responses[] = "334 username\r\n";
            }
            elseif ($command === base64_encode('test-user')) $this->responses[] = "334 password\r\n";
            elseif ($command === base64_encode('test-pass')) $this->responses[] = "235 authenticated\r\n";
            elseif (str_starts_with($command, 'MAIL FROM:') || str_starts_with($command, 'RCPT TO:')) $this->responses[] = "250 OK\r\n";
            elseif ($command === 'DATA') $this->responses[] = "354 send body\r\n";
            elseif ($command !== 'QUIT') {
                if (!str_ends_with($bytes, "\r\n.\r\n")) throw new \RuntimeException('SMTP DATA must end with exactly CRLF dot CRLF');
                $this->responses[] = "250 queued\r\n";
            }
            return strlen($bytes);
        }
        public function recv(...$args): string|false {
            $value = array_shift($this->responses);
            if (is_array($value)) { $this->errCode = $value['error']; $this->errMsg = 'Connection reset'; return false; }
            $this->errCode = $value === false ? 110 : 0;
            $this->errMsg = $value === false ? 'Connection timed out' : '';
            return $value ?? '';
        }
        public function close(): void { $this->events[] = ['close']; }
    }
}
namespace {
    define('AF_INET', 2); define('SOCK_STREAM', 1); define('IPPROTO_IP', 0);
    require __DIR__ . '/../src/Support/Mailer.php';
    $failures = [];
    foreach (['implicit' => 465, 'starttls' => 587, 'delayed' => 465, 'no-starttls' => 587, 'connect-failed' => 465, 'eof' => 465, 'read-failed' => 465] as $scenario => $port) {
        \Swoole\Coroutine\Socket::$scenario = $scenario;
        putenv('MAIL_ACCOUNTS=' . json_encode([['host' => 'smtp.example.test', 'port' => $port, 'username' => 'test-user', 'password' => 'test-pass']]));
        $ok = \App\Support\Mailer::send('recipient@example.test', 'Test', '<p>Test</p>');
        $socket = \Swoole\Coroutine\Socket::$last;
        $expected = in_array($scenario, ['implicit', 'starttls', 'delayed'], true);
        if ($ok !== $expected) $failures[] = $scenario . ': unexpected send result';
        if ($scenario === 'no-starttls' && in_array('AUTH LOGIN', $socket->commands, true)) $failures[] = 'STARTTLS unavailable: attempted credential exchange';
        if (!$expected && !in_array(['close'], $socket->events, true)) $failures[] = $scenario . ': socket not closed';
        if ($ok) {
            $settings = array_values(array_filter($socket->events, fn($e) => $e[0] === 'tls'))[0][1] ?? [];
            if (($settings['ssl_host_name'] ?? '') !== 'smtp.example.test' || ($settings['ssl_verify_peer'] ?? false) !== true) $failures[] = $scenario . ': peer verification missing';
            $handshakes = count(array_filter($socket->events, fn($e) => $e[0] === 'handshake'));
            if ($handshakes !== ($port === 465 ? 0 : 1)) $failures[] = $scenario . ': incorrect handshake count';
        }
        echo ($ok === $expected ? 'PASS ' : 'FAIL ') . $scenario . PHP_EOL;
    }
    if ($failures) { fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL); exit(1); }
    echo "PASS SMTP TLS initialization, upgrade, delayed response and credential protection\n";
}
