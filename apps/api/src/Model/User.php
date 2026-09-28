<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\Model;
use Hyperf\Database\Model\Relations\HasMany;

class User extends Model
{
    protected ?string $table = 'users';

    protected array $guarded = [];

    protected array $hidden = ['password_hash'];

    public function tokens(): HasMany
    {
        return $this->hasMany(UserToken::class, 'user_id');
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** 绑定的考生 / 错题编号，未绑定时为空字符串。 */
    public function mistakeCode(): string
    {
        return trim((string) ($this->mistake_code ?? ''));
    }
}
