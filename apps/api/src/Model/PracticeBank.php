<?php
declare(strict_types=1);
namespace App\Model;
use Hyperf\Database\Model\Model;
class PracticeBank extends Model
{
    protected ?string $table = 'practice_banks';
    protected array $guarded = [];
}
