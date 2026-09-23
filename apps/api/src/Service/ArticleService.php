<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\AnalysisArticle;
use App\Model\Hotspot;
use App\Model\Prediction;
use Hyperf\Database\Model\Builder;

/**
 * 文章类内容：真题分析、时政热点、时政预测。
 */
class ArticleService
{
    /** @return array<int, AnalysisArticle> */
    public function analysis(string $category = ''): array
    {
        return AnalysisArticle::query()
            ->when($category !== '', fn (Builder $q) => $q->where('category', $category))
            // 发布稿排在同类工作稿之前，再按原文件顺序
            ->orderByDesc('release')
            ->orderBy('sort_order')
            ->get()
            ->all();
    }

    public function analysisBySlug(string $slug): ?AnalysisArticle
    {
        return AnalysisArticle::query()->where('slug', $slug)->first();
    }

    /** @return array<int, Hotspot> */
    public function hotspots(string $period = '', string $priority = ''): array
    {
        return Hotspot::query()
            ->when($period !== '', fn (Builder $q) => $q->where('period', $period))
            ->when($priority !== '', fn (Builder $q) => $q->where('priority', $priority))
            ->orderByRaw("FIELD(priority, 'S', 'A', 'B', 'C')")
            ->orderByDesc('published_at')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /** 首页卡片用的热点：只取卡片字段，按 level 再按更新时间。 */
    /** @return array<int, Hotspot> */
    public function hotspotCards(int $limit = 6): array
    {
        return Hotspot::query()
            ->whereNotNull('slug')
            ->orderByRaw("FIELD(priority, 'S', 'A', 'B', 'C')")
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get()
            ->all();
    }

    public function hotspotBySlug(string $slug): ?Hotspot
    {
        return Hotspot::query()->where('slug', $slug)->first();
    }

    /** @return array<int, Prediction> */
    public function predictions(string $layer = ''): array
    {
        return Prediction::query()
            ->when($layer !== '', fn (Builder $q) => $q->where('layer', $layer))
            ->orderBy('sort_order')
            ->get()
            ->all();
    }

    public function predictionBySlug(string $slug): ?Prediction
    {
        return Prediction::query()->where('slug', $slug)->first();
    }
}

