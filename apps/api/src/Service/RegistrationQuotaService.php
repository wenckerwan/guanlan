<?php
declare(strict_types=1);

namespace App\Service;

use Hyperf\DbConnection\Db;

final class RegistrationQuotaService
{
    /** Called only after a valid email code; database errors must fail closed. */
    public function reserve(string $ip): ?string
    {
        return Db::transaction(function () use ($ip): ?string {
            $key = hash('sha256', 'register-ip:' . $ip);
            Db::table('registration_quotas')->upsert([
                ['quota_key' => $key, 'attempts' => 0, 'expires_at' => date('Y-m-d H:i:s', time() + 86400)],
            ], ['quota_key'], ['quota_key']);
            $row = Db::table('registration_quotas')->where('quota_key', $key)->lockForUpdate()->first();
            $now = time();
            $count = strtotime((string) $row->expires_at) <= $now ? 0 : (int) $row->attempts;
            $limit = max(1, (int) (getenv('REGISTRATION_IP_DAILY_LIMIT') ?: 5));
            if ($count >= $limit) return '该网络今日注册次数已达上限，请稍后再试';
            Db::table('registration_quotas')->where('quota_key', $key)->update([
                'attempts' => $count + 1,
                'expires_at' => $count === 0 ? date('Y-m-d H:i:s', $now + 86400) : $row->expires_at,
            ]);
            return null;
        }, 3);
    }
}
