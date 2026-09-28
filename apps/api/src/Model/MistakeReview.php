<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\Model;
use Hyperf\Database\Model\Relations\BelongsTo;

class MistakeReview extends Model
{
    protected ?string $table = 'mistake_reviews';

    protected array $guarded = [];

    protected array $casts = [
        'last_reviewed_at' => 'datetime',
        'next_review_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function mistakeItem(): BelongsTo
    {
        return $this->belongsTo(MistakeItem::class, 'mistake_item_id');
    }

    public function isNew(): bool
    {
        return $this->status === 'new';
    }

    public function isReviewing(): bool
    {
        return $this->status === 'reviewing';
    }

    public function isMastered(): bool
    {
        return $this->status === 'mastered';
    }

    public function isSnoozed(): bool
    {
        return $this->status === 'snoozed';
    }

    public function isDueToday(): bool
    {
        if (! $this->next_review_at) {
            return false;
        }

        return $this->next_review_at->lte(now()->endOfDay());
    }

    public function isOverdue(): bool
    {
        if (! $this->next_review_at) {
            return false;
        }

        return $this->next_review_at->lt(now()->startOfDay());
    }

    public function accuracy(): float
    {
        if ($this->review_count === 0) {
            return 0;
        }

        return round($this->correct_count / $this->review_count, 4);
    }
}
