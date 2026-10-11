<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\PracticeBank;
use App\Model\PracticeQuestion;
use Hyperf\DbConnection\Db;
use RuntimeException;

class PracticeBankImporter
{
    public static function readPackage(string $path): array
    {
        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload) || ($payload['bankId'] ?? '') !== 'current-affairs-2026'
            || ($payload['questionCount'] ?? null) !== 101 || count($payload['questions'] ?? []) !== 101) {
            throw new RuntimeException('题库数据包数量或标识无效');
        }
        $refs = [];
        foreach ($payload['questions'] as $question) {
            $ref = $question['questionRef'] ?? '';
            if (isset($refs[$ref]) || ! preg_match('/^SZ\d{2}-\d+$/', $ref)
                || self::questionVersion($question) !== ($question['questionVersion'] ?? null)) {
                throw new RuntimeException('题库题号或判题版本无效：' . $ref);
            }
            $refs[$ref] = true;
        }
        return $payload;
    }

    /** Python json.dumps(grading, ensure_ascii=False, sort_keys=True, separators=(',', ':')) */
    public static function questionVersion(array $question): string
    {
        $options = $question['options'] ?? [];
        if (array_keys($options) !== ['A', 'B', 'C', 'D']) {
            throw new RuntimeException('选项必须完整且有序');
        }
        $answer = (string) ($question['answer'] ?? '');
        if (! preg_match('/^[A-D]{1,4}$/', $answer) || count(array_unique(str_split($answer))) !== strlen($answer)
            || ! in_array($question['type'] ?? '', ['single', 'multi'], true)) {
            throw new RuntimeException('标准答案或题型无效');
        }
        $grading = [
            'answer' => $answer,
            'options' => $options,
            'stem' => $question['stem'],
            'type' => $question['type'],
        ];
        return hash('sha256', json_encode($grading, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function import(array $payload, bool $publish = false): array
    {
        if (($payload['bankId'] ?? '') !== 'current-affairs-2026' || count($payload['questions'] ?? []) !== 101) {
            throw new RuntimeException('拒绝导入无效题库');
        }
        foreach ($payload['questions'] as $question) {
            if (self::questionVersion($question) !== ($question['questionVersion'] ?? '')) {
                throw new RuntimeException('题目版本哈希不匹配');
            }
        }
        return Db::transaction(function () use ($payload, $publish): array {
            $bank = PracticeBank::query()->where('slug', $payload['bankId'])->lockForUpdate()->first();
            if (! $bank) {
                $bank = PracticeBank::create([
                    'slug' => $payload['bankId'],
                    'title' => $payload['title'],
                    'category' => $payload['category'],
                    'schema_version' => $payload['schemaVersion'],
                    'content_version' => $payload['contentVersion'],
                    'source_sha256' => $payload['sourceSha256'],
                    'status' => 'draft',
                    'preview_count' => 6,
                ]);
            }
            $activeIds = [];
            foreach ($payload['questions'] as $question) {
                $record = PracticeQuestion::query()->where('bank_id', $bank->id)
                    ->where('question_ref', $question['questionRef'])
                    ->where('question_version', $question['questionVersion'])->first();
                $attributes = [
                    'type' => $question['type'], 'module' => $question['module'],
                    'stem' => $question['stem'], 'options' => $question['options'],
                    'answer' => $question['answer'], 'analysis' => $question['analysis'],
                    'event' => $question['event'], 'knowledge' => $question['knowledge'],
                    'sources' => $question['sources'], 'sort_order' => $question['sortOrder'],
                    'is_active' => true,
                ];
                if ($record) {
                    $record->fill($attributes)->save();
                } else {
                    $record = PracticeQuestion::create(array_merge($attributes, [
                        'bank_id' => $bank->id,
                        'question_ref' => $question['questionRef'],
                        'question_version' => $question['questionVersion'],
                    ]));
                }
                $activeIds[] = $record->id;
            }
            PracticeQuestion::query()->where('bank_id', $bank->id)->whereNotIn('id', $activeIds)
                ->update(['is_active' => false]);
            $bank->fill([
                'title' => $payload['title'], 'category' => $payload['category'],
                'schema_version' => $payload['schemaVersion'],
                'content_version' => $payload['contentVersion'],
                'source_sha256' => $payload['sourceSha256'],
            ]);
            if ($publish) {
                $bank->status = 'published';
                $bank->published_at ??= date('Y-m-d H:i:s');
            } else {
                $bank->status = 'draft';
            }
            $bank->save();
            return ['bankId' => $bank->slug, 'status' => $bank->status, 'questionCount' => count($activeIds), 'contentVersion' => $bank->content_version];
        }, 3);
    }
}
