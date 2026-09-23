<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\Model;
use Hyperf\Database\Model\Relations\HasMany;

class Paper extends Model
{
    protected ?string $table = 'papers';

    protected array $guarded = [];

    protected array $casts = ['sections' => 'array'];

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'pid', 'pid')->orderBy('no');
    }
}
