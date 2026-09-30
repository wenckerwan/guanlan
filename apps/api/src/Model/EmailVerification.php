<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\Model;

/**
 * 邮箱验证码（注册等用途）。code_hash = sha256(code . email)。
 */
class EmailVerification extends Model
{
    protected ?string $table = 'email_verifications';

    protected array $guarded = [];
}
