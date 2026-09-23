<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\Model;
use Hyperf\Database\Model\Relations\HasMany;

class Mock extends Model
{
    protected ?string $table = 'mocks';

    protected array $guarded = [];

    public function questions(): HasMany
    {
        return $this->hasMany(MockQuestion::class, 'mock_id')->orderBy('no');
    }
}
