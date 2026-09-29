<?php

declare(strict_types=1);

namespace App\Resource;

use App\Model\User;
use App\Support\UserGroup;

class UserResource
{
    public static function make(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'email' => (string) $user->email,
            'displayName' => (string) $user->display_name,
            'role' => (string) $user->role,
            'status' => (string) $user->status,
            'userGroup' => $user->group(),
            'features' => UserGroup::features($user->group()),
            'mistakeCode' => (string) ($user->mistake_code ?? ''),
            'createdAt' => (string) ($user->created_at ?? ''),
        ];
    }

    /** @param iterable<User> $users */
    public static function collection(iterable $users): array
    {
        $out = [];
        foreach ($users as $user) {
            $out[] = self::make($user);
        }
        return $out;
    }
}
