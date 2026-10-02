<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AdminAuditService;
use App\Service\ShizhengScreeningService;
use App\Support\ApiResponse;
use App\Support\Auth;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 每日时政：AI 配置（后台可填）+ 候选池 + 筛选 + 发布。
 * 全部挂在 /api/v1/admin 组下，需管理员权限。
 */
class AdminShizhengController
{
    public function __construct(
        private ShizhengScreeningService $service,
        private AdminAuditService $audit,
        private RequestInterface $request
    ) {
    }

    public function getConfig(): ResponseInterface
    {
        return ApiResponse::data($this->service->getPublicConfig());
    }

    public function saveConfig(): ResponseInterface
    {
        $config = $this->service->saveConfig($this->request->all());
        $this->audit->log(Auth::user(), 'shizheng.config.save', 'shizheng_config', 'shizheng.ai', ['model' => $config['model'], 'provider' => $config['provider']]);
        return ApiResponse::data($config);
    }

    public function testConfig(): ResponseInterface
    {
        $result = $this->service->testConfig();
        return $result['ok']
            ? ApiResponse::data($result)
            : ApiResponse::message($result['error'], 502);
    }

    /** 爬虫推送当天候选：{date: 'YYYY-MM-DD', items: [...]} */
    public function pushCandidates(): ResponseInterface
    {
        $date = trim((string) $this->request->input('date', ''));
        $items = $this->request->input('items', []);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ApiResponse::message('date 格式应为 YYYY-MM-DD', 422);
        }
        if (! is_array($items) || $items === []) {
            return ApiResponse::message('items 不能为空', 422);
        }
        if (count($items) > 200) {
            return ApiResponse::message('单次最多 200 条', 422);
        }
        return ApiResponse::data($this->service->upsertCandidates($date, $items), 201);
    }

    public function candidates(): ResponseInterface
    {
        $date = trim((string) $this->request->input('date', ''));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ApiResponse::message('date 格式应为 YYYY-MM-DD', 422);
        }
        $rows = $this->service->candidates($date);
        return ApiResponse::data(array_map(fn ($c) => [
            'id' => $c->id,
            'title' => $c->title,
            'source' => $c->source,
            'channel' => $c->channel,
            'url' => $c->url,
            'status' => $c->status,
            'priority' => (string) ($c->payload['priority'] ?? ''),
            'module' => (string) ($c->payload['module'] ?? ''),
            'type' => (string) ($c->payload['type'] ?? ''),
            'aiPriority' => $c->ai_priority,
            'aiModule' => $c->ai_module,
            'aiReason' => $c->ai_reason,
            'examSim' => round((float) $c->exam_sim, 4),
            'examAffinity' => round((float) $c->exam_affinity, 4),
            'examMatches' => (array) ($c->exam_matches ?? []),
            'hotspotId' => $c->hotspot_id,
        ], $rows));
    }

    /** 筛选：{date, top?, auto?, strategy?} auto=true 时筛完直接发布 */
    public function screen(): ResponseInterface
    {
        $date = trim((string) $this->request->input('date', ''));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ApiResponse::message('date 格式应为 YYYY-MM-DD', 422);
        }
        $top = (int) $this->request->input('top', 0);
        if ($top <= 0) {
            $top = $this->service->getConfig()['topN'];
        }
        $auto = filter_var($this->request->input('auto', false), FILTER_VALIDATE_BOOLEAN);
        $strategy = trim((string) $this->request->input('strategy', 'ai')) ?: 'ai';
        $result = $this->service->screen($date, $top, $auto, $strategy);
        if (! isset($result['error'])) {
            // fallback_reason 进审计：降级不能只在响应里一闪而过，否则又没人知道
            $this->audit->log(Auth::user(), 'shizheng.screen', 'shizheng_candidates', $date, [
                'top' => $top,
                'auto' => $auto,
                'strategy' => $result['strategy'] ?? $strategy,
                'fallbackReason' => $result['fallbackReason'] ?? null,
                'selected' => count($result['selected'] ?? []),
            ]);
        }
        return isset($result['error'])
            ? ApiResponse::message($result['error'], 422)
            : ApiResponse::data($result);
    }

    /** 手动发布选中项：{ids: [1,2,3]} */
    public function publish(): ResponseInterface
    {
        $ids = $this->request->input('ids', []);
        if (! is_array($ids) || $ids === []) {
            return ApiResponse::message('ids 不能为空', 422);
        }
        $result = $this->service->publish(array_map('intval', $ids));
        $this->audit->log(Auth::user(), 'shizheng.publish', 'shizheng_candidates', implode(',', array_map('intval', $ids)), $result);
        return ApiResponse::data($result);
    }
}
