<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\Model;
use Hyperf\Database\Model\Relations\BelongsTo;
use Hyperf\Database\Model\Relations\HasMany;

class Chapter extends Model
{
    protected ?string $table = 'chapters';

    protected array $guarded = [];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function knowledgePoints(): HasMany
    {
        return $this->hasMany(KnowledgePoint::class, 'chapter_id')->orderBy('sort_order');
    }
}
