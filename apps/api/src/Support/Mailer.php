<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/**
 * 轻量 SMTP 发信（PHP 原生 stream，无第三方依赖）。
 * 465 走隐式 SSL，587 走 STARTTLS；配置全部来自环境变量 MAIL_*。
 */
class Mailer
{
    public static function enabled(): bool
    {
        return (bool) env('MAIL_ENABLED', false)
            && (string) env('MAIL_USERNAME', '') !== ''
            && (string) env('MAIL_PASSWORD', '') !== '';
    }

    public static function send(string $to, string $subject, string $html): bool
    {
        $host = (string) env('MAIL_HOST', 'smtp.exmail.qq.com');
        $port = (int) env('MAIL_PORT', 465);
        $user = (string) env('MAIL_USERNAME', '');
        $pass = (string) env('MAIL_PASSWORD', '');
        $from = (string) env('MAIL_FROM_ADDRESS', $user);
        $fromName = (string) env('MAIL_FROM_NAME', '观澜');

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
