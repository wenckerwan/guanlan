<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\Model;

class UserStudyStat extends Model
{
    protected ?string $table = 'user_study_stats';

    protected array $guarded = [];

    public bool $timestamps = false;
}
