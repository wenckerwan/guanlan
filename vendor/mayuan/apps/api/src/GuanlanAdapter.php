<?php
declare(strict_types=1);
namespace Mayuan;

/**
 * 观澜集成适配器(观澜新契约,见 GUANLAN_TASKS.md §一/§三)。
 *
 * 要点:
 *  - 马原与观澜同域部署;身份验证用用户的人态 token 经容器内网调用观澜 /auth/me。
 *  - 摘要推送也用「该用户的人态 token」,不再使用任何服务 token。
 *  - 不实现授权码/OAuth/独立服务身份(exchangeCode 恒 503)。
 *
 * 环境变量(观澜侧部署提供):
 *  - GUANLAN_API_INTERNAL_BASE  内网 API base,默认 http://api:9501/api/v1(允许 http)。
 *  - GUANLAN_SUMMARY_PROTOCOL   须为 mayuan-summary-v1。
 *  - GUANLAN_SUMMARY_URL        摘要推送地址(生产 https,内网可 http)。
 *  - GUANLAN_SERVICE_TOKEN      已废弃,禁止引用。
 */
final class GuanlanAdapter {
    /** 进程内 verifyToken 缓存:sha256(token) => ['at'=>int, 'identity'=>array] */
    private static array $identityCache = [];
    private const IDENTITY_CACHE_TTL = 60;

    /**
     * 集成状态。configured 判定:协议匹配 且 推送地址非空(http/https) 且 内网 base 非空。
     * 不再要求 SERVICE_TOKEN。
     */
    public static function status(): array {
        $protocolOk = getenv('GUANLAN_SUMMARY_PROTOCOL') === 'mayuan-summary-v1';
        $summaryUrl = getenv('GUANLAN_SUMMARY_URL') ?: '';
        $summaryOk = $summaryUrl !== ''
            && (str_starts_with($summaryUrl, 'https://') || str_starts_with($summaryUrl, 'http://'));
        $internalBase = getenv('GUANLAN_API_INTERNAL_BASE');
        $baseOk = ($internalBase === false ? 'http://api:9501/api/v1' : $internalBase) !== '';
        $ready = $protocolOk && $summaryOk && $baseOk;
        return [
            'configured' => $ready,
            'status' => $ready
                ? 'summary-configured: user-token push via internal verify; code exchange unavailable'
                : 'unconfigured: summary protocol/url or internal api base missing; code exchange unavailable',
        ];
    }

    /**
     * 用用户的人态 token 经内网调用观澜 /auth/me 验证身份。
     * 同一 token 60s 内命中进程内缓存,不重复内网调用。
     *
     * @throws ApiError 401 invalid_token / invalid_identity;503 integration_unconfigured
     */
    public function verifyToken(string $token): array {
        if (!$token || preg_match('/[\r\n]/', $token)) {
            throw new ApiError(401, 'invalid_token', '令牌无效');
        }
        $hash = hash('sha256', $token);
        $cached = self::$identityCache[$hash] ?? null;
        if ($cached && (time() - $cached['at']) < self::IDENTITY_CACHE_TTL) {
            return $cached['identity'];
        }
        $base = getenv('GUANLAN_API_INTERNAL_BASE');
        $base = rtrim($base === false || $base === '' ? 'http://api:9501/api/v1' : $base, '/');
        // base 已含 /api/v1,内网调用允许 http。
        $result = $this->request('GET', $base . '/auth/me', $token);
        $u = $result['data']['user'] ?? $result['data'] ?? null;
        if (
            !is_array($u)
            || (!is_string($u['id'] ?? null) && !is_int($u['id'] ?? null))
            || ($u['is_active'] ?? true) === false
            || ($u['status'] ?? 'active') !== 'active'
        ) {
            throw new ApiError(401, 'invalid_identity', '观澜身份无效');
        }
        $identity = [
            'id' => 'guanlan:' . $u['id'],
            'name' => (string)($u['displayName'] ?? $u['nickname'] ?? $u['name'] ?? '观澜学习者'),
            'role' => ($u['role'] ?? '') === 'admin' ? 'admin' : 'learner',
        ];
        self::$identityCache[$hash] = ['at' => time(), 'identity' => $identity];
        return $identity;
    }

    /**
     * 方案 A 已废弃授权码兑换,恒 503。
     */
    public function exchangeCode(string $code, string $state, string $redirect): never {
        throw new ApiError(503, 'integration_unconfigured', '观澜尚未提供已验证的一次性授权码兑换协议');
    }

    /**
     * 推送学习摘要到观澜,使用「该用户当前有效的人态 token」(由调用方传入)。
     * 禁止把 token 写进任何返回、日志、payload。
     *
     * @param array  $summary   outbox payload(含 subject=guanlan:<id> 等)
     * @param string $userToken 该用户的人态 token
     * @return array{status:int, revision:mixed, retryAfter:int} 409 会以 status=409 明确返回,供上层 Outbox 映射 superseded。
     * @throws ApiError 503 integration_unconfigured
     */
    public function sendSummary(array $summary, string $userToken): array {
        if (!self::status()['configured']) {
            throw new ApiError(503, 'integration_unconfigured', '观澜摘要协议未明确启用');
        }
        if ($userToken === '' || preg_match('/[\r\n]/', $userToken)) {
            // token 缺失/非法视同授权失败:返回 401 由上层 Outbox 标 paused,待用户重新登录恢复。
            return ['status' => 401, 'revision' => null, 'retryAfter' => 60];
        }
        $url = getenv('GUANLAN_SUMMARY_URL') ?: '';
        $ctx = stream_context_create(['http' => [
            'method' => 'PUT',
            'header' => "Authorization: Bearer " . $userToken . "\r\nContent-Type: application/json\r\nAccept: application/json\r\n",
            'content' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'timeout' => 10,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        $headers = $http_response_header ?? [];
        preg_match('/\s(\d{3})\s/', $headers[0] ?? '', $m);
        $status = (int)($m[1] ?? 503);
        $retryAfter = 60;
        foreach ($headers as $h) {
            if (preg_match('/^Retry-After:\s*(\d+)/i', $h, $v)) {
                $retryAfter = (int)$v[1];
            }
        }
        $data = $raw ? json_decode($raw, true) : null;
        return ['status' => $status, 'revision' => $data['data']['revision'] ?? null, 'retryAfter' => $retryAfter];
    }

    /**
     * 内网 GET 调用观澜,仅接受 200;非 200 或网络失败 → 401 identity_validation_failed。
     */
    private function request(string $method, string $url, string $token): array {
        $ctx = stream_context_create(['http' => [
            'method' => $method,
            'header' => 'Authorization: Bearer ' . $token . "\r\nAccept: application/json\r\n",
            'timeout' => 8,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        $status = $http_response_header[0] ?? '';
        if ($raw === false || !preg_match('/ 200 /', $status)) {
            throw new ApiError(401, 'identity_validation_failed', '观澜会话验证失败');
        }
        try {
            return json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new ApiError(502, 'invalid_identity_response', '观澜响应无效');
        }
    }
}
