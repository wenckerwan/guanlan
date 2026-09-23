<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\Model;

class Question extends Model
{
    protected ?string $table = 'questions';

    protected array $guarded = [];

    protected array $casts = ['options' => 'array'];

    /** 客观题才可判分；分析题无固定答案。 */
    public function isObjective(): bool
    {
        return in_array($this->type, ['single', 'multi'], true);
    }
}
