<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\Model;

class Note extends Model
{
    protected ?string $table = 'notes';

    protected array $guarded = [];
}
