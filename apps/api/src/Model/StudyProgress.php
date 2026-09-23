<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\Model;

class StudyProgress extends Model
{
    protected ?string $table = 'study_progress';

    protected array $guarded = [];

    protected array $casts = ['last_seen_at' => 'datetime'];
}
