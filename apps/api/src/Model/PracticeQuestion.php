<?php
declare(strict_types=1);
namespace App\Model;
use Hyperf\Database\Model\Model;
class PracticeQuestion extends Model
{
    protected ?string $table = 'practice_questions';
    protected array $guarded = [];
    protected array $casts = ['options' => 'array', 'sources' => 'array', 'is_active' => 'boolean'];
}
