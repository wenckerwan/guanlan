<?php

declare(strict_types=1);

namespace AppService;

use AppModelMistakeAccount;
use AppModelMistakeStudent;
use AppModelUser;
use AppSupportAccountId;

class MistakeAccountService
{
    public function __construct(private MistakeProfileService $profiles)
    {
    }

    /** @return array{account: MistakeAccount, student: MistakeStudent} */
    public function provision(User $user): array
    {
        $account = MistakeAccount::query()->where('user_id', $user->id)->first();
        if (! $account) {
            $account = MistakeAccount::create(['user_id' => (int) $user->id]);
        }

        $code = AccountId::format((int) $account->id);
        $student = MistakeStudent::query()->where('owner_user_id', $user->id)->first();
        if (! $student) {
            $student = MistakeStudent::create([
                'owner_user_id' => (int) $user->id,
                'code' => $code,
                'name' => ((string) ($user->display_name ?: $code)) . '的错题分析',
                'relation' => '本人',
                'detail_html' => '',
                'sort_order' => 100000 + (int) $account->id,
            ]);
        }

        $this->profiles->ensureDefault($student);

        // 兼容旧客户端，正式来源仍为 mistake_accounts.id。
        if ((string) $user->mistake_code !== $code) {
            $user->mistake_code = $code;
            $user->save();
        }

        return ['account' => $account, 'student' => $student];
    }
}
