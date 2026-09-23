<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\SearchService;
use App\Support\ApiResponse;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class SearchController
{
    private const TYPES = ['question', 'paper', 'analysis', 'hotspot', 'prediction', 'mock', 'mistake'];

    public function __construct(
        private SearchService $service,
        private RequestInterface $request
    ) {
    }

    /**
     * GET /api/v1/search?q=&type=&limit=
     * `q` 为空返回空结果而不是 400，前端首屏不会因空关键词报错。
     */
    public function index(): ResponseInterface
    {
        $keyword = trim((string) $this->request->input('q', ''));
        $type = (string) $this->request->input('type', '');
        if ($type !== '' && ! in_array($type, self::TYPES, true)) {
            return ApiResponse::message('type 取值不合法', 422, ['type' => '允许值：' . implode('、', self::TYPES)]);
        }
        if (mb_strlen($keyword) > 60) {
            return ApiResponse::message('关键词过长', 422, ['q' => '关键词最多 60 个字符']);
        }

        $limit = (int) $this->request->input('limit', 40);
        $limit = max(1, min(100, $limit ?: 40));

        return ApiResponse::data($this->service->search($keyword, $type, $limit));
    }
}