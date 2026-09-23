<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\Model;
use Hyperf\Database\Model\Relations\BelongsTo;

class MistakeHandbook extends Model
{
    protected ?string $table = 'mistake_handbooks';

    protected array $guarded = [];

    protected array $casts = ['sections' => 'array'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(MistakeStudent::class, 'student_id');
    }
}
