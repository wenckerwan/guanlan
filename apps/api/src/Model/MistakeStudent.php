<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\Model;
use Hyperf\Database\Model\Relations\BelongsTo;
use Hyperf\Database\Model\Relations\HasMany;
use Hyperf\Database\Model\Relations\HasOne;

class MistakeStudent extends Model
{
    protected ?string $table = 'mistake_students';

    protected array $guarded = [];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MistakeItem::class, 'student_id');
    }

    public function handbooks(): HasMany
    {
        return $this->hasMany(MistakeHandbook::class, 'student_id');
    }

    public function profile(): HasOne
    {
        return $this->hasOne(MistakeProfile::class, 'student_id');
    }
}
