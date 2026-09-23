<?php

declare(strict_types=1);

namespace App\Controller;

use App\Resource\ArticleResource;
use App\Service\ArticleService;
use App\Support\ApiResponse;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class ArticleController
{
    public function __construct(
        private ArticleService $service,
        private RequestInterface $request
    ) {
    }

    public function analysisIndex(): ResponseInterface
    {
        $category = (string) $this->request->input('category', '');
        return ApiResponse::data(ArticleResource::collection($this->service->analysis($category)));
    }

    public function analysisShow(string $slug): ResponseInterface
    {
        $article = $this->service->analysisBySlug($slug);
        if (! $article) {
            return ApiResponse::message('文章不存在', 404);
        }
        return ApiResponse::data(ArticleResource::detail($article));
    }

    public function hotspotIndex(): ResponseInterface
    {
        $items = $this->service->hotspots(
            (string) $this->request->input('period', ''),
            (string) $this->request->input('priority', '')
        );
        return ApiResponse::data(ArticleResource::collection($items));
    }

    public function hotspotShow(string $slug): ResponseInterface
    {
        $hotspot = $this->service->hotspotBySlug($slug);
        if (! $hotspot) {
            return ApiResponse::message('时政内容不存在', 404);
        }
        return ApiResponse::data(ArticleResource::detail($hotspot));
    }

    public function predictionIndex(): ResponseInterface
    {
        $layer = (string) $this->request->input('layer', '');
        return ApiResponse::data(ArticleResource::collection($this->service->predictions($layer)));
    }

    public function predictionShow(string $slug): ResponseInterface
    {
        $prediction = $this->service->predictionBySlug($slug);
        if (! $prediction) {
            return ApiResponse::message('预测内容不存在', 404);
        }
        return ApiResponse::data(ArticleResource::detail($prediction));
    }
}
