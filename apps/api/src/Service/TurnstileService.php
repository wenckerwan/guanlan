<?php
declare(strict_types=1);

namespace App\Service;

use Closure;
use GuzzleHttp\Client;
use Throwable;

final class TurnstileService
{
    public function __construct(private ?Closure $transport = null) {}

    public function validate(string $token, string $ip): ?array
    {
        if (getenv('TURNSTILE_ENABLED') !== 'true') return null;
        $secret = getenv('TURNSTILE_SECRET_KEY') ?: '';
        $hosts = array_filter(array_map('trim', explode(',', getenv('TURNSTILE_HOSTNAMES') ?: '')));
        if ($secret === '' || $hosts === []) return ['error' => '安全验证暂不可用，请稍后重试', 'status' => 503];
        if ($token === '' || strlen($token) > 2048) return ['error' => '请先完成人机验证', 'status' => 422];
        try {
            $payload = ['secret' => $secret, 'response' => $token, 'remoteip' => $ip];
            $result = $this->transport ? ($this->transport)($payload) : $this->request($payload);
            if (($result['success'] ?? false) !== true || !in_array($result['hostname'] ?? '', $hosts, true)
                || ($result['action'] ?? '') !== 'register') {
                return ['error' => '人机验证未通过或已过期，请重新验证', 'status' => 422];
            }
        } catch (Throwable) {
            return ['error' => '安全验证暂不可用，请稍后重试', 'status' => 503];
        }
        return null;
    }

    private function request(array $payload): array
    {
        $response = (new Client(['connect_timeout' => 3, 'timeout' => 8, 'allow_redirects' => false]))
            ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', ['form_params' => $payload]);
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
