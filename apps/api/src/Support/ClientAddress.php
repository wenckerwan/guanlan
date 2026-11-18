<?php
declare(strict_types=1);

namespace App\Support;

/** Authenticated BFF forwarding, independent of changing Docker container IPs. */
final class ClientAddress
{
    public static function verify(string $ip, string $timestamp, string $signature, string $method, string $path): ?string
    {
        $secret = getenv('BFF_PROXY_SECRET') ?: '';
        if (strlen($secret) < 32 || !filter_var($ip, FILTER_VALIDATE_IP)
            || !ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 60
            || !preg_match('/^[a-f0-9]{64}$/D', $signature)) return null;
        $expected = hash_hmac('sha256', "$timestamp\n$method\n$path\n$ip", $secret);
        return hash_equals($expected, $signature) ? $ip : null;
    }
}
