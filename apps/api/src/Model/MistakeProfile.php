<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\Model;
use Hyperf\Database\Model\Relations\BelongsTo;

class MistakeProfile extends Model
{
    protected ?string $table = 'mistake_profiles';
    protected array $guarded = [];
    protected array $casts = ['is_default' => 'boolean'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(MistakeStudent::class, 'student_id');
    }
}
