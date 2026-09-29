<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\User;
use Hyperf\DbConnection\Db;

/**
 * 管理员操作审计：所有后台写操作落 admin_audit_logs。
 */
class AdminAuditService
{
    public function log(User $admin, string $action, string $targetType, string $targetId, array $detail = []): void
    {
        Db::table('admin_audit_logs')->insert([
            'admin_id' => (int) $admin->id,
            'action' => mb_substr($action, 0, 64),
            'target_type' => mb_substr($targetType, 0, 32),
            'target_id' => mb_substr($targetId, 0, 64),
            'detail' => $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
