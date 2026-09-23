<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\Model;
use Hyperf\Database\Model\Relations\HasMany;

class MistakeStudent extends Model
{
    protected ?string $table = 'mistake_students';

    protected array $guarded = [];

    public function items(): HasMany
    {
        return $this->hasMany(MistakeItem::class, 'student_id');
    }

    public function handbooks(): HasMany
    {
        return $this->hasMany(MistakeHandbook::class, 'student_id');
    }
}
