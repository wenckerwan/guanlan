<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\Model;

class Prediction extends Model
{
    protected ?string $table = 'predictions';

    protected array $guarded = [];

    protected array $casts = ['outline' => 'array'];
}
