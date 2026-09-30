<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/**
 * 轻量 SMTP 发信（PHP 原生 stream，无第三方依赖）。
 * 465 走隐式 SSL，587 走 STARTTLS；配置全部来自环境变量 MAIL_*。
 * 支持发件账号池：MAIL_ACCOUNTS 为 JSON 数组时多账号轮流发、失败自动切换。
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
        $host = $account['host'];
        $port = $account['port'];
        $user = $account['username'];
        $pass = $account['password'];
        $from = $account['from'];
        $fromName = $account['name'];

        try {
            $transport = $port === 465 ? sprintf('ssl://%s:%d', $host, $port) : sprintf('tcp://%s:%d', $host, $port);
            $socket = stream_socket_client($transport, $errno, $errstr, 15);
            if (! $socket) {
                return false;
            }
            stream_set_timeout($socket, 15);

            // Swoole 协程 hooks 下 fgets 不可靠，改用 fread 循环；终止条件为「末行形如 250 ...（第 4 字符为空格）」
            $read = static function () use ($socket): string {
                $data = '';
                $deadline = microtime(true) + 15;
                while (microtime(true) < $deadline) {
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
                $deadline = microtime(true) + 15;
                while ($written < strlen($buffer) && microtime(true) < $deadline) {
                    $n = @fwrite($socket, substr($buffer, $written));
                    if ($n === false || $n === 0) {
                        usleep(20000);
                        continue;
                    }
                    $written += $n;
                }
            };
            $step = 'connect';
            $expect = static function (string $data, string $codes) use ($socket, &$step): void {
                $ok = false;
                foreach (explode(',', $codes) as $code) {
                    if (str_starts_with($data, $code)) {
                        $ok = true;
                    }
                }
                if (! $ok) {
                    $meta = stream_get_meta_data($socket);
                    $state = sprintf('eof=%s timed_out=%s blocked=%s', var_export($meta['eof'], true), var_export($meta['timed_out'], true), var_export($meta['blocked'], true));
                    throw new \RuntimeException("SMTP step [{$step}] unexpected response [{$state}]: " . substr($data, 0, 200));
                }
            };

            $step = 'banner';
            $expect($read(), '220');
            $write('EHLO guanlan');
            $step = 'ehlo';
            $ehlo = $read();
            $expect($ehlo, '250');

            if ($port !== 465 && str_contains($ehlo, 'STARTTLS')) {
                $write('STARTTLS');
                $step = 'starttls';
                $expect($read(), '220');
                if (! stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('STARTTLS failed');
                }
                $write('EHLO guanlan');
                $expect($read(), '250');
            }

            $step = 'auth-user';
            $write('AUTH LOGIN');
            $expect($read(), '334');
            $step = 'auth-username';
            $write(base64_encode($user));
            $expect($read(), '334');
            $step = 'auth-password';
            $write(base64_encode($pass));
            $expect($read(), '235');

            $step = 'mail-from';
            $write(sprintf('MAIL FROM:<%s>', $from));
            $expect($read(), '250');
            $step = 'rcpt-to';
            $write(sprintf('RCPT TO:<%s>', $to));
            $expect($read(), '250,251');
            $step = 'data';
            $write('DATA');
            $expect($read(), '354');

            $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
            $encodedName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
            $headers = [
                'From: ' . $encodedName . ' <' . $from . '>',
                'To: <' . $to . '>',
                'Subject: ' . $encodedSubject,
                'Date: ' . date('r'),
                'Message-ID: <' . bin2hex(random_bytes(12)) . '@guanlan>',
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                'Content-Transfer-Encoding: base64',
            ];
            $body = base64_encode($html);
            $body = chunk_split($body);
            $payload = implode("\r\n", $headers) . "\r\n\r\n" . $body;
            // 点透明：正文行首的点补一个点（先填充，再追加 SMTP 终止符 \r\n.\r\n）
            $payload = preg_replace('/^\./m', '..', $payload) ?? $payload;
            $write($payload . "\r\n.\r\n");
            $step = 'data-end';
            $expect($read(), '250');

            $write('QUIT');
            fclose($socket);
            return true;
        } catch (Throwable $exception) {
            error_log('[Mailer] send failed: ' . $exception->getMessage());
            return false;
        }
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
