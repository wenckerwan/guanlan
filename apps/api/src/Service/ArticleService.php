<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\AnalysisArticle;
use App\Model\Prediction;
use App\Support\ContentStatus;
use Hyperf\Database\Model\Builder;

/**
 * 文章类内容：真题分析、时政预测。
 */
class ArticleService
{
    /** @return array<int, AnalysisArticle> */
    public function analysis(string $category = ''): array
    {
        return AnalysisArticle::query()
            ->where('status', '<>', ContentStatus::HIDDEN)
            ->when($category !== '', fn (Builder $q) => $q->where('category', $category))
            // 发布稿排在同类工作稿之前，再按原文件顺序
            ->orderByDesc('release')
            ->orderBy('sort_order')
            ->get()
            ->all();
    }

    public function analysisBySlug(string $slug): ?AnalysisArticle
    {
        return AnalysisArticle::query()
            ->where('slug', $slug)
            ->where('status', '<>', ContentStatus::HIDDEN)
            ->first();
    }

    /** @return array<int, Prediction> */
    public function predictions(string $layer = ''): array
    {
        return Prediction::query()
            ->where('status', '<>', ContentStatus::HIDDEN)
            ->when($layer !== '', fn (Builder $q) => $q->where('layer', $layer))
            ->orderBy('sort_order')
            ->get()
            ->all();
    }

    public function predictionBySlug(string $slug): ?Prediction
    {
        return Prediction::query()
            ->where('slug', $slug)
            ->where('status', '<>', ContentStatus::HIDDEN)
            ->first();
    }
}

