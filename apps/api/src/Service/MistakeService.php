<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\MistakeHandbook;
use App\Model\MistakeItem;
use App\Model\MistakeStudent;
use Hyperf\Database\Model\Builder;

/**
 * 个人错题分析：考生之间完全隔离，查询一律带 student_id 约束。
 */
class MistakeService
{
    /** @return array<int, MistakeStudent> */
    public function students(): array
    {
        return MistakeStudent::query()->orderBy('sort_order')->orderBy('id')->get()->all();
    }

    public function student(string $code): ?MistakeStudent
    {
        return MistakeStudent::query()->where('code', $code)->first();
    }

    public function items(int $studentId, string $module = '', string $errorType = ''): array
    {
        return MistakeItem::query()
            ->where('student_id', $studentId)
            ->when($module !== '', fn (Builder $q) => $q->where('module', $module))
            ->when($errorType !== '', fn (Builder $q) => $q->where('error_type', $errorType))
            ->orderBy('sort_order')
            ->get()
            ->all();
    }

    /** @return array<string, int> 各模块错题数 */
    public function moduleCounts(int $studentId): array
    {
        $rows = MistakeItem::query()
            ->selectRaw('module, count(*) as total')
            ->where('student_id', $studentId)
            ->groupBy('module')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->module] = (int) $row->total;
        }
        return $out;
    }

    /** @return array<string, int> 错因分布 */
    public function errorTypeCounts(int $studentId): array
    {
        $rows = MistakeItem::query()
            ->selectRaw('error_type, count(*) as total')
            ->where('student_id', $studentId)
            ->groupBy('error_type')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->error_type] = (int) $row->total;
        }
        return $out;
    }

    /** @return array<int, MistakeHandbook> */
    public function handbooks(int $studentId): array
    {
        return MistakeHandbook::query()
            ->where('student_id', $studentId)
            ->orderBy('sort_order')
            ->get()
            ->all();
    }

    public function handbook(int $id): ?MistakeHandbook
    {
        return MistakeHandbook::find($id);
    }
}
