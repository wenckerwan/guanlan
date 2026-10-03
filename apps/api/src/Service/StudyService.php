<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\Attempt;
use App\Model\Favorite;
use App\Model\MistakeStudent;
use App\Model\Note;
use App\Model\Question;
use App\Model\StudyProgress;
use App\Model\User;
use Hyperf\Database\Model\Builder;

/**
 * 用户态：收藏、笔记、做题记录与进度。
 */
class StudyService
{
    public const TARGET_TYPES = [
        'question', 'paper', 'analysis', 'hotspot', 'prediction', 'mistake', 'mistake_item', 'handbook', 'mock',
        // 马原知识宇宙
        'mayuan_concept', 'mayuan_relation', 'mayuan_comparison', 'mayuan_experiment',
        // 近现代史时间实验室
        'history_event', 'history_comparison',
    ];

    public function __construct(
        private MistakeAccountService $mistakeAccounts,
        private MistakeService $mistakes
    ) {
    }

    /** @return array<int, Favorite> */
    public function favorites(User $user, string $targetType = ''): array
    {
        return Favorite::query()
            ->where('user_id', $user->id)
            ->when($targetType !== '', fn (Builder $q) => $q->where('target_type', $targetType))
            ->orderByDesc('id')
            ->get()
            ->all();
    }

    public function toggleFavorite(User $user, string $targetType, string $targetId, string $title = '', string $url = ''): array
    {
        $existing = Favorite::query()
            ->where('user_id', $user->id)
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->first();

        if ($existing) {
            $existing->delete();
            return ['favorited' => false];
        }

        Favorite::create([
            'user_id' => $user->id,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'title' => mb_substr($title, 0, 191),
            'url' => mb_substr($url, 0, 512),
        ]);

        return ['favorited' => true];
    }

    public function removeFavorite(User $user, int $id): bool
    {
        return (bool) Favorite::query()->where('user_id', $user->id)->where('id', $id)->delete();
    }

    /**
     * 幂等设置收藏目标状态：重试安全（重复调用不意外取消/重复）。
     */
    public function setFavorite(User $user, string $targetType, string $targetId, bool $favorited, string $title = '', string $url = ''): array
    {
        $existing = Favorite::query()
            ->where('user_id', $user->id)
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->first();

        if (! $favorited) {
            if ($existing) {
                $existing->delete();
            }
            return ['favorited' => false];
        }

        if ($existing) {
            if ($title !== '') {
                $existing->title = mb_substr($title, 0, 191);
            }
            if ($url !== '') {
                $existing->url = mb_substr($url, 0, 512);
            }
            $existing->save();
            return ['favorited' => true, 'id' => (int) $existing->id];
        }

        $favorite = Favorite::create([
            'user_id' => $user->id,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'title' => mb_substr($title, 0, 191),
            'url' => mb_substr($url, 0, 512),
        ]);

        return ['favorited' => true, 'id' => (int) $favorite->id];
    }

    /** @return array<int, Note> */
    public function notes(User $user, string $targetType = '', string $targetId = ''): array
    {
        return Note::query()
            ->where('user_id', $user->id)
            ->when($targetType !== '', fn (Builder $q) => $q->where('target_type', $targetType))
            ->when($targetId !== '', fn (Builder $q) => $q->where('target_id', $targetId))
            ->orderByDesc('id')
            ->get()
            ->all();
    }

    public function addNote(User $user, string $targetType, string $targetId, string $content, string $title = ''): Note
    {
        return Note::create([
            'user_id' => $user->id,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'title' => mb_substr($title, 0, 191),
            'content' => $content,
        ]);
    }

    public function removeNote(User $user, int $id): bool
    {
        return (bool) Note::query()->where('user_id', $user->id)->where('id', $id)->delete();
    }

    /**
     * 编辑笔记内容（仅限本人，且只更新 content，不变更归属）。
     */
    public function updateNote(User $user, int $id, string $content): ?Note
    {
        $note = Note::query()->where('user_id', $user->id)->where('id', $id)->first();
        if (! $note) {
            return null;
        }
        $note->content = $content;
        $note->save();
        return $note;
    }

    /**
     * 记录一次作答：写 attempts，并 upsert 对应进度。
     */
    public function recordAttempt(
        User $user,
        string $source,
        string $sourceRef,
        string $questionRef,
        string $module,
        string $chosen,
        string $correct
    ): Attempt {
        $isRight = $chosen !== '' && $chosen === $correct;

        $attempt = Attempt::create([
            'user_id' => $user->id,
            'source' => $source,
            'source_ref' => mb_substr($sourceRef, 0, 64),
            'question_ref' => mb_substr($questionRef, 0, 64),
            'module' => mb_substr($module, 0, 64),
            'chosen' => mb_substr($chosen, 0, 16),
            'correct' => mb_substr($correct, 0, 16),
            'is_right' => $isRight,
        ]);

        $progress = StudyProgress::query()
            ->where('user_id', $user->id)
            ->where('scope', $source)
            ->where('ref', mb_substr($sourceRef, 0, 191))
            ->first();

        if (! $progress) {
            $progress = new StudyProgress([
                'user_id' => $user->id,
                'scope' => $source,
                'ref' => mb_substr($sourceRef, 0, 191),
                'label' => mb_substr($module, 0, 191),
            ]);
        }

        $progress->correct_count = (int) $progress->correct_count + ($isRight ? 1 : 0);
        $progress->wrong_count = (int) $progress->wrong_count + ($isRight ? 0 : 1);
        $progress->last_seen_at = date('Y-m-d H:i:s');
        $total = (int) $progress->correct_count + (int) $progress->wrong_count;
        $progress->progress = $total > 0
            ? (int) min(100, round((int) $progress->correct_count / $total * 100))
            : 0;
        $progress->status = $total > 0 ? 'practising' : 'reading';
        $progress->save();

        return $attempt;
    }

    /**
     * 真题整卷交卷：服务端判分、逐题落 attempts，错题（含未作答的客观题）
     * 自动归集进本人错题本。
     *
     * @param array<int, string> $answers questionId => 所选字母
     * @return array<string, mixed>
     */
    public function recordPaperSession(User $user, string $pid, array $answers): array
    {
        $questions = Question::query()->where('pid', $pid)->get();
        if ($questions->isEmpty()) {
            return ['error' => '试卷不存在'];
        }

        $student = MistakeStudent::query()->where('owner_user_id', (int) $user->id)->first();
        if (! $student) {
            $student = $this->mistakeAccounts->provision($user)['student'];
        }

        $right = 0;
        $wrong = 0;
        $blank = 0;
        $score = 0;
        $collected = 0;

        foreach ($questions as $question) {
            $questionId = (int) $question->id;
            $chosen = self::normalizeLetters((string) ($answers[$questionId] ?? ''));
            $correct = self::normalizeLetters((string) $question->answer);

            if ($chosen !== '') {
                $this->recordAttempt(
                    $user,
                    'paper',
                    $pid,
                    (string) $questionId,
                    (string) $question->module_name,
                    $chosen,
                    $correct
                );
            }

            if ($correct === '' || (array) $question->options === []) {
                continue;
            }

            if ($chosen === '') {
                $blank++;
            } elseif ($chosen === $correct) {
                $right++;
                $score += (int) $question->score;
            } else {
                $wrong++;
            }

            if ($chosen === '' || $chosen !== $correct) {
                if ($this->mistakes->collectWrongChoice($student, $question, $chosen)) {
                    $collected++;
                }
            }
        }

        return [
            'attempted' => count(array_filter($answers, fn ($v) => trim((string) $v) !== '')),
            'right' => $right,
            'wrong' => $wrong,
            'blank' => $blank,
            'score' => $score,
            'totalScore' => (int) $questions->sum('score'),
            'collected' => $collected,
        ];
    }

    /** 字母答案归一化：去非字母、大写、去重排序，保证多选可比对。 */
    public static function normalizeLetters(string $value): string
    {
        $letters = array_unique(str_split(strtoupper(preg_replace('/[^A-Da-d]/', '', $value) ?? '')));
        sort($letters);
        return implode('', $letters);
    }

    /** @return array<string, mixed> */
    public function stats(User $user): array
    {
        $total = Attempt::query()->where('user_id', $user->id)->count();
        $right = Attempt::query()->where('user_id', $user->id)->where('is_right', true)->count();

        $byModule = Attempt::query()
            ->selectRaw('module, count(*) as total, sum(is_right) as right_count')
            ->where('user_id', $user->id)
            ->groupBy('module')
            ->get()
            ->map(fn ($row) => [
                'module' => (string) $row->module,
                'total' => (int) $row->total,
                'right' => (int) $row->right_count,
                'accuracy' => (int) $row->total > 0
                    ? (int) round((int) $row->right_count / (int) $row->total * 100)
                    : 0,
            ])
            ->all();

        return [
            'total' => (int) $total,
            'right' => (int) $right,
            'wrong' => (int) $total - (int) $right,
            'accuracy' => (int) $total > 0 ? (int) round($right / $total * 100) : 0,
            'byModule' => array_values($byModule),
            'favorites' => Favorite::query()->where('user_id', $user->id)->count(),
            'notes' => Note::query()->where('user_id', $user->id)->count(),
        ];
    }

    /** @return array<int, StudyProgress> */
    public function progress(User $user): array
    {
        return StudyProgress::query()
            ->where('user_id', $user->id)
            ->orderByDesc('last_seen_at')
            ->get()
            ->all();
    }

    public function upsertProgress(User $user, string $scope, string $ref, string $label = '', string $status = 'reading', int $progress = 0): StudyProgress
    {
        $record = StudyProgress::query()
            ->where('user_id', $user->id)
            ->where('scope', $scope)
            ->where('ref', $ref)
            ->first() ?? new StudyProgress([
                'user_id' => $user->id,
                'scope' => $scope,
                'ref' => $ref,
            ]);

        $record->label = $label !== '' ? mb_substr($label, 0, 191) : $record->label;
        $record->status = $status;
        $record->progress = max(0, min(100, $progress));
        $record->last_seen_at = date('Y-m-d H:i:s');
        $record->save();

        return $record;
    }
}
