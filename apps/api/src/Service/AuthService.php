<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\User;
use Hyperf\DbConnection\Db;
use Throwable;

/** 认证、注册以及账号对应错题本的原子创建。 */
class AuthService
{
    public function __construct(private MistakeAccountService $mistakeAccounts)
    {
    }

    public function register(string $email, string $password, string $displayName): array
    {
        $email = mb_strtolower(trim($email));
        if (User::query()->where('email', $email)->exists()) {
            return ['error' => '该邮箱已注册'];
        }

        try {
            $user = Db::transaction(function () use ($email, $password, $displayName): User {
                $user = User::create([
                    'email' => $email,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'display_name' => $displayName !== '' ? $displayName : mb_substr($email, 0, strpos($email, '@') ?: 8),
                    'role' => 'user',
                    'status' => 'active',
                ]);
                $this->mistakeAccounts->provision($user);
                return $user->fresh(['mistakeAccount']);
            }, 3);
        } catch (Throwable $exception) {
            if (str_contains($exception->getMessage(), 'Duplicate') || str_contains($exception->getMessage(), '1062')) {
                return ['error' => '该邮箱已注册或账号创建冲突，请重试'];
            }
            throw $exception;
        }

        return ['user' => $user];
    }

    public function login(string $email, string $password): ?User
    {
        $user = User::query()->with('mistakeAccount')->where('email', mb_strtolower(trim($email)))->first();
        if (! $user || ! password_verify($password, (string) $user->password_hash) || ! $user->isActive()) {
            return null;
        }
        $user->last_login_at = date('Y-m-d H:i:s');
        $user->save();
        return $user;
    }
}
