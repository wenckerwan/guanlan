<?php

declare(strict_types=1);

namespace App\Seeder;

use RuntimeException;

final class AdminCredentials
{
    /** @return array{email: string, password: string, displayName: string} */
    public static function fromEnvironment(array $environment): array
    {
        $appEnvironment = strtolower(trim((string) ($environment['APP_ENV'] ?? '')));
        $email = $environment['ADMIN_EMAIL'] ?? null;
        $password = $environment['ADMIN_PASSWORD'] ?? null;
        $displayName = trim((string) ($environment['ADMIN_DISPLAY_NAME'] ?? ''));

        if ($appEnvironment === 'local') {
            $email = $email ?? 'admin@guanlan.local';
            $password = $password ?? 'guanlan2027';
        } else {
            if (! is_string($email) || trim($email) === '') {
                throw new RuntimeException('ADMIN_EMAIL is required outside local mode');
            }
            if (! is_string($password) || $password === '') {
                throw new RuntimeException('ADMIN_PASSWORD is required outside local mode');
            }
        }

        $email = strtolower(trim((string) $email));
        $password = (string) $password;
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('ADMIN_EMAIL must be a valid email address');
        }
        if ($appEnvironment !== 'local' && strlen($password) < 12) {
            throw new RuntimeException('ADMIN_PASSWORD must be at least 12 characters');
        }

        return [
            'email' => $email,
            'password' => $password,
            'displayName' => $displayName !== '' ? $displayName : '管理员',
        ];
    }
}
