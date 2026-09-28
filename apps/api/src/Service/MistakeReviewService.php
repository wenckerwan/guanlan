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
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        Db::table('mistake_reviews')->insert($rows);
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
            // 答错：回到复习中，1 天后复习
            $review->status = 'reviewing';
            $review->next_review_at = now()->addDays(self::INTERVALS['wrong']);
            return;
        }

        // 答对：根据连续答对次数决定间隔
        $consecutiveCorrect = $this->getConsecutiveCorrect($review);

        if ($consecutiveCorrect === 0) {
            // 第一次答对
            $review->status = 'reviewing';
            $review->next_review_at = now()->addDays(self::INTERVALS['first_correct']);
        } elseif ($consecutiveCorrect === 1) {
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
     * 获取连续答对次数
     */
    private function getConsecutiveCorrect(MistakeReview $review): int
    {
        // 简化版本：通过正确率和最近结果判断
        // 完整版本需要记录详细的答题历史
        if ($review->correct_count === 1) {
            return 0;
        }

        if ($review->correct_count === 2 && $review->last_result === 'correct') {
            return 1;
        }

        if ($review->correct_count >= 3 && $review->last_result === 'correct') {
            return 2;
        }

        return 0;
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
     * 修改复习状态
     */
    public function updateStatus(User $user, int $itemId, string $status, ?string $nextReviewAt = null): MistakeReview
    {
        $review = MistakeReview::query()
            ->where('user_id', $user->id)
            ->where('mistake_item_id', $itemId)
            ->firstOrFail();

        $review->status = $status;

        if ($nextReviewAt) {
            $review->next_review_at = $nextReviewAt;
        }

        $review->save();

        return $review;
    }
}
