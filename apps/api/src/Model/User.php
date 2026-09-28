<?php

declare(strict_types=1);

namespace AppModel;

use HyperfDatabaseModelModel;
use HyperfDatabaseModelRelationsHasMany;
use HyperfDatabaseModelRelationsHasOne;

class User extends Model
{
    protected ?string $table = 'users';

    protected array $guarded = [];

    protected array $hidden = ['password_hash'];

    public function tokens(): HasMany
    {
        return $this->hasMany(UserToken::class, 'user_id');
    }

    public function mistakeAccount(): HasOne
    {
        return $this->hasOne(MistakeAccount::class, 'user_id');
    }

    public function mistakeStudent(): HasOne
    {
        return $this->hasOne(MistakeStudent::class, 'owner_user_id');
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function accountId(): string
    {
        $account = $this->mistakeAccount;
        return $account instanceof MistakeAccount ? $account->displayId() : trim((string) ($this->mistake_code ?? ''));
    }

    /** 兼容旧调用；新代码应使用 accountId()。 */
    public function mistakeCode(): string
    {
        return $this->accountId();
    }
}
