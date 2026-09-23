<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\Attempt;
use App\Model\Favorite;
use App\Model\Note;
use App\Model\StudyProgress;
use App\Model\User;
use Hyperf\Database\Model\Builder;

/**
 * 用户态：收藏、笔记、做题记录与进度。
 */
class StudyService
{
    public const TARGET_TYPES = ['question', 'paper', 'analysis', 'hotspot', 'prediction', 'mistake', 'handbook', 'mock'];

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
