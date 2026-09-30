<?php

declare(strict_types=1);

namespace App\Support;

use Swoole\Coroutine;
use Throwable;

/**
 * 轻量 SMTP 发信（无第三方依赖）。
 * 协程内使用 Swoole 原生 Coroutine\Socket（PHP ssl:// stream 在协程 hooks 下收不到延迟响应），
 * 非协程环境回退 PHP stream。465 走隐式 SSL，587 走 STARTTLS。
 * 配置全部来自环境变量 MAIL_*；支持账号池：MAIL_ACCOUNTS 为 JSON 数组时多账号轮流发、失败自动切换。
 */
class Mailer
{
    /**
     * @return array<int, array{host:string,port:int,username:string,password:string,from:string,name:string}>
     */
    public static function accounts(): array
    {
        $json = self::envVar('MAIL_ACCOUNTS', '');
        if ($json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $accounts = [];
                foreach ($decoded as $item) {
                    if (! is_array($item) || (string) ($item['username'] ?? '') === '') {
                        continue;
                    }
                    $accounts[] = [
                        'host' => (string) ($item['host'] ?? 'smtp.exmail.qq.com'),
                        'port' => (int) ($item['port'] ?? 465),
                        'username' => (string) $item['username'],
                        'password' => (string) ($item['password'] ?? ''),
                        'from' => (string) ($item['from'] ?? $item['username']),
                        'name' => (string) ($item['name'] ?? self::envVar('MAIL_FROM_NAME', '观澜')),
                    ];
                }
                if ($accounts !== []) {
                    return $accounts;
                }
            }
        }

        $user = self::envVar('MAIL_USERNAME', '');
        if ($user === '' || self::envVar('MAIL_PASSWORD', '') === '') {
            return [];
        }
        return [[
            'host' => self::envVar('MAIL_HOST', 'smtp.exmail.qq.com'),
            'port' => (int) self::envVar('MAIL_PORT', 465),
            'username' => $user,
            'password' => self::envVar('MAIL_PASSWORD', ''),
            'from' => self::envVar('MAIL_FROM_ADDRESS', $user),
            'name' => self::envVar('MAIL_FROM_NAME', '观澜'),
        ]];
    }

    public static function enabled(): bool
    {
        $flag = strtolower(self::envVar('MAIL_ENABLED', 'false'));
        return in_array($flag, ['1', 'true', 'yes', 'on'], true) && self::accounts() !== [];
    }

    public static function send(string $to, string $subject, string $html): bool
    {
        $accounts = self::accounts();
        if ($accounts === []) {
            return false;
        }
        // 随机起点轮流使用账号，失败则顺延尝试下一个
        $start = count($accounts) > 1 ? random_int(0, count($accounts) - 1) : 0;
        for ($i = 0; $i < count($accounts); ++$i) {
            $account = $accounts[($start + $i) % count($accounts)];
            if (self::sendWith($account, $to, $subject, $html)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array{host:string,port:int,username:string,password:string,from:string,name:string} $account
     */
    private static function sendWith(array $account, string $to, string $subject, string $html): bool
    {
        try {
            $transport = self::inCoroutine()
                ? self::swooleTransport($account['host'], $account['port'])
                : self::streamTransport($account['host'], $account['port']);
            self::dialogue($transport, $account, $to, $subject, $html);
            $transport['close']();

            return true;
        } catch (Throwable $exception) {
            error_log('[Mailer] send failed: ' . $exception->getMessage());
            if (isset($transport)) {
                try {
                    $transport['close']();
                } catch (Throwable) {
                }
            }
            return false;
        }
    }

    /**
     * 共享 SMTP 会话：横幅 → EHLO → （可选 STARTTLS）→ 认证 → 信封 → 正文。
     *
     * @param array{send:callable(string):void,recv:callable():string,starttls:callable():void,close:callable():void} $transport
     * @param array{host:string,port:int,username:string,password:string,from:string,name:string} $account
     */
    private static function dialogue(array $transport, array $account, string $to, string $subject, string $html): void
    {
        $send = $transport['send'];
        $recv = $transport['recv'];
        $expect = static function (string $data, string $codes, string $step) use ($transport): void {
            $ok = false;
            foreach (explode(',', $codes) as $code) {
                if (str_starts_with($data, $code)) {
                    $ok = true;
                }
            }
            if (! $ok) {
                throw new \RuntimeException("SMTP step [{$step}] unexpected response: " . substr($data, 0, 200));
            }
        };

        $expect($recv(), '220', 'banner');
        $send('EHLO guanlan');
        $ehlo = $recv();
        $expect($ehlo, '250', 'ehlo');

        if ($account['port'] !== 465 && str_contains($ehlo, 'STARTTLS')) {
            $send('STARTTLS');
            $expect($recv(), '220', 'starttls');
            $transport['starttls']();
            $send('EHLO guanlan');
            $expect($recv(), '250', 'ehlo-tls');
        }

        $send('AUTH LOGIN');
        $expect($recv(), '334', 'auth-user');
        $send(base64_encode($account['username']));
        $expect($recv(), '334', 'auth-username');
        $send(base64_encode($account['password']));
        $expect($recv(), '235', 'auth-password');

        $send(sprintf('MAIL FROM:<%s>', $account['from']));
        $expect($recv(), '250', 'mail-from');
        $send(sprintf('RCPT TO:<%s>', $to));
        $expect($recv(), '250,251', 'rcpt-to');
        $send('DATA');
        $expect($recv(), '354', 'data');

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedName = '=?UTF-8?B?' . base64_encode($account['name']) . '?=';
        $headers = [
            'From: ' . $encodedName . ' <' . $account['from'] . '>',
            'To: <' . $to . '>',
            'Subject: ' . $encodedSubject,
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@guanlan>',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $payload = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($html));
        // 点透明：正文行首的点补一个点；终止符必须紧跟上一行 CRLF（QQ 服务器对终止符前的空行会挂起不响应）
        $payload = preg_replace('/^\./m', '..', $payload) ?? $payload;
        $send($payload . ".\r\n");
        $expect($recv(), '250', 'data-end');

        $send('QUIT');
    }

    /**
     * Swoole 协程原生传输。recv 带 0.5s 超时返回空串属正常，外层循环以「末行形如 250 ...」为终止条件。
     */
    private static function swooleTransport(string $host, int $port): array
    {
        $socket = new Coroutine\Socket(\AF_INET, \SOCK_STREAM, \IPPROTO_IP);
        if (! $socket->connect($host, $port, 15)) {
            throw new \RuntimeException("connect to {$host}:{$port} failed: " . $socket->errMsg);
        }
        if ($port === 465 && ! $socket->sslHandshake()) {
            throw new \RuntimeException('sslHandshake failed: ' . $socket->errMsg);
        }

        $deadline = static fn (): float => microtime(true) + 15;
        return [
            'send' => static function (string $command) use ($socket): void {
                $buffer = $command . "\r\n";
                $written = 0;
                $limit = microtime(true) + 15;
                while ($written < strlen($buffer) && microtime(true) < $limit) {
                    $n = $socket->send(substr($buffer, $written));
                    if ($n === false || $n === 0) {
                        Coroutine::sleep(0.02);
                        continue;
                    }
                    $written += $n;
                }
                if ($written < strlen($buffer)) {
                    throw new \RuntimeException('send timeout');
                }
            },
            'recv' => static function () use ($socket): string {
                $data = '';
                $limit = microtime(true) + 15;
                while (microtime(true) < $limit) {
                    $chunk = $socket->recv(8192, 0.5);
                    if (is_string($chunk) && $chunk !== '') {
                        $data .= $chunk;
                    }
                    if (preg_match('/(?:^|\r\n)\d{3} [^\r\n]*\r\n$/s', $data)) {
                        break;
                    }
                    if ($chunk === false) {
                        throw new \RuntimeException('recv eof/err: ' . $socket->errMsg);
                    }
                    Coroutine::sleep(0.05);
                }
                return $data;
            },
            'starttls' => static function () use ($socket): void {
                if (! $socket->sslHandshake()) {
                    throw new \RuntimeException('STARTTLS handshake failed: ' . $socket->errMsg);
                }
            },
            'close' => static function () use ($socket): void {
                try {
                    $socket->close();
                } catch (Throwable) {
                }
            },
        ];
    }

    /**
     * 非协程环境回退：PHP stream。
     */
    private static function streamTransport(string $host, int $port): array
    {
        $transport = $port === 465 ? sprintf('ssl://%s:%d', $host, $port) : sprintf('tcp://%s:%d', $host, $port);
        $socket = stream_socket_client($transport, $errno, $errstr, 15);
        if (! $socket) {
            throw new \RuntimeException("connect to {$host}:{$port} failed: {$errstr}");
        }
        stream_set_timeout($socket, 15);

        $read = static function () use ($socket): string {
            $data = '';
            $limit = microtime(true) + 15;
            while (microtime(true) < $limit) {
                $chunk = @fread($socket, 8192);
                if (is_string($chunk) && $chunk !== '') {
                    $data .= $chunk;
                }
                if (preg_match('/(?:^|\r\n)\d{3} [^\r\n]*\r\n$/s', $data)) {
                    break;
                }
                if ($chunk === false || $chunk === '') {
                    usleep(50000);
                }
            }
            return $data;
        };
        $write = static function (string $command) use ($socket): void {
            $buffer = $command . "\r\n";
            $written = 0;
            $limit = microtime(true) + 15;
            while ($written < strlen($buffer) && microtime(true) < $limit) {
                $n = @fwrite($socket, substr($buffer, $written));
                if ($n === false || $n === 0) {
                    usleep(20000);
                    continue;
                }
                $written += $n;
            }
            if ($written < strlen($buffer)) {
                throw new \RuntimeException('send timeout');
            }
        };

        return [
            'send' => $write,
            'recv' => $read,
            'starttls' => static function () use ($socket): void {
                if (! stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('STARTTLS failed');
                }
            },
            'close' => static function () use ($socket): void {
                try {
                    fclose($socket);
                } catch (Throwable) {
                }
            },
        ];
    }

    private static function inCoroutine(): bool
    {
        return class_exists(Coroutine::class) && Coroutine::getCid() >= 0;
    }

    private static function envVar(string $key, string $default): string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }
        return $value;
    }
}
