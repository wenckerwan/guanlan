<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\Model;

class MockQuestion extends Model
{
    protected ?string $table = 'mock_questions';

    protected array $guarded = [];

    public bool $timestamps = false;

    protected array $casts = ['options' => 'array'];
}

