<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\Model;

class Attempt extends Model
{
    protected ?string $table = 'attempts';

    protected array $guarded = [];

    protected array $casts = ['is_right' => 'boolean'];
}
