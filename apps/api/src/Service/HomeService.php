<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\AnalysisArticle;
use App\Model\Document;
use App\Model\Hotspot;
use App\Model\MistakeItem;
use App\Model\Paper;
use App\Model\Prediction;
use App\Model\Question;
use App\Model\Subject;

class HomeService
{
    public function __construct(private ArticleService $articles)
    {
    }

    /**
     * 首页热点卡片：优先时政长文（slug 非空），再回落到 dev.2 的卡片式热点。
     *
     * @return array<int, Hotspot>
     */
    public function hotspots(): array
    {
        $long = $this->articles->hotspotCards(6);
        if ($long !== []) {
            return $long;
        }

        return Hotspot::query()
            ->with(['subject', 'chapter'])
            ->orderByRaw("FIELD(level, 'S', 'A', 'B', 'C')")
            ->orderByDesc('updated_at')
            ->get()
            ->all();
    }

    /**
     * 资料卡：按 sort_order。
     *
     * @return array<int, Document>
     */
    public function documents(): array
    {
        return Document::query()
            ->orderBy('sort_order')
            ->get()
            ->all();
    }

    /**
     * 学科摘要：按 sort_order。
     *
     * @return array<int, Subject>
     */
    public function subjects(): array
    {
        return Subject::query()
            ->orderBy('sort_order')
            ->get()
            ->all();
    }

    /**
     * 首页统计条：真实数据规模，不再写死。
     *
     * @return array<string, int>
     */
    public function stats(): array
    {
        return [
            'questions' => (int) Question::query()->count(),
            'papers' => (int) Paper::query()->count(),
            'hotspots' => (int) Hotspot::query()->whereNotNull('slug')->count(),
            'predictions' => (int) Prediction::query()->count(),
            'analysis' => (int) AnalysisArticle::query()->count(),
            'mistakes' => (int) MistakeItem::query()->count(),
        ];
    }
}
