<?php

declare(strict_types=1);

namespace App\Resource;

use App\Model\Question;

/**
 * 题目 DTO。`reveal=false` 时抹掉 answer/analysis，供在线作答使用。
 */
class QuestionResource
{
    public static function make(Question $question, bool $reveal = true): array
    {
        return [
            'id' => (int) $question->id,
            'pid' => (string) $question->pid,
            'year' => (int) $question->year,
            'label' => (string) $question->label,
            'no' => (int) $question->no,
            'type' => (string) $question->type,
            'typeCn' => (string) $question->type_cn,
            'score' => (float) $question->score,
            'module' => (string) $question->module,
            'moduleName' => (string) $question->module_name,
            'kaodian' => (string) $question->kaodian,
            'stem' => (string) $question->stem,
            'material' => (string) $question->material,
            'options' => (object) ($question->options ?? []),
            'answer' => $reveal ? (string) $question->answer : null,
            'analysis' => $reveal ? (string) $question->analysis : null,
            'answerText' => $reveal ? (string) $question->answer_text : null,
            'accuracy' => (string) $question->accuracy,
            'objective' => $question->isObjective(),
        ];
    }

    /** @param iterable<Question> $questions */
    public static function collection(iterable $questions, bool $reveal = true): array
    {
        $out = [];
        foreach ($questions as $question) {
            $out[] = self::make($question, $reveal);
        }
        return $out;
    }
}
