<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\User;
use App\Model\UserStudyStat;
use App\Support\UserGroup;

/**
 * 学习时长统计：前端心跳上报浏览时长，按用户按天累计。
 */
class StatsService
{
    public function heartbeat(User $user, int $seconds): array
    {
        $seconds = min(120, max(1, $seconds));
        $today = date('Y-m-d');

        $row = UserStudyStat::query()->firstOrNew([
            'user_id' => (int) $user->id,
            'date' => $today,
        ]);
        $row->seconds = (int) $row->seconds + $seconds;
        $row->save();

        return ['seconds' => $seconds, 'totalToday' => (int) $row->seconds];
    }

    /**
     * 学习时长排行榜：period=week 本周一至今，total 累计全部。
     * 只返回昵称、用户组、时长，不含邮箱等敏感字段。
     */
    public function leaderboard(string $period, int $limit = 20): array
    {
        $query = UserStudyStat::query()
            ->join('users', 'users.id', '=', 'user_study_stats.user_id')
            ->where('users.status', 'active')
            ->groupBy('user_study_stats.user_id', 'users.display_name', 'users.role', 'users.user_group')
            ->selectRaw('user_study_stats.user_id, users.display_name, users.role, users.user_group, SUM(user_study_stats.seconds) AS total_seconds');

        if ($period === 'week') {
            $query->where('user_study_stats.date', '>=', date('Y-m-d', strtotime('monday this week')));
        }

        return $query
            ->orderByDesc('total_seconds')
            ->limit(max(1, min(50, $limit)))
            ->get()
            ->map(fn ($row) => [
                'userId' => (int) $row->user_id,
                'displayName' => (string) $row->display_name,
                'role' => (string) $row->role,
                'userGroup' => UserGroup::normalize($row->user_group),
                'seconds' => (int) $row->total_seconds,
            ])
            ->all();
    }
}
