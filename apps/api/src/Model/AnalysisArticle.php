<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\Model;

class AnalysisArticle extends Model
{
    protected ?string $table = 'analysis_articles';

    protected array $guarded = [];

    protected array $casts = ['outline' => 'array', 'release' => 'boolean'];
}