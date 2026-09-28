<?php

declare(strict_types=1);

namespace App\Model;

use App\Support\AccountId;
use Hyperf\Database\Model\Model;
use Hyperf\Database\Model\Relations\BelongsTo;

class MistakeAccount extends Model
{
    protected ?string $table = 'mistake_accounts';
    protected array $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function displayId(): string
    {
        return AccountId::format((int) $this->id);
    }
}
