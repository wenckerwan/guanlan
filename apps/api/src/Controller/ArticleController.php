<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\AnalysisArticle;
use App\Model\Prediction;
use App\Resource\ArticleResource;
use App\Service\ArticleService;
use App\Support\ApiResponse;
use App\Support\GuestQuota;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 文章类内容：列表带 locked 标记，详情对超额访客返回 403。
 *
 * 配额按「栏目整体顺序」计算，与列表上的筛选条件无关，
 * 这样列表与详情的免费 / 锁定边界始终一致。
 */
class ArticleController
{
    public function __construct(
        private ArticleService $service,
        private RequestInterface $request
    ) {
    }

    public function analysisIndex(): ResponseInterface
    {
        $all = $this->service->analysis();

        return ApiResponse::data(ArticleResource::collection(
            $this->service->analysis((string) $this->request->input('category', '')),
            $this->lockedSlugs($all)
        ));
    }

    public function analysisShow(string $slug): ResponseInterface
    {
        $article = $this->service->analysisBySlug($slug);
        if (! $article) {
            return ApiResponse::message('文章不存在', 404);
        }

        return $this->detailOrQuota($this->service->analysis(), $slug, $article);
    }

    public function predictionIndex(): ResponseInterface
    {
        return ApiResponse::data(ArticleResource::collection(
            $this->service->predictions((string) $this->request->input('layer', '')),
            $this->lockedSlugs($this->service->predictions())
        ));
    }

    public function predictionShow(string $slug): ResponseInterface
    {
        $prediction = $this->service->predictionBySlug($slug);
        if (! $prediction) {
            return ApiResponse::message('预测内容不存在', 404);
        }

        return $this->detailOrQuota($this->service->predictions(), $slug, $prediction);
    }

    /**
     * 按栏目整体顺序算出被锁定的 slug 集合；登录用户为空集。
     *
     * @param iterable<AnalysisArticle|Prediction> $ordered
     * @return array<string, true>
     */
    private function lockedSlugs(iterable $ordered): array
    {
        $locked = [];
        $index = 0;
        foreach ($ordered as $item) {
            if (GuestQuota::locked($index)) {
                $locked[(string) $item->slug] = true;
            }
            $index++;
        }

        return $locked;
    }

    /**
     * 详情统一入口：访客超出本栏目配额时拒绝，否则返回完整正文。
     *
     * @param iterable<AnalysisArticle|Prediction> $ordered
     */
    private function detailOrQuota(iterable $ordered, string $slug, object $model): ResponseInterface
    {
        if (isset($this->lockedSlugs($ordered)[$slug])) {
            return ApiResponse::message('登录后查看完整内容', 403);
        }

        return ApiResponse::data(ArticleResource::detail($model));
    }
}
