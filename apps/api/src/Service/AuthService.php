<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\EmailVerification;
use App\Model\User;
use App\Support\Mailer;
use App\Support\Auth;
use Hyperf\DbConnection\Db;
use Throwable;

/** 认证、注册以及账号对应错题本的原子创建。 */
class AuthService
{
    public function __construct(private MistakeAccountService $mistakeAccounts)
    {
    }

    public function register(string $email, string $password, string $displayName, ?string $code = null): array
    {
        $email = mb_strtolower(trim($email));

        if (Mailer::enabled()) {
            if ($code === null || trim($code) === '') {
                return ['error' => '请先获取并填写邮箱验证码'];
            }
            $verifyError = $this->verifyRegisterCode($email, trim($code));
            if ($verifyError !== null) {
                return ['error' => $verifyError];
            }
        }

        return $this->createAccount($email, $password, $displayName);
    }

    public function createByAdmin(string $email, string $password, string $displayName, string $role): array
    {
        $actor = Auth::user();
        if (!$actor || !$actor->isAdmin() || !$actor->isActive()) throw new \RuntimeException('需要管理员权限', 403);
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 191) throw new \RuntimeException('邮箱格式不正确', 422);
        if (mb_strlen($password) < 6) throw new \RuntimeException('密码至少 6 位', 422);
        if (mb_strlen($displayName) > 191) throw new \RuntimeException('昵称最多 191 个字符', 422);
        if (!in_array($role, ['user', 'admin'], true)) throw new \RuntimeException('无效的角色', 422);
        return $this->createAccount($email, $password, $displayName, $role);
    }

    private function createAccount(string $email, string $password, string $displayName, string $role = 'user'): array
    {
        if (User::query()->where('email', $email)->exists()) {
            return ['error' => '该邮箱已注册'];
        }

        try {
            $user = Db::transaction(function () use ($email, $password, $displayName, $role): User {
                $user = User::create([
                    'email' => $email,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'display_name' => $displayName !== '' ? $displayName : mb_substr($email, 0, strpos($email, '@') ?: 8),
                    'role' => $role,
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

    /**
     * 发送注册验证码。返回 ['ok'=>true] 或 ['error'=>...]。
     * 已注册的邮箱不发信但返回同样的成功提示，避免枚举。
     */
    public function sendRegisterCode(string $email, string $ip): array
    {
        if (! Mailer::enabled()) {
            return ['error' => '邮箱验证服务未开启'];
        }
        $email = mb_strtolower(trim($email));
        $now = time();
        $windowStart = date('Y-m-d H:i:s', $now - 3600);

        $last = EmailVerification::query()
            ->where('email', $email)->where('purpose', 'register')
            ->orderByDesc('id')->first();
        if ($last && strtotime((string) $last->created_at) > $now - 60) {
            return ['error' => '发送过于频繁，请 60 秒后再试'];
        }
        $emailCount = EmailVerification::query()
            ->where('email', $email)->where('purpose', 'register')
            ->where('created_at', '>=', $windowStart)->count();
        if ($emailCount >= 5) {
            return ['error' => '该邮箱验证码请求过于频繁，请一小时后再试'];
        }
        if ($ip !== '') {
            $ipCount = EmailVerification::query()
                ->where('ip', $ip)
                ->where('created_at', '>=', $windowStart)->count();
            if ($ipCount >= 60) {
                return ['error' => '请求过于频繁，请稍后再试'];
            }
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        EmailVerification::create([
            'email' => $email,
            'purpose' => 'register',
            'code_hash' => hash('sha256', $code . $email),
            'attempts' => 0,
            'expires_at' => date('Y-m-d H:i:s', $now + 600),
            'ip' => $ip !== '' ? $ip : null,
        ]);

        if (User::query()->where('email', $email)->exists()) {
            return ['ok' => true];
        }

        $sent = Mailer::send($email, '观澜注册验证码', self::codeHtml($code));
        if (! $sent) {
            return ['error' => '验证码发送失败，请稍后重试'];
        }
        return ['ok' => true];
    }

    /** 校验注册验证码。成功返回 null，失败返回错误文案（消耗一次尝试机会）。 */
    public function verifyRegisterCode(string $email, string $code): ?string
    {
        $email = mb_strtolower(trim($email));
        $row = EmailVerification::query()
            ->where('email', $email)->where('purpose', 'register')
            ->whereNull('used_at')->orderByDesc('id')->first();
        if (! $row || strtotime((string) $row->expires_at) < time()) {
            return '验证码已过期，请重新获取';
        }
        if ((int) $row->attempts >= 5) {
            return '验证码错误次数过多，请重新获取';
        }
        if (! hash_equals((string) $row->code_hash, hash('sha256', $code . $email))) {
            $row->attempts = (int) $row->attempts + 1;
            $row->save();
            return '验证码不正确';
        }
        $row->used_at = date('Y-m-d H:i:s');
        $row->save();
        return null;
    }

    private static function codeHtml(string $code): string
    {
        return '<div style="max-width:480px;margin:0 auto;font-family:-apple-system,\'PingFang SC\',\'Microsoft YaHei\',sans-serif;color:#1f2937;">'
            . '<div style="background:#0f172a;color:#fff;padding:20px 28px;border-radius:12px 12px 0 0;font-size:18px;font-weight:600;">观澜 · 考研政治知识库</div>'
            . '<div style="border:1px solid #e5e7eb;border-top:none;padding:28px;border-radius:0 0 12px 12px;">'
            . '<p style="margin:0 0 12px;">你好！你正在注册观澜账号，验证码为：</p>'
            . '<p style="margin:0 0 16px;font-size:32px;font-weight:700;letter-spacing:8px;color:#0f172a;">' . $code . '</p>'
            . '<p style="margin:0 0 6px;color:#6b7280;font-size:13px;">验证码 10 分钟内有效，请勿泄露给他人。</p>'
            . '<p style="margin:0;color:#6b7280;font-size:13px;">如果这不是你的操作，请忽略本邮件。</p>'
            . '</div></div>';
    }
}
