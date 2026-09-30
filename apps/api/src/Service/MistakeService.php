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

    /**
     * 上传 Markdown 自动入错题本：AI 结构化抽取结果（$aiItems）优先，
     * 为空时回退规则解析（parseMarkdownItems）。按 item_key 幂等去重。
     *
     * @param array<int, array> $aiItems 模型输出的原始条目（extractItems 的 content 解析产物）
     * @return array{imported:int, updated:int, skipped:int}
     */
    public function importUploadedItems(MistakeStudent $student, string $markdown, array $aiItems = []): array
    {
        $candidates = $aiItems !== [] ? $aiItems : self::parseMarkdownItems($markdown);
        $candidates = array_slice($candidates, 0, 100);

        $imported = 0;
        $updated = 0;
        $skipped = 0;
        foreach ($candidates as $candidate) {
            $fields = is_array($candidate) ? self::normalizeUploadedItem($candidate, (int) $student->id) : null;
            if ($fields === null) {
                ++$skipped;
                continue;
            }

            try {
                $existing = MistakeItem::query()
                    ->where('student_id', (int) $student->id)
                    ->where('item_key', $fields['item_key'])
                    ->first();
                if ($existing) {
                    $existing->fill($fields)->save();
                    ++$updated;
                } else {
                    MistakeItem::create($fields + ['student_id' => (int) $student->id]);
                    ++$imported;
                }
            } catch (\Throwable) {
                // 单条失败（如数据库异常）只影响该条，不吞掉整批结果
                ++$skipped;
            }
        }

        return ['imported' => $imported, 'updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * 模型抽取内容 → 错题条目字段；缺少题干或正确答案的条目视为不可导入，返回 null。
     */
    private static function normalizeUploadedItem(array $raw, int $studentId): ?array
    {
        $stem = trim((string) ($raw['stem'] ?? ''));
        $correct = self::normalizeAnswerLetters((string) ($raw['correctAnswer'] ?? ''));
        $chosen = self::normalizeAnswerLetters((string) ($raw['myAnswer'] ?? ''));
        if ($stem === '' || $correct === '') {
            return null;
        }

        $options = [];
        foreach ((array) ($raw['options'] ?? []) as $option) {
            if (! is_array($option)) {
                continue;
            }
            $label = self::normalizeAnswerLetters((string) ($option['label'] ?? ''));
            if ($label === '' || mb_strlen($label) !== 1) {
                continue;
            }
            $mark = '';
            if ($chosen !== '' && str_contains($chosen, $label)) {
                $mark = str_contains($correct, $label) ? 'hit' : 'chosen';
            } elseif (str_contains($correct, $label)) {
                $mark = 'missed';
            }
            $options[] = ['label' => $label, 'text' => trim((string) ($option['text'] ?? '')), 'mark' => $mark];
        }

        $module = trim((string) ($raw['module'] ?? ''));
        $kaodian = trim((string) ($raw['kaodian'] ?? ''));

        return self::buildUploadFields($studentId, $stem, $options, $chosen, $correct, $module, $kaodian);
    }

    /**
     * 规则解析兜底：按「分析页格式示例」切分自由 Markdown。
     * `## 模块 - 来源` 开头切块；题干/答案/考点用 **加粗标签** 或「标签：」行识别，选项按 A. 行识别。
     *
     * @return array<int, array{stem:string,options:array,myAnswer:string,correctAnswer:string,module:string,kaodian:string}>
     */
    public static function parseMarkdownItems(string $markdown): array
    {
        $text = str_replace("\r\n", "\n", $markdown);
        $sections = preg_split('/^##\s+/m', $text) ?: [$text];
        $out = [];
        foreach ($sections as $section) {
            $section = trim($section);
            if ($section === '') {
                continue;
            }

            $module = '未分类';
            $firstLineEnd = (int) (strpos($section, "\n") ?: strlen($section));
            $title = trim(substr($section, 0, $firstLineEnd));
            if (preg_match('/^(.+?)\s*[-—–]\s*\S+$/u', $title, $m)) {
                $module = trim($m[1]);
            }

            $stem = '';
            if (preg_match('/\*\*题干\*\*\s*[:：]\s*(.+?)(?=\n\s*[A-DＡ-Ｄ][\.．、]|\n\s*\*\*|\n##|$)/su', $section, $m)) {
                $stem = trim($m[1]);
            } elseif (preg_match('/题干\s*[:：]\s*(.+?)(?=\n\s*[A-DＡ-Ｄ][\.．、]|\n\s*\*\*|\n##|$)/su', $section, $m)) {
                $stem = trim($m[1]);
            }

            $options = [];
            if (preg_match_all('/^([A-DＡ-Ｄ])[\.．、]\s*(.+)$/mu', $section, $m, PREG_SET_ORDER)) {
                foreach ($m as $match) {
                    $options[] = ['label' => self::normalizeAnswerLetters($match[1]), 'text' => trim($match[2]), 'mark' => ''];
                }
            }

            $chosen = self::normalizeAnswerLetters(self::labeledValue($section, '我的答案'));
            $correct = self::normalizeAnswerLetters(self::labeledValue($section, '正确答案'));
            $kaodian = self::labeledValue($section, '考点');
            if ($stem === '' || $correct === '') {
                continue;
            }

            $out[] = ['stem' => $stem, 'options' => $options, 'myAnswer' => $chosen, 'correctAnswer' => $correct, 'module' => $module, 'kaodian' => $kaodian];
        }

        return $out;
    }

    /** 解析「**标签**: 值」或「标签：值」两种写法的值。 */
    private static function labeledValue(string $section, string $label): string
    {
        $label = preg_quote($label, '/');
        if (preg_match("/(?:\*\*{$label}\*\*|{$label})\s*[:：]\s*(.+)/u", $section, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    private static function normalizeAnswerLetters(string $value): string
    {
        $value = strtr($value, ['Ａ' => 'A', 'Ｂ' => 'B', 'Ｃ' => 'C', 'Ｄ' => 'D']);
        if (! preg_match_all('/[A-D]/', strtoupper($value), $m)) {
            return '';
        }

        return implode('', array_values(array_unique($m[0])));
    }

    private static function buildUploadFields(int $studentId, string $stem, array $options, string $chosen, string $correct, string $module, string $kaodian): array
    {
        foreach ($options as &$option) {
            $label = (string) $option['label'];
            if ($chosen !== '' && str_contains($chosen, $label)) {
                $option['mark'] = str_contains($correct, $label) ? 'hit' : 'chosen';
            } elseif (str_contains($correct, $label)) {
                $option['mark'] = 'missed';
            }
        }
        unset($option);

        // item_key 全局唯一：掺入 student_id，避免不同用户上传同一题时互相撞唯一索引
        $itemKey = 'upload-' . substr(hash('sha256', $studentId . '|' . $stem . '|' . $correct), 0, 24);

        return [
            'module' => mb_substr($module !== '' ? $module : '未分类', 0, 32),
            'chapter' => '',
            'chapter_no' => 0,
            'source_no' => '上传',
            'kaodian' => mb_substr($kaodian, 0, 191),
            'stem' => $stem,
            'options' => $options,
            'my_answer' => mb_substr($chosen, 0, 16),
            'correct_answer' => mb_substr($correct, 0, 16),
            'q_type' => mb_strlen($correct) > 1 ? '多选' : '单选',
            'error_type' => self::errorType($chosen, $correct),
            'origin' => 'upload',
            'item_key' => $itemKey,
            'content_hash' => substr(hash('sha256', (string) json_encode([
                'stem' => $stem,
                'options' => $options,
                'correct' => $correct,
            ], JSON_UNESCAPED_UNICODE)), 0, 16),
            'sort_order' => 0,
        ];
    }

    /**
     * 模型抽取输出 → 原始条目数组：剥代码块围栏、截取首尾大括号之间再 json_decode。
     *
     * @return array<int, array>
     */
    public static function parseAiExtraction(string $content): array
    {
        $content = trim($content);
        if (preg_match('/```(?:json)?\s*(.+?)```/s', $content, $m)) {
            $content = trim($m[1]);
        }
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start === false || $end === false || $end <= $start) {
            return [];
        }
        $decoded = json_decode(substr($content, $start, $end - $start + 1), true);
        if (! is_array($decoded)) {
            return [];
        }
        if (isset($decoded['items']) && is_array($decoded['items'])) {
            return $decoded['items'];
        }
        // 模型偶尔直接输出条目数组
        if (array_is_list($decoded) && isset($decoded[0]['stem'])) {
            return $decoded;
        }

        return [];
    }
}
