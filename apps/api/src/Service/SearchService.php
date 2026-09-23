<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\AnalysisArticle;
use App\Model\Hotspot;
use App\Model\MistakeItem;
use App\Model\Mock;
use App\Model\Paper;
use App\Model\Prediction;
use App\Model\Question;

/**
 * 全站统一检索：把六类内容收敛成一个结果列表。
 *
 * 结果契约（camelCase）：
 *   type    question | paper | analysis | hotspot | prediction | mock | mistake
 *   title   主标题
 *   snippet 摘要片段
 *   url     站内相对路由
 *   meta    右侧标签（年份 / 模块 / 期间等）
 */
class SearchService
{
    private const PER_TYPE = 12;

    /** @return array{items: array<int, array<string, string>>, total: int, groups: array<string, int>} */
    public function search(string $keyword, string $type = '', int $limit = 40): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return ['items' => [], 'total' => 0, 'groups' => []];
        }

        $like = '%' . $keyword . '%';

        $items = array_merge(
            $this->questions($like),
            $this->papers($like),
            $this->articles(AnalysisArticle::query(), 'analysis', $like),
            $this->articles(Hotspot::query(), 'hotspot', $like),
            $this->articles(Prediction::query(), 'prediction', $like),
            $this->mocks($like),
            $this->mistakes($like),
        );

        $groups = [];
        foreach ($items as $item) {
            $groups[$item['type']] = ($groups[$item['type']] ?? 0) + 1;
        }

        if ($type !== '') {
            $items = array_values(array_filter($items, fn (array $i) => $i['type'] === $type));
        }

        $items = array_slice($items, 0, max(1, $limit));

        return ['items' => $items, 'total' => count($items), 'groups' => $groups];
    }

    /** @return array<int, array<string, string>> */
    private function questions(string $like): array
    {
        $rows = Question::query()
            ->where(function ($q) use ($like) {
                $q->where('stem', 'like', $like)
                    ->orWhere('kaodian', 'like', $like)
                    ->orWhere('material', 'like', $like)
                    ->orWhere('analysis', 'like', $like);
            })
            ->orderBy('year', 'desc')
            ->orderBy('no')
            ->limit(self::PER_TYPE)
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->item(
                'question',
                sprintf('%d 年第 %d 题', (int) $row->year, (int) $row->no),
                (string) $row->stem,
                sprintf('/papers/%s#q%d', rawurlencode((string) $row->pid), (int) $row->no),
                trim((string) $row->module_name, ' ') ?: (string) $row->type_cn
            );
        }
        return $out;
    }

    /** @return array<int, array<string, string>> */
    private function papers(string $like): array
    {
        $rows = Paper::query()
            ->where(function ($q) use ($like) {
                $q->where('pid', 'like', $like)->orWhere('label', 'like', $like);
            })
            ->orderBy('sort_order')
            ->limit(self::PER_TYPE)
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->item(
                'paper',
                sprintf('%d 年 %s', (int) $row->year, (string) ($row->label ?: '统考真题')),
                sprintf('共 %d 题，满分 %d 分', (int) $row->question_count, (int) $row->total_score),
                '/papers/' . rawurlencode((string) $row->pid),
                (string) $row->kind
            );
        }
        return $out;
    }

    /**
     * 真题分析 / 时政热点 / 时政预测共用一套检索。
     *
     * @param \Hyperf\Database\Model\Builder $query
     * @return array<int, array<string, string>>
     */
    private function articles($query, string $type, string $like): array
    {
        $rows = $query
            ->where(function ($q) use ($like) {
                $q->where('title', 'like', $like)
                    ->orWhere('summary', 'like', $like)
                    ->orWhere('html', 'like', $like);
            })
            ->limit(self::PER_TYPE)
            ->get();

        $prefix = ['analysis' => '/analysis/', 'hotspot' => '/hotspots/', 'prediction' => '/predictions/'][$type];
        $out = [];
        foreach ($rows as $row) {
            $meta = match ($type) {
                'analysis' => (string) $row->category,
                'hotspot' => (string) $row->period,
                default => (string) $row->layer,
            };
            $out[] = $this->item(
                $type,
                (string) $row->title,
                (string) $row->summary,
                $prefix . rawurlencode((string) $row->slug),
                $meta
            );
        }
        return $out;
    }

    /** @return array<int, array<string, string>> */
    private function mocks(string $like): array
    {
        $rows = Mock::query()
            ->where(function ($q) use ($like) {
                $q->where('title', 'like', $like)->orWhere('slug', 'like', $like);
            })
            ->limit(self::PER_TYPE)
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->item(
                'mock',
                (string) $row->title,
                sprintf('%d 题模拟押题卷', (int) $row->question_count),
                '/mocks/' . rawurlencode((string) $row->slug),
                '模拟押题'
            );
        }
        return $out;
    }

    /** @return array<int, array<string, string>> */
    private function mistakes(string $like): array
    {
        $rows = MistakeItem::query()
            ->with('student')
            ->where(function ($q) use ($like) {
                $q->where('stem', 'like', $like)
                    ->orWhere('kaodian', 'like', $like)
                    ->orWhere('chapter', 'like', $like);
            })
            ->limit(self::PER_TYPE)
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $code = (string) ($row->student?->code ?? '');
            if ($code === '') {
                continue;
            }
            $out[] = $this->item(
                'mistake',
                (string) $row->stem,
                sprintf('我选 %s ｜ 正确 %s ｜ %s', (string) ($row->my_answer ?: '未记录'), (string) $row->correct_answer, (string) $row->error_type),
                sprintf('/mistakes/%s/%d', rawurlencode($code), (int) $row->id),
                trim(sprintf('%s %s', (string) $row->module, (string) $row->chapter))
            );
        }
        return $out;
    }

    /** @return array<string, string> */
    private function item(string $type, string $title, string $snippet, string $url, string $meta): array
    {
        return [
            'type' => $type,
            'title' => mb_substr(trim($title), 0, 120),
            'snippet' => mb_substr(preg_replace('/\s+/u', ' ', trim(strip_tags($snippet))) ?? '', 0, 160),
            'url' => $url,
            'meta' => trim($meta),
        ];
    }
}