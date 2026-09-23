<?php

declare(strict_types=1);

namespace App\Resource;

use App\Model\Mock;
use App\Model\MockQuestion;

class MockResource
{
    public static function make(Mock $mock): array
    {
        return [
            'slug' => (string) $mock->slug,
            'title' => (string) $mock->title,
            'summary' => (string) $mock->summary,
            'totalScore' => (int) $mock->total_score,
            'durationMinutes' => (int) $mock->duration_minutes,
            'questionCount' => (int) $mock->question_count,
            'answeredCount' => (int) $mock->answered_count,
        ];
    }

    /** @param iterable<Mock> $mocks */
    public static function collection(iterable $mocks): array
    {
        $out = [];
        foreach ($mocks as $mock) {
            $out[] = self::make($mock);
        }
        return $out;
    }

    public static function question(MockQuestion $question, bool $reveal = true): array
    {
        return [
            'no' => (int) $question->no,
            'type' => (string) $question->type,
            'typeCn' => (string) $question->type_cn,
            'score' => (float) $question->score,
            'module' => (string) $question->module,
            'moduleName' => (string) $question->module_name,
            'kaodian' => (string) $question->kaodian,
            'stem' => (string) $question->stem,
            'options' => (object) ($question->options ?? []),
            'answer' => $reveal ? (string) $question->answer : null,
            'analysis' => $reveal ? (string) $question->analysis : null,
        ];
    }

    /** @param iterable<MockQuestion> $questions */
    public static function questions(iterable $questions, bool $reveal = true): array
    {
        $out = [];
        foreach ($questions as $question) {
            $out[] = self::question($question, $reveal);
        }
        return $out;
    }
}
