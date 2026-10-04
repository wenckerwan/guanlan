<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\Model;
use Hyperf\Database\Model\Relations\HasMany;

class Subject extends Model
{
    protected ?string $table = 'subjects';

    protected array $guarded = [];

    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class, 'subject_id')->orderBy('sort_order');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'subject_id');
    }
}
