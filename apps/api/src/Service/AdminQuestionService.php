<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Paper;
use App\Model\Question;
use App\Resource\QuestionResource;
use App\Support\Auth;
use App\Support\ContentMaintenance;

final class AdminQuestionService
{
    private const TYPES = ['single', 'multi', 'analyse', 'discern', 'essay', 'material', 'simple'];
    private const FIELDS = [
        'type'=>'type', 'typeCn'=>'type_cn', 'stem'=>'stem', 'material'=>'material',
        'options'=>'options', 'answer'=>'answer', 'answerText'=>'answer_text', 'analysis'=>'analysis',
        'module'=>'module', 'moduleName'=>'module_name', 'kaodian'=>'kaodian', 'score'=>'score', 'sortOrder'=>'sort_order',
    ];
    private const CHARS = ['typeCn'=>32, 'module'=>16, 'moduleName'=>64, 'kaodian'=>191, 'answer'=>16];
    private const BYTES = ['stem'=>65535, 'material'=>65535, 'answerText'=>65535, 'analysis'=>1048576];

    public function __construct(private AdminAuditService $audit) {}

    public function detail(int $id): array
    {
        AdminDashboardService::authorize();
        $model = Question::find($id);
        if (!$model) throw new \RuntimeException('题目不存在', 404);
        return QuestionResource::make($model);
    }

    public function create(string $pid, array $data): Question
    {
        AdminDashboardService::authorize();
        return $this->write(function () use ($pid, $data) {
            AdminDashboardService::authorize();
            $paper = Paper::query()->where('pid', $pid)->lockForUpdate()->first();
            if (!$paper) throw new \RuntimeException('试卷不存在', 404);
            $fields = $this->validate($data);
            if (Question::query()->where('pid', $pid)->where('no', $data['no'])->exists()) throw new \RuntimeException('题号已存在', 409);
            $question = new Question();
            $question->fill($fields + ['pid'=>$pid, 'no'=>$data['no'], 'year'=>(int)$paper->year, 'label'=>(string)$paper->label, 'revision'=>1]);
            $question->save();
            $paper->question_count = (int) Question::query()->where('pid', $pid)->count();
            $paper->save();
            $this->audit->log(Auth::user(), 'question.create', 'question', (string)$question->id, ['pid'=>$pid, 'no'=>$data['no'], 'revision'=>1]);
            return $question;
        });
    }

    public function update(int $id, array $data): Question
    {
        AdminDashboardService::authorize();
        // The immutable pid is read without taking a child lock before its parent.
        $snapshot = Question::find($id);
        if (!$snapshot) throw new \RuntimeException('题目不存在', 404);
        return $this->write(function () use ($id, $snapshot, $data) {
            AdminDashboardService::authorize();
            $paper = Paper::query()->where('pid', $snapshot->pid)->lockForUpdate()->first();
            if (!$paper) throw new \RuntimeException('试卷不存在', 404);
            $question = Question::query()->where('id', $id)->lockForUpdate()->first();
            if (!$question) throw new \RuntimeException('题目不存在', 404);
            if ((string)$question->pid !== (string)$snapshot->pid || (int)$question->no !== (int)$snapshot->no) throw new \RuntimeException('题目身份已改变，请重新读取', 409);
            if (!isset($data['expectedRevision']) || !is_int($data['expectedRevision']) || $data['expectedRevision'] < 1 || $data['expectedRevision'] >= PHP_INT_MAX) throw new \RuntimeException('请提供有效的题目版本号', 422);
            if ($data['expectedRevision'] !== (int)$question->revision) throw new \RuntimeException('题目已被修改，请重新读取后合并', 409);
            $fields = $this->validate($data, $question);
            $question->fill($fields);
            $question->revision = (int)$question->revision + 1;
            $question->save();
            $this->audit->log(Auth::user(), 'question.update', 'question', (string)$id, ['pid'=>(string)$question->pid, 'no'=>(int)$question->no, 'revision'=>(int)$question->revision]);
            return $question;
        });
    }

    private function write(callable $operation): Question
    {
        // Imports lock the same maintenance rows in alphabetical order. Paper deletion
        // also holds papers for its entire transaction, so a creator cannot leave an orphan.
        return ContentMaintenance::write('papers', fn () => ContentMaintenance::write('questions', $operation));
    }

    private function validate(array $data, ?Question $current = null): array
    {
        $create = $current === null;
        $allowed = [...array_keys(self::FIELDS), ...($create ? ['no'] : ['expectedRevision', 'id', 'pid', 'no'])];
        foreach ($data as $key=>$value) {
            if (!in_array($key, $allowed, true)) throw new \RuntimeException('未知的题目字段：'.$key, 422);
        }
        if ($create && (!isset($data['no']) || !is_int($data['no']) || $data['no'] < 1 || $data['no'] > 65535)) throw new \RuntimeException('题号必须为1到65535的整数', 422);
        if ($current) {
            foreach (['id'=>(int)$current->id, 'pid'=>(string)$current->pid, 'no'=>(int)$current->no] as $key=>$value) {
                if (array_key_exists($key, $data) && $data[$key] !== $value) throw new \RuntimeException('题目身份字段不可改变：'.$key, 422);
            }
        }

        $fields = [];
        foreach (self::FIELDS as $key=>$column) {
            if (!array_key_exists($key, $data)) continue;
            $value = $data[$key];
            if (isset(self::CHARS[$key]) && (!is_string($value) || mb_strlen($value) > self::CHARS[$key])) throw new \RuntimeException('题目文本字段无效：'.$key, 422);
            if (isset(self::BYTES[$key]) && (!is_string($value) || strlen($value) > self::BYTES[$key])) throw new \RuntimeException('题目正文超过容量或格式无效：'.$key, 422);
            if ($key === 'stem' && trim($value) === '') throw new \RuntimeException('题干不能为空', 422);
            if ($key === 'type' && (!is_string($value) || !in_array($value, self::TYPES, true))) throw new \RuntimeException('题型无效', 422);
            if ($key === 'sortOrder' && (!is_int($value) || $value < -2147483648 || $value > 2147483647)) throw new \RuntimeException('排序必须为32位整数', 422);
            if ($key === 'score' && ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value < 0 || $value > 9999.9 || !preg_match('/^[0-9]+(?:\.[0-9])?$/D', (string)$value))) throw new \RuntimeException('分值必须为0到9999.9且最多一位小数', 422);
            if ($key === 'options') {
                if (!is_array($value)) throw new \RuntimeException('选项必须为A-H字母映射', 422);
                foreach ($value as $letter=>$text) {
                    if (!is_string($letter) || !preg_match('/^[A-H]$/D', $letter) || !is_string($text) || trim($text) === '') throw new \RuntimeException('选项必须为非空的A-H字母字符串映射', 422);
                }
            }
            $fields[$column] = $value;
        }

        if ($create) {
            if (!isset($fields['type'], $fields['stem'])) throw new \RuntimeException('请提供题型与题干', 422);
            $fields += ['type_cn'=>'', 'material'=>'', 'options'=>[], 'answer'=>'', 'answer_text'=>'', 'analysis'=>'', 'module'=>'', 'module_name'=>'', 'kaodian'=>'', 'score'=>0, 'sort_order'=>$data['no']];
        }

        // Imported rows can contain incomplete answers. Unchanged semantic fields are
        // preserved for metadata/sort edits; never fabricate their missing answers.
        $semanticChange = $create;
        foreach (['type', 'options', 'answer', 'answer_text', 'analysis'] as $column) {
            if (!array_key_exists($column, $fields)) continue;
            $unchanged = $current && ($column === 'options' ? $fields[$column] == ($current->$column ?? []) : $fields[$column] === (string)($current->$column ?? ''));
            if ($unchanged) {
                // Keep original NULL/text and JSON object/array representations verbatim.
                unset($fields[$column]);
            } else {
                $semanticChange = true;
            }
        }
        if ($semanticChange) {
            $type = $fields['type'] ?? (string)$current->type;
            $options = $fields['options'] ?? ($current->options ?? []);
            $answer = $fields['answer'] ?? (string)$current->answer;
            if (in_array($type, ['single', 'multi'], true)) {
                if (count($options) < 2) throw new \RuntimeException('客观题至少需要两个选项', 422);
                foreach ($options as $letter=>$text) {
                    if (!is_string($letter) || !preg_match('/^[A-H]$/D', $letter) || !is_string($text) || trim($text) === '') throw new \RuntimeException('客观题选项格式无效', 422);
                }
                if (!preg_match('/^[A-H]+$/D', $answer) || count(array_unique(str_split($answer))) !== strlen($answer) || ($type === 'single' && strlen($answer) !== 1) || ($type === 'multi' && strlen($answer) < 2)) throw new \RuntimeException('正确答案字母与题型不一致', 422);
                foreach (str_split($answer) as $letter) if (!array_key_exists($letter, $options)) throw new \RuntimeException('正确答案必须对应已有选项', 422);
            } else {
                if (!in_array($type, self::TYPES, true) || $options !== []) throw new \RuntimeException('主观题不能包含选项', 422);
                $answerText = $fields['answer_text'] ?? (string)($current?->answer_text ?? '');
                $analysis = $fields['analysis'] ?? (string)($current?->analysis ?? '');
                if (trim($answerText) === '' && trim($analysis) === '') throw new \RuntimeException('主观题需要答案要点或解析', 422);
            }
            if ($create || array_key_exists('options', $fields) || array_key_exists('type', $fields)) $fields['n_opt'] = count($options);
        }
        return $fields;
    }
}
