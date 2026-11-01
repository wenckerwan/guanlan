<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\MistakeItem;
use App\Model\MistakeStudent;
use App\Resource\MistakeResource;
use Hyperf\Database\Model\Builder;
use Hyperf\DbConnection\Db;

/** Administrator reads are separate from student-facing mistake access. */
final class AdminMistakeListService
{
    public function students(string $q = '', int $page = 1, int $perPage = 20): array
    {
        AdminDashboardService::authorize();
        $q = trim($q);
        $query = MistakeStudent::query()->with('owner');
        if ($q !== '') {
            $like = '%' . strtr($q, ['=' => '==', '%' => '=%', '_' => '=_']) . '%';
            $query->where(function (Builder $query) use ($like) {
                $query->whereRaw("code LIKE ? ESCAPE '='", [$like])
                    ->orWhereRaw("name LIKE ? ESCAPE '='", [$like])
                    ->orWhereHas('owner', fn (Builder $owner) => $owner->whereRaw("email LIKE ? ESCAPE '='", [$like]));
            });
        }

        $total = (int) (clone $query)->count();
        $perPage = min(100, max(1, $perPage));
        $page = min(max(1, $page), max(1, (int) ceil($total / $perPage)));
        $students = $query->orderBy('sort_order')->orderBy('id')->forPage($page, $perPage)->get();

        // Aggregate only the selected page, retaining each student's complete item counts.
        $ids = $students->pluck('id')->all();
        $moduleCounts = $this->counts($ids, 'module');
        $errorTypes = $this->counts($ids, 'error_type');
        $items = [];
        foreach ($students as $student) {
            $id = (int) $student->id;
            $items[] = [
                'code' => (string) $student->code,
                'name' => (string) $student->name,
                'relation' => (string) $student->relation,
                'itemCount' => array_sum($moduleCounts[$id] ?? []),
                'moduleCounts' => $moduleCounts[$id] ?? [],
                'errorTypes' => $errorTypes[$id] ?? [],
                'ownerEmail' => (string) ($student->owner?->email ?? ''),
                'ownerId' => $student->owner_user_id ? (int) $student->owner_user_id : null,
                'isDataset' => !(bool) $student->owner_user_id,
            ];
        }

        return compact('items', 'total', 'page', 'perPage');
    }

    public function items(string $code, string $module = '', string $errorType = '', int $page = 1, int $perPage = 20): array
    {
        AdminDashboardService::authorize();
        $student = MistakeStudent::query()->where('code', $code)->first();
        if (!$student) throw new \RuntimeException('考生不存在', 404);

        $allItems = MistakeItem::query()->where('student_id', (int) $student->id);
        $query = clone $allItems;
        // Both fields are existing free text values; preserve exact matching, including whitespace.
        if ($module !== '') $query->where('module', $module);
        if ($errorType !== '') $query->where('error_type', $errorType);
        $total = (int) (clone $query)->count();
        $perPage = min(100, max(1, $perPage));
        $page = min(max(1, $page), max(1, (int) ceil($total / $perPage)));
        $items = $query->orderBy('sort_order')->orderBy('id')->forPage($page, $perPage)->get();

        return [
            'code' => (string) $student->code,
            'name' => (string) $student->name,
            'items' => MistakeResource::items($items),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'filters' => [
                'modules' => (clone $allItems)->distinct()->orderBy('module')->pluck('module')->all(),
                'errorTypes' => (clone $allItems)->distinct()->orderBy('error_type')->pluck('error_type')->all(),
            ],
        ];
    }

    private function counts(array $ids, string $column): array
    {
        if ($ids === []) return [];
        $rows = Db::table('mistake_items')->whereIn('student_id', $ids)
            ->selectRaw("student_id, $column AS bucket, COUNT(*) AS total")
            ->groupBy('student_id', $column)->get();
        $counts = [];
        foreach ($rows as $row) $counts[(int) $row->student_id][(string) $row->bucket] = (int) $row->total;
        return $counts;
    }
}
