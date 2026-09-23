<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\AnalysisArticle;
use App\Model\Attempt;
use App\Model\Hotspot;
use App\Model\MistakeItem;
use App\Model\Paper;
use App\Model\Prediction;
use App\Model\Question;
use App\Model\User;
use Hyperf\DbConnection\Db;

/**
 * 认证与注册。
 */
class AuthService
{
    public function register(string $email, string $password, string $displayName): array
    {
        $email = mb_strtolower(trim($email));
        if (User::query()->where('email', $email)->exists()) {
            return ['error' => '该邮箱已注册'];
        }

        $user = User::create([
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'display_name' => $displayName !== '' ? $displayName : mb_substr($email, 0, strpos($email, '@') ?: 8),
            'role' => 'user',
            'status' => 'active',
        ]);

        return ['user' => $user];
    }

    public function login(string $email, string $password): ?User
    {
        $user = User::query()->where('email', mb_strtolower(trim($email)))->first();
        if (! $user || ! password_verify($password, (string) $user->password_hash)) {
            return null;
        }
        if (! $user->isActive()) {
            return null;
        }

        $user->last_login_at = date('Y-m-d H:i:s');
        $user->save();

        return $user;
    }
}
