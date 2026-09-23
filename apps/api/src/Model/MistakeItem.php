<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\Model;
use Hyperf\Database\Model\Relations\BelongsTo;

class MistakeItem extends Model
{
    protected ?string $table = 'mistake_items';

    protected array $guarded = [];

    protected array $casts = ['options' => 'array'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(MistakeStudent::class, 'student_id');
    }
}
