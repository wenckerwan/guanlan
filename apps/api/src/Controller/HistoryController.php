<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\HistoryService;
use App\Support\ApiResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * 史纲（近现代史时间实验室）只读数据接口。
 */
class HistoryController
{
    public function __construct(private HistoryService $service)
    {
    }

    /**
     * GET /api/v1/history/events
     * 公开只读（AuthMiddleware 仅解析身份，不强制登录）。
     * 返回 { data: HistoryDataset }；无任何已发布版本时 404。
     */
    public function events(): ResponseInterface
    {
        $dataset = $this->service->latestPublished();
        if ($dataset === null) {
            return ApiResponse::message('历史数据集尚未发布', 404);
        }

        return ApiResponse::data($dataset);
    }
}
