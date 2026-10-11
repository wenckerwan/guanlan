<?php
declare(strict_types=1);
namespace App\Model;
use Hyperf\Database\Model\Model;
class PracticeAttempt extends Model
{
    protected ?string $table = 'practice_attempts';
    protected array $guarded = [];
    public bool $timestamps = false;
    protected array $casts = ['is_right' => 'boolean'];
}
