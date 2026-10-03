<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\Model;

class HistoryDataset extends Model
{
    protected ?string $table = 'history_datasets';

    protected array $guarded = [];

    protected array $casts = ['payload' => 'array'];
}
