<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\Model;

class Favorite extends Model
{
    protected ?string $table = 'favorites';

    protected array $guarded = [];
}
