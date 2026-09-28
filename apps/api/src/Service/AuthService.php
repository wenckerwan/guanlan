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

        $attributes = [
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'display_name' => $displayName !== '' ? $displayName : mb_substr($email, 0, strpos($email, '@') ?: 8),
            'role' => 'user',
            'status' => 'active',
        ];

        // 编号由「读取 + 计算」得出，并发注册可能算出同一个值；
        // mistake_code 有唯一索引，冲突时重算，避免直接 500。
        $user = null;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $user = User::create($attributes + ['mistake_code' => $this->nextAccountId()]);
                break;
            } catch (\Throwable $exception) {
                if (! $this->isDuplicateMistakeCode($exception)) {
                    throw $exception;
                }
            }
        }

        if (! $user instanceof User) {
            return ['error' => '账号创建失败，请重试'];
        }

        return ['user' => $user];
    }

    /** 唯一索引冲突（并发注册抢到同一编号）。 */
    private function isDuplicateMistakeCode(\Throwable $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'mistake_code')
            && (str_contains($message, 'Duplicate') || str_contains($message, '1062') || str_contains($message, 'unique'));
    }

    /**
     * 账号 ID：纯数字递增，同时作为该账号的错题编号。
     * 取最小未被占用的正整数，不复用已释放的编号。
     */
    private function nextAccountId(): string
    {
        $taken = [];
        foreach (User::query()->whereNotNull('mistake_code')->pluck('mistake_code') as $code) {
            if (is_numeric($code)) {
                $taken[(int) $code] = true;
            }
        }

        $candidate = 1;
        while (isset($taken[$candidate])) {
            $candidate++;
        }

        return (string) $candidate;
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
