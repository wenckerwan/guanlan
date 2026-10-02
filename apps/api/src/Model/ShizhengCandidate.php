<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\Model;

class ShizhengCandidate extends Model
{
    protected ?string $table = 'shizheng_candidates';

    protected array $guarded = [];

    protected array $casts = [
        'payload' => 'array',
        'exam_matches' => 'array',
        'exam_sim' => 'float',
        'exam_affinity' => 'float',
        'hotspot_id' => 'integer',
    ];
}
