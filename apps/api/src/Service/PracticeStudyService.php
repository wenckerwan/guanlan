<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Favorite;
use App\Model\PracticeAttempt;
use App\Model\PracticeBank;
use App\Model\PracticeQuestion;
use App\Model\User;
use Hyperf\DbConnection\Db;
use RuntimeException;

class PracticeStudyService
{
    public function bank(string $slug, ?User $user): array
    {
        $bank = $this->publishedBank($slug);
        $questions = PracticeQuestion::query()->where('bank_id', $bank->id)->where('is_active', true)
            ->orderBy('sort_order')->get();
        $count = $questions->count();
        if (! $user) {
            $questions = $questions->take((int) $bank->preview_count);
        }
        return [
            'bankId' => $bank->slug, 'title' => $bank->title, 'category' => $bank->category,
            'contentVersion' => $bank->content_version, 'questionCount' => $count,
            'previewCount' => (int) $bank->preview_count,
            'questions' => $questions->map(static fn (PracticeQuestion $q): array => [
                'questionRef' => $q->question_ref, 'questionVersion' => $q->question_version,
                'type' => $q->type, 'module' => $q->module, 'stem' => $q->stem,
                'options' => $q->options, 'answer' => $q->answer, 'analysis' => $q->analysis,
                'event' => $q->event, 'knowledge' => $q->knowledge, 'sources' => $q->sources,
                'sortOrder' => (int) $q->sort_order,
            ])->all(),
        ];
    }

    public function submit(User $user, array $input): array
    {
        $slug = trim((string) ($input['bankId'] ?? ''));
        $ref = trim((string) ($input['questionRef'] ?? ''));
        $version = trim((string) ($input['questionVersion'] ?? ''));
        $eventId = trim((string) ($input['eventId'] ?? ''));
        $chosen = $input['chosen'] ?? null;
        if (! preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-8][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', $eventId)
            || ! is_array($chosen) || count($chosen) < 1 || count($chosen) > 4
            || array_values($chosen) !== $chosen) {
            throw new RuntimeException('事件标识或答案无效', 422);
        }
        foreach ($chosen as $letter) {
            if (! is_string($letter) || ! preg_match('/^[A-D]$/', $letter)) {
                throw new RuntimeException('答案仅支持 A—D', 422);
            }
        }
        if (count(array_unique($chosen)) !== count($chosen)) {
            throw new RuntimeException('答案不得重复', 422);
        }
        sort($chosen, SORT_STRING);
        $chosenText = implode('', $chosen);
        if ($slug === '' || $ref === '' || ! preg_match('/^[a-f0-9]{64}$/', $version)) {
            throw new RuntimeException('题目或版本参数无效', 422);
        }
        $fingerprint = hash('sha256', json_encode([$slug, $ref, $version, $chosenText], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $attempt = Db::transaction(function () use ($user, $slug, $ref, $version, $eventId, $chosenText, $fingerprint): array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existing = PracticeAttempt::query()->where('user_id', $user->id)
                ->where('client_event_id', $eventId)->first();
            if ($existing) {
                if (! hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
                    throw new RuntimeException('同一事件的作答内容已变更', 409);
                }
                return [$existing, true];
            }
            $bank = $this->publishedBank($slug, true);
            $question = PracticeQuestion::query()->where('bank_id', $bank->id)
                ->where('question_ref', $ref)->where('is_active', true)->first();
            if (! $question) {
                throw new RuntimeException('题目不存在', 404);
            }
            if ($question->question_version !== $version) {
                throw new RuntimeException('题目版本已更新，请刷新后重试', 409);
            }
            if ($question->type === 'single' && strlen($chosenText) !== 1) {
                throw new RuntimeException('单选题只能选择一项', 422);
            }
            $correct = implode('', array_unique(str_split((string) $question->answer)));
            $correctLetters = str_split($correct);
            sort($correctLetters, SORT_STRING);
            $correct = implode('', $correctLetters);
            $record = PracticeAttempt::create([
                'user_id' => $user->id, 'bank_id' => $bank->id,
                'question_id' => $question->id, 'client_event_id' => $eventId,
                'chosen' => $chosenText, 'correct_snapshot' => $correct,
                'is_right' => $chosenText === $correct,
                'request_fingerprint' => $fingerprint, 'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            return [$record, false];
        }, 3);
        [$record, $duplicate] = $attempt;
        $question = PracticeQuestion::find($record->question_id);
        $bank = PracticeBank::find($record->bank_id);
        try {
            $state = $this->state($user, (string) $bank->slug);
        } catch (RuntimeException $error) {
            if ($error->getCode() !== 404) {
                throw $error;
            }
            // A saved event remains retriable even if its bank is later unpublished.
            $state = ['questions' => [], 'summary' => null];
        }
        return [
            'attemptId' => (int) $record->id, 'isRight' => (bool) $record->is_right,
            'correct' => str_split((string) $record->correct_snapshot), 'duplicate' => $duplicate,
            'questionState' => $state['questions'][$question->question_ref] ?? null,
            'bankSummary' => $state['summary'],
        ];
    }

    public function state(User $user, string $slug): array
    {
        $bank = $this->publishedBank($slug);
        $questions = PracticeQuestion::query()->where('bank_id', $bank->id)->where('is_active', true)
            ->orderBy('sort_order')->get();
        $ids = $questions->pluck('id')->all();
        $attempts = PracticeAttempt::query()->where('user_id', $user->id)->whereIn('question_id', $ids)
            ->orderBy('id')->get();
        $map = [];
        foreach ($questions as $question) {
            $map[$question->question_ref] = [
                'questionVersion' => $question->question_version, 'attemptCount' => 0,
                'firstIsRight' => null, 'lastIsRight' => null, 'lastChosen' => [],
                'lastCorrect' => [], 'lastAttemptAt' => null, 'favorited' => false,
            ];
        }
        $refs = $questions->keyBy('id');
        foreach ($attempts as $attempt) {
            $ref = $refs[$attempt->question_id]->question_ref;
            $entry =& $map[$ref];
            if ($entry['attemptCount'] === 0) {
                $entry['firstIsRight'] = (bool) $attempt->is_right;
            }
            ++$entry['attemptCount'];
            $entry['lastIsRight'] = (bool) $attempt->is_right;
            $entry['lastChosen'] = str_split((string) $attempt->chosen);
            $entry['lastCorrect'] = str_split((string) $attempt->correct_snapshot);
            $entry['lastAttemptAt'] = $attempt->created_at;
            unset($entry);
        }
        $targets = Favorite::query()->where('user_id', $user->id)->where('target_type', 'practice_question')
            ->pluck('target_id')->all();
        foreach ($targets as $target) {
            $prefix = $slug . ':';
            if (str_starts_with((string) $target, $prefix) && isset($map[substr((string) $target, strlen($prefix))])) {
                $map[substr((string) $target, strlen($prefix))]['favorited'] = true;
            }
        }
        return ['bankId' => $slug, 'contentVersion' => $bank->content_version, 'questions' => $map,
            'summary' => self::metrics($map, count($map), $slug)];
    }

    public function summary(?User $user): array
    {
        $cumulative = $this->cumulative($user);
        $bank = PracticeBank::query()->where('slug', 'current-affairs-2026')->where('status', 'published')->first();
        $current = $bank && $user ? $this->state($user, (string) $bank->slug)['summary']
            : self::metrics([], $bank ? PracticeQuestion::query()->where('bank_id', $bank->id)->where('is_active', true)->count() : 0, 'current-affairs-2026');
        return $cumulative + ['currentAffairs' => $current];
    }

    public function cumulative(?User $user = null): array
    {
        $query = Db::table('practice_attempts as a')
            ->join('practice_questions as q', 'q.id', '=', 'a.question_id')
            ->select('q.module')
            ->selectRaw('COUNT(*) AS total, SUM(a.is_right) AS rights');
        if ($user) {
            $query->where('a.user_id', $user->id);
        }
        $rows = $query->groupBy('q.module')->get();
        $byModule = [];
        $total = 0;
        $right = 0;
        foreach ($rows as $row) {
            $moduleTotal = (int) $row->total;
            $moduleRight = (int) $row->rights;
            $byModule[] = ['module' => (string) $row->module, 'total' => $moduleTotal, 'right' => $moduleRight];
            $total += $moduleTotal;
            $right += $moduleRight;
        }
        return ['total' => $total, 'right' => $right, 'wrong' => $total - $right,
            'byModule' => $byModule,
            'bySource' => [['source' => 'practice', 'total' => $total, 'right' => $right]]];
    }

    public function history(User $user, array $filters = []): array
    {
        $page = max(1, min(100000, (int) ($filters['page'] ?? 1)));
        $perPage = max(1, min(100, (int) ($filters['perPage'] ?? 20)));
        $query = PracticeAttempt::query()->where('user_id', $user->id);
        if (! empty($filters['bankId'])) {
            $bank = PracticeBank::query()->where('slug', $filters['bankId'])->first();
            if (! $bank) {
                return ['items' => [], 'total' => 0, 'page' => 1, 'perPage' => $perPage];
            }
            $query->where('bank_id', $bank->id);
        }
        $total = $query->count();
        $page = min($page, max(1, (int) ceil($total / $perPage)));
        $rows = $query->orderByDesc('id')->offset(($page - 1) * $perPage)->limit($perPage)->get();
        $questions = PracticeQuestion::query()->whereIn('id', $rows->pluck('question_id')->all())->get()->keyBy('id');
        return ['items' => $rows->map(static function (PracticeAttempt $row) use ($questions): array {
            $question = $questions[$row->question_id];
            return ['attemptId' => (int) $row->id, 'questionRef' => $question->question_ref,
                'questionVersion' => $question->question_version, 'chosen' => str_split((string) $row->chosen),
                'correct' => str_split((string) $row->correct_snapshot), 'isRight' => (bool) $row->is_right,
                'createdAt' => $row->created_at];
        })->all(), 'total' => $total, 'page' => $page, 'perPage' => $perPage];
    }

    private function publishedBank(string $slug, bool $lock = false): PracticeBank
    {
        $query = PracticeBank::query()->where('slug', $slug)->where('status', 'published');
        $bank = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $bank) {
            throw new RuntimeException('题库不存在或尚未发布', 404);
        }
        return $bank;
    }

    private static function metrics(array $states, int $questionCount, string $bankId): array
    {
        $done = array_filter($states, static fn ($entry) => $entry['attemptCount'] > 0);
        $unique = count($done);
        $submissions = array_sum(array_column($states, 'attemptCount'));
        $first = count(array_filter($done, static fn ($entry) => $entry['firstIsRight'] === true));
        $latest = count(array_filter($done, static fn ($entry) => $entry['lastIsRight'] === true));
        return [
            'bankId' => $bankId, 'questionCount' => $questionCount,
            'submissionCount' => $submissions, 'attempts' => $submissions,
            'uniqueQuestionCount' => $unique, 'distinctQuestions' => $unique,
            'firstCorrectCount' => $first, 'firstRight' => $first,
            'latestCorrectCount' => $latest, 'latestRight' => $latest,
            'firstAccuracy' => $unique ? round($first / $unique * 100, 2) : 0,
            'latestAccuracy' => $unique ? round($latest / $unique * 100, 2) : 0,
            'repeatCount' => $submissions - $unique,
            'completion' => $questionCount ? round($unique / $questionCount * 100, 2) : 0,
        ];
    }
}
