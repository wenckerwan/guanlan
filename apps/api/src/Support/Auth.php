<?php

declare(strict_types=1);

namespace App\Support;

use App\Model\User;
use App\Model\UserToken;
use Hyperf\Context\Context;
use Hyperf\HttpServer\Contract\RequestInterface;

/**
 * 令牌认证：客户端传 `Authorization: Bearer <token>`。
 * 库里只存 sha256(token)，明文不落库。
 */
class Auth
{
    public const CONTEXT_USER = 'auth.user';

    public static function issue(User $user, string $userAgent = ''): string
    {
        $token = bin2hex(random_bytes(32));

        UserToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'user_agent' => mb_substr($userAgent, 0, 191),
            'expires_at' => date('Y-m-d H:i:s', time() + 60 * 60 * 24 * 30),
        ]);

        return $token;
    }

    public static function tokenFrom(RequestInterface $request): string
    {
        $header = (string) $request->getHeaderLine('Authorization');
        if (stripos($header, 'bearer ') === 0) {
            return trim(substr($header, 7));
        }
        return (string) $request->input('token', '');
    }

    public static function resolve(string $token): ?User
    {
        if ($token === '') {
            return null;
        }

        $record = UserToken::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', date('Y-m-d H:i:s'))
            ->first();

        if (! $record) {
            return null;
        }

        $user = User::find($record->user_id);

        return $user && $user->isActive() ? $user : null;
    }

    public static function user(): ?User
    {
        $user = Context::get(self::CONTEXT_USER);
        return $user instanceof User ? $user : null;
    }

    public static function setUser(?User $user): void
    {
        Context::set(self::CONTEXT_USER, $user);
    }

    public static function revoke(string $token): void
    {
        UserToken::query()->where('token_hash', hash('sha256', $token))->delete();
    }
}
