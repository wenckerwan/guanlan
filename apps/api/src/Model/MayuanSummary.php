<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\Model;

/**
 * 马原学习摘要（每用户一行，绝对值覆盖；revision 单调、last_event_id 幂等）。
 */
class MayuanSummary extends Model
{
    protected ?string $table = 'mayuan_summaries';

    protected array $guarded = [];

    protected array $casts = [
        'user_id' => 'integer',
        'revision' => 'integer',
        'visited_concept_count' => 'integer',
        'self_assessed_mastered_count' => 'integer',
        'practice_attempt_count' => 'integer',
        'practice_correct_count' => 'integer',
        'due_review_count' => 'integer',
        'resume_target' => 'array',
    ];
}
