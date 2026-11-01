<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\Paper;
use App\Model\Question;
use Hyperf\Database\Model\Builder;

/**
 * 真题回顾：试卷列表、试卷详情、逐题检索。
 * `reveal=0` 时隐藏答案与解析，用于在线作答。
 */
class QuestionService
{
    /** @return array<int, Paper> */
    public function papers(?int $year = null, string $module = '', string $keyword = ''): array
    {
        return Paper::query()
            ->when($year, fn (Builder $q) => $q->where('year', $year))
            ->when($module !== '', fn (Builder $q) => $q->whereHas(
                'questions',
                fn (Builder $inner) => $inner->where('module', $module)
            ))
            ->when($keyword !== '', fn (Builder $q) => $q->where('pid', 'like', "%{$keyword}%"))
            ->orderBy('sort_order')
            ->get()
            ->all();
    }

    public function paper(string $pid): ?Paper
    {
        return Paper::query()->where('pid', $pid)->first();
    }

    /** @return array<int, Question> */
    public function paperQuestions(string $pid): array
    {
        return Question::query()->where('pid', $pid)->orderBy('sort_order')->orderBy('no')->orderBy('id')->get()->all();
    }

    /**
     * @return array{items: array<int, Question>, total: int}
     */
    public function search(
        string $module = '',
        string $type = '',
        string $keyword = '',
        ?int $year = null,
        int $page = 1,
        int $perPage = 20
    ): array {
        $query = Question::query()
            ->when($module !== '', fn (Builder $q) => $q->where('module', $module))
            ->when($type !== '', fn (Builder $q) => $q->where('type', $type))
            ->when($year, fn (Builder $q) => $q->where('year', $year))
            ->when($keyword !== '', function (Builder $q) use ($keyword) {
                $like = "%{$keyword}%";
                $q->where(fn (Builder $inner) => $inner
                    ->where('stem', 'like', $like)
                    ->orWhere('kaodian', 'like', $like)
                    ->orWhere('material', 'like', $like));
            });

        $total = (clone $query)->count();

        $items = $query->orderBy('year', 'desc')
            ->orderBy('no')
            ->forPage(max(1, $page), max(1, $perPage))
            ->get()
            ->all();

        return ['items' => $items, 'total' => (int) $total];
    }

    public function find(int $id): ?Question
    {
        return Question::find($id);
    }

    /** @return array<int, array{module: string, name: string, count: int}> */
    public function moduleSummary(): array
    {
        $rows = Question::query()
            ->selectRaw('module, module_name, count(*) as total')
            ->groupBy('module', 'module_name')
            ->orderByDesc('total')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'module' => (string) $row->module,
                'name' => (string) $row->module_name,
                'count' => (int) $row->total,
            ];
        }
        return $out;
    }
}
