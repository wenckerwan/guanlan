<?php

declare(strict_types=1);

namespace App\Resource;

use App\Model\AnalysisArticle;
use App\Model\Hotspot;
use App\Model\Prediction;
use App\Support\ContentStatus;

/**
 * 文章类 DTO：列表不带 html（省流量），详情才带。
 */
class ArticleResource
{
    public static function listItem(AnalysisArticle|Hotspot|Prediction $model): array
    {
        $base = [
            'id' => (int) $model->id,
            'slug' => (string) $model->slug,
            'title' => (string) $model->title,
            'summary' => (string) $model->summary,
            'priority' => (string) ($model->priority ?? 'A'),
            'status' => ContentStatus::normalize(isset($model->status) ? (string) $model->status : null),
            'commentMode' => (string) ($model->comment_mode ?? 'open'),
            'outline' => array_values((array) ($model->outline ?? [])),
        ];

        if ($model instanceof AnalysisArticle) {
            $base['category'] = (string) $model->category;
            $base['sourceFile'] = (string) $model->source_file;
            $base['wordCount'] = (int) $model->word_count;
            $base['release'] = (bool) $model->release;
        } elseif ($model instanceof Hotspot) {
            $base['period'] = (string) $model->period;
            $base['level'] = (string) $model->level;
            $base['type'] = (string) $model->type;
            $base['tag'] = (string) $model->tag;
        } else {
            $base['layer'] = (string) $model->layer;
            $base['sortOrder'] = (int) $model->sort_order;
            $base['sourceFile'] = (string) $model->source_file;
            $base['wordCount'] = (int) $model->word_count;
        }

        if ($model instanceof AnalysisArticle || $model instanceof Hotspot) {
            $base['revision'] = max(1, (int) ($model->revision ?? 1));
            $base['contentSource'] = (string) ($model->content_source ?? 'dataset');
        }
        return $base;
    }

    public static function detail(AnalysisArticle|Hotspot|Prediction $model): array
    {
        $item = self::listItem($model);
        $item['html'] = (string) $model->html;
        return $item;
    }

    public static function adminDetail(AnalysisArticle|Hotspot $model): array
    {
        $item = self::detail($model);
        $item['wordCount'] = (int) ($model->word_count ?? 0);
        $item['format'] = $model->markdown !== null ? 'markdown' : 'html';
        $item['body'] = $model->markdown !== null ? (string) $model->markdown : (string) $model->html;
        $item['revision'] = (int) ($model->revision ?? 1);
        $item['contentSource'] = (string) ($model->content_source ?? 'dataset');
        $item['subjectId'] = (int) ($model->subject_id ?? 1);
        return $item;
    }

    /**
     * @param iterable<AnalysisArticle|Hotspot|Prediction> $models
     * @param array<string, true> $lockedSlugs 访客超额、需登录才能阅读的 slug
     */
    public static function collection(iterable $models, array $lockedSlugs = []): array
    {
        $out = [];
        foreach ($models as $model) {
            $item = self::listItem($model);
            $item['locked'] = isset($lockedSlugs[(string) $model->slug]);
            $out[] = $item;
        }
        return $out;
    }
}
