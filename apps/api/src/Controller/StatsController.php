<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\QuestionService;
use App\Service\StatsService;
use App\Support\ApiResponse;
use App\Support\Auth;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class StatsController
{
    public function __construct(
        private QuestionService $questions,
        private StatsService $stats,
        private RequestInterface $request
    ) {
    }

    public function overview(): ResponseInterface
    {
        return ApiResponse::data([
            'modules' => $this->questions->moduleSummary(),
        ]);
    }

    /** 心跳上报浏览时长：{seconds: 30}，需登录 */
    public function heartbeat(): ResponseInterface
    {
        $user = Auth::user();
        if (! $user) {
            return ApiResponse::message('未登录', 401);
        }
        $seconds = (int) $this->request->input('seconds', 0);
        if ($seconds <= 0) {
            return ApiResponse::message('seconds 无效', 422);
        }
        return ApiResponse::data($this->stats->heartbeat($user, $seconds));
    }

    /** 学习时长排行榜：?period=week|total&limit=20，公开（仅昵称+组+时长） */
    public function leaderboard(): ResponseInterface
    {
        $period = $this->request->input('period', 'week') === 'total' ? 'total' : 'week';
        $limit = (int) $this->request->input('limit', 20);
        return ApiResponse::data([
            'period' => $period,
            'items' => $this->stats->leaderboard($period, $limit),
        ]);
    }
}
