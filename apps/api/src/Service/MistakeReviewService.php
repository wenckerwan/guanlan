<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\MistakeItem;
use App\Model\MistakeReview;
use App\Model\User;
use Hyperf\DbConnection\Db;

class MistakeReviewService
{
    /**
     * 复习间隔算法（固定间隔版本）
     */
    private const INTERVALS = [
        'wrong' => 1,           // 答错：1 天后复习
        'first_correct' => 3,   // 第一次答对：3 天后复习
        'second_correct' => 7,  // 连续第二次答对：7 天后复习
        'third_correct' => 14,  // 连续第三次答对：14 天后复习，并标记已掌握
    ];

    /**
     * 获取复习概览
     */
    public function getSummary(User $user, string $studentCode): array
    {
        $studentId = Db::table('mistake_students')
            ->where('code', $studentCode)
            ->value('id');

        if (! $studentId) {
            return [
                'total' => 0,
                'new' => 0,
                'reviewing' => 0,
                'mastered' => 0,
                'snoozed' => 0,
                'dueToday' => 0,
                'overdue' => 0,
                'accuracy' => 0,
                'reviewCount' => 0,
            ];
        }

        $itemIds = Db::table('mistake_items')
            ->where('student_id', $studentId)
            ->pluck('id');

        $total = $itemIds->count();

        // 确保所有错题都有复习记录
        $this->ensureReviews($user, $itemIds->toArray());

        $reviews = MistakeReview::query()
            ->where('user_id', $user->id)
            ->whereIn('mistake_item_id', $itemIds)
            ->get();

        $statusCounts = $reviews->countBy('status');
        $dueToday = $reviews->filter->isDueToday()->count();
        $overdue = $reviews->filter->isOverdue()->count();

        $totalReviews = $reviews->sum('review_count');
        $totalCorrect = $reviews->sum('correct_count');

        return [
            'total' => $total,
            'new' => $statusCounts['new'] ?? 0,
            'reviewing' => $statusCounts['reviewing'] ?? 0,
            'mastered' => $statusCounts['mastered'] ?? 0,
            'snoozed' => $statusCounts['snoozed'] ?? 0,
            'dueToday' => $dueToday,
            'overdue' => $overdue,
            'accuracy' => $totalReviews > 0 ? round($totalCorrect / $totalReviews, 4) : 0,
            'reviewCount' => $totalReviews,
        ];
    }

    /**
     * 确保所有错题都有复习记录
     */
    private function ensureReviews(User $user, array $itemIds): void
    {
        $existing = MistakeReview::query()
            ->where('user_id', $user->id)
            ->whereIn('mistake_item_id', $itemIds)
            ->pluck('mistake_item_id')
            ->toArray();

        $missing = array_diff($itemIds, $existing);

        if (empty($missing)) {
            return;
        }

        $items = MistakeItem::query()
            ->whereIn('id', $missing)
            ->get();

        $now = date('Y-m-d H:i:s');
        $rows = [];

        foreach ($items as $item) {
            $rows[] = [
                'user_id' => $user->id,
                'mistake_item_id' => $item->id,
                'item_key' => $item->item_key,
                'status' => 'new',
                'review_count' => 0,
                'correct_count' => 0,
                'wrong_count' => 0,
                'consecutive_correct' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // 唯一索引 (user_id, mistake_item_id) 兜底并发首访，冲突行直接跳过
        Db::table('mistake_reviews')->insertOrIgnore($rows);
    }

    /**
     * 提交重练结果
     */
    public function submitReview(User $user, int $itemId, string $chosen): array
    {
        $item = MistakeItem::query()->findOrFail($itemId);

        // 获取或创建复习记录
        $review = MistakeReview::query()
            ->where('user_id', $user->id)
            ->where('mistake_item_id', $itemId)
            ->first();

        if (! $review) {
            $review = MistakeReview::create([
                'user_id' => $user->id,
                'mistake_item_id' => $itemId,
                'item_key' => $item->item_key,
                'status' => 'new',
            ]);
        }

        // 标准化答案
        $chosen = $this->normalizeAnswer($chosen);
        $correctAnswer = $this->normalizeAnswer($item->correct_answer);

        $isCorrect = $chosen === $correctAnswer;
        $result = $isCorrect ? 'correct' : 'wrong';

        // 更新复习记录
        $review->review_count++;
        $review->last_chosen = $chosen;
        $review->last_result = $result;
        $review->last_reviewed_at = now();

        if ($isCorrect) {
            $review->correct_count++;
        } else {
            $review->wrong_count++;
        }

        // 计算下次复习时间和状态
        $this->updateNextReview($review, $isCorrect);

        $review->save();

        return [
            'isCorrect' => $isCorrect,
            'correctAnswer' => $item->correct_answer,
            'chosen' => $chosen,
            'review' => $review,
        ];
    }

    /**
     * 更新下次复习时间和状态
     */
    private function updateNextReview(MistakeReview $review, bool $isCorrect): void
    {
        if (! $isCorrect) {
            // 答错：连胜清零，回到复习中，1 天后复习
            $review->consecutive_correct = 0;
            $review->status = 'reviewing';
            $review->next_review_at = now()->addDays(self::INTERVALS['wrong']);
            return;
        }

        // 答对：连胜 +1，按连胜次数决定间隔
        $streak = (int) $review->consecutive_correct + 1;
        $review->consecutive_correct = $streak;

        if ($streak === 1) {
            // 第一次答对
            $review->status = 'reviewing';
            $review->next_review_at = now()->addDays(self::INTERVALS['first_correct']);
        } elseif ($streak === 2) {
            // 连续第二次答对
            $review->status = 'reviewing';
            $review->next_review_at = now()->addDays(self::INTERVALS['second_correct']);
        } else {
            // 连续第三次及以上答对：标记已掌握
            $review->status = 'mastered';
            $review->next_review_at = now()->addDays(self::INTERVALS['third_correct']);
        }
    }

    /**
     * 标准化答案（多选题按字母顺序排序）
     */
    private function normalizeAnswer(string $answer): string
    {
        $answer = strtoupper(trim($answer));
        $chars = str_split(str_replace([' ', ',', '、', '，'], '', $answer));
        sort($chars);
        return implode('', $chars);
    }

    /**
     * 修改复习状态（无复习记录时自动创建，而不是 500）
     */
    public function updateStatus(User $user, int $itemId, string $status, ?string $nextReviewAt = null): MistakeReview
    {
        $review = MistakeReview::query()
            ->where('user_id', $user->id)
            ->where('mistake_item_id', $itemId)
            ->first();

        if (! $review) {
            $item = MistakeItem::query()->findOrFail($itemId);
            $review = MistakeReview::create([
                'user_id' => $user->id,
                'mistake_item_id' => $itemId,
                'item_key' => $item->item_key,
                'status' => $status,
            ]);
        }

        $review->status = $status;

        if ($nextReviewAt !== null && trim($nextReviewAt) !== '') {
            $timestamp = strtotime($nextReviewAt);
            if ($timestamp !== false) {
                $review->next_review_at = date('Y-m-d H:i:s', $timestamp);
            }
        }

        $review->save();

        return $review;
    }

    /**
     * 保存个人行动建议（写入用户自己的复习记录，不污染全局错题数据）
     */
    public function savePersonalAction(User $user, MistakeItem $item, string $action): string
    {
        $review = MistakeReview::query()
            ->where('user_id', $user->id)
            ->where('mistake_item_id', $item->id)
            ->first();

        if (! $review) {
            $review = MistakeReview::create([
                'user_id' => $user->id,
                'mistake_item_id' => (int) $item->id,
                'item_key' => $item->item_key,
                'status' => 'new',
            ]);
        }

        $review->personal_action = $action;
        $review->save();

        return $action;
    }
}
