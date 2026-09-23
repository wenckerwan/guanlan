<?php

declare(strict_types=1);

namespace App\Resource;

use App\Model\AnalysisArticle;
use App\Model\Hotspot;
use App\Model\Prediction;

/**
 * 文章类 DTO：列表不带 html（省流量），详情才带。
 */
class ArticleResource
{
    public static function listItem(AnalysisArticle|Hotspot|Prediction $model): array
    {
        $base = [
            'slug' => (string) $model->slug,
            'title' => (string) $model->title,
            'summary' => (string) $model->summary,
            'priority' => (string) ($model->priority ?? 'A'),
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
            $base['sourceFile'] = (string) $model->source_file;
            $base['wordCount'] = (int) $model->word_count;
        }

        return $base;
    }

    public static function detail(AnalysisArticle|Hotspot|Prediction $model): array
    {
        $item = self::listItem($model);
        $item['html'] = (string) $model->html;
        return $item;
    }

    /** @param iterable<AnalysisArticle|Hotspot|Prediction> $models */
    public static function collection(iterable $models): array
    {
        $out = [];
        foreach ($models as $model) {
            $out[] = self::listItem($model);
        }
        return $out;
    }
}
