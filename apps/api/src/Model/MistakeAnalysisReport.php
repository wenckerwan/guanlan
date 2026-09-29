<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\Model;

class MistakeAnalysisReport extends Model
{
    protected ?string $table = 'mistake_analysis_reports';

    protected array $guarded = [];

    public $timestamps = false;
}
