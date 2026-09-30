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
        $json = (string) env('MAIL_ACCOUNTS', '');
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
                        'name' => (string) ($item['name'] ?? env('MAIL_FROM_NAME', '观澜')),
                    ];
                }
                if ($accounts !== []) {
                    return $accounts;
                }
            }
        }

        $user = (string) env('MAIL_USERNAME', '');
        if ($user === '' || (string) env('MAIL_PASSWORD', '') === '') {
            return [];
        }
        return [[
            'host' => (string) env('MAIL_HOST', 'smtp.exmail.qq.com'),
            'port' => (int) env('MAIL_PORT', 465),
            'username' => $user,
            'password' => (string) env('MAIL_PASSWORD', ''),
            'from' => (string) env('MAIL_FROM_ADDRESS', $user),
            'name' => (string) env('MAIL_FROM_NAME', '观澜'),
        ]];
    }

    public static function enabled(): bool
    {
        return (bool) env('MAIL_ENABLED', false) && self::accounts() !== [];
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

            $read = static function () use ($socket): string {
                $data = '';
                while ($line = fgets($socket, 515)) {
                    $data .= $line;
                    if (isset($line[3]) && $line[3] === ' ') {
                        break;
                    }
                }
                return $data;
            };
            $write = static function (string $command) use ($socket): void {
                fwrite($socket, $command . "\r\n");
            };
            $expect = static function (string $data, string $codes): void {
                $ok = false;
                foreach (explode(',', $codes) as $code) {
                    if (str_starts_with($data, $code)) {
                        $ok = true;
                    }
                }
                if (! $ok) {
                    throw new \RuntimeException('SMTP unexpected response: ' . substr($data, 0, 200));
                }
            };

            $expect($read(), '220');
            $write('EHLO guanlan');
            $ehlo = $read();
            $expect($ehlo, '250');

            if ($port !== 465 && str_contains($ehlo, 'STARTTLS')) {
                $write('STARTTLS');
                $expect($read(), '220');
                if (! stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('STARTTLS failed');
                }
                $write('EHLO guanlan');
                $expect($read(), '250');
            }

            $write('AUTH LOGIN');
            $expect($read(), '334');
            $write(base64_encode($user));
            $expect($read(), '334');
            $write(base64_encode($pass));
            $expect($read(), '235');

            $write(sprintf('MAIL FROM:<%s>', $from));
            $expect($read(), '250');
            $write(sprintf('RCPT TO:<%s>', $to));
            $expect($read(), '250,251');
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
            $data = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
            // SMTP 行以点开头会被误判为结束符，补一个点（点透明）
            $data = preg_replace('/^\./m', '..', $data) ?? $data;
            $write($data);
            $expect($read(), '250');

            $write('QUIT');
            fclose($socket);
            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
