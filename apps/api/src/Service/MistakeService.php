<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\MistakeHandbook;
use App\Model\MistakeItem;
use App\Model\MistakeStudent;
use App\Model\Question;
use Hyperf\Database\Model\Builder;

/**
 * 个人错题分析：查询一律带 student_id 约束，可见性由 MistakeAccess 统一裁决。
 */
class MistakeService
{
    /** @return array<int, MistakeStudent> */
    public function students(): array
    {
        return MistakeStudent::query()->orderBy('sort_order')->orderBy('id')->get()->all();
    }

    /** @param array<int, string>|null $allowedCodes null 表示不限 */
    /** @return array<int, MistakeStudent> */
    public function studentsWithin(?array $allowedCodes): array
    {
        $query = MistakeStudent::query()->orderBy('sort_order')->orderBy('id');
        if ($allowedCodes !== null) {
            $query->whereIn('code', $allowedCodes);
        }

        return $query->get()->all();
    }

    public function student(string $code): ?MistakeStudent
    {
        return MistakeStudent::query()->where('code', $code)->first();
    }

    /** @return array{items: array<int, MistakeItem>, total: int} */
    public function items(
        int $studentId,
        string $module = '',
        string $errorType = '',
        int $page = 1,
        int $perPage = 20
    ): array
    {
        $query = MistakeItem::query()
            ->where('student_id', $studentId)
            ->when($module !== '', fn (Builder $q) => $q->where('module', $module))
            ->when($errorType !== '', fn (Builder $q) => $q->where('error_type', $errorType));

        $total = (int) (clone $query)->count();
        $items = $query->orderBy('sort_order')
            ->forPage(max(1, $page), max(1, min(100, $perPage)))
            ->get()
            ->all();

        return ['items' => $items, 'total' => $total];
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
        return MistakeHandbook::query()->with('student')->where('id', $id)->first();
    }

    public function item(int $id): ?MistakeItem
    {
        return MistakeItem::query()->with('student')->where('id', $id)->first();
    }

    public function updateAction(MistakeItem $item, string $action): MistakeItem
    {
        $item->action = $action;
        $item->save();

        return $item;
    }

    public function deleteItem(MistakeItem $item): bool
    {
        return (bool) $item->delete();
    }

    /**
     * 真题交卷自动归集：按 item_key（paper-q{题目id}）upsert，
     * 同题重复交卷只更新最新作答，不产生重复错题。
     * options 沿用数据集明细的 {label, text, mark} 结构，前端错题册直接可渲染。
     */
    public function collectWrongChoice(MistakeStudent $student, Question $question, string $chosen): bool
    {
        $correct = StudyService::normalizeLetters((string) $question->answer);
        $chosen = StudyService::normalizeLetters($chosen);
        $itemKey = 'paper-q' . $question->id;

        $options = [];
        foreach ((array) ($question->options ?? []) as $letter => $text) {
            $letter = (string) $letter;
            $mark = '';
            if ($chosen !== '' && str_contains($chosen, $letter)) {
                $mark = str_contains($correct, $letter) ? 'hit' : 'chosen';
            } elseif (str_contains($correct, $letter)) {
                $mark = 'missed';
            }
            $options[] = ['label' => $letter, 'text' => (string) $text, 'mark' => $mark];
        }

        $stem = trim((string) $question->material . "\n" . (string) $question->stem);
        $fields = [
            'module' => mb_substr((string) $question->module_name, 0, 32),
            'chapter' => '',
            'chapter_no' => 0,
            'source_no' => mb_substr($question->year . '·' . $question->no, 0, 16),
            'kaodian' => mb_substr((string) $question->kaodian, 0, 191),
            'stem' => $stem,
            'options' => $options,
            'my_answer' => mb_substr($chosen, 0, 16),
            'correct_answer' => mb_substr($correct, 0, 16),
            'q_type' => mb_substr((string) $question->type_cn, 0, 16),
            'error_type' => self::errorType($chosen, $correct),
            'origin' => 'paper',
            'item_key' => $itemKey,
            'content_hash' => substr(hash('sha256', (string) json_encode([
                'stem' => $stem,
                'options' => $options,
                'correct' => $correct,
            ], JSON_UNESCAPED_UNICODE)), 0, 16),
            'sort_order' => 0,
        ];

        $existing = MistakeItem::query()
            ->where('student_id', (int) $student->id)
            ->where('item_key', $itemKey)
            ->first();
        if ($existing) {
            $existing->fill($fields)->save();

            return false;
        }

        MistakeItem::create($fields + ['student_id' => (int) $student->id]);

        return true;
    }

    /** 错因口径与数据集导入（build_mistakes.py）保持一致。 */
    private static function errorType(string $chosen, string $correct): string
    {
        if ($correct === '') {
            return '未知';
        }
        if ($chosen === '') {
            return '未记录';
        }

        $a = array_fill_keys(str_split($chosen), true);
        $b = array_fill_keys(str_split($correct), true);
        if ($a == $b) {
            return '正确';
        }
        if (array_intersect_key($a, $b) !== []) {
            return '既漏又错';
        }

        return mb_strlen($chosen) >= mb_strlen($correct) ? '纯错选' : '纯漏选';
    }
}
