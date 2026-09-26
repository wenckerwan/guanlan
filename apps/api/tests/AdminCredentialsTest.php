<?php

declare(strict_types=1);

use App\Seeder\AdminCredentials;

if (! defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

$autoloadPath = BASE_PATH . '/vendor/autoload.php';
if (is_file($autoloadPath)) {
    require $autoloadPath;
}
if (! class_exists(AdminCredentials::class)) {
    require BASE_PATH . '/src/Seeder/AdminCredentials.php';
}

$local = AdminCredentials::fromEnvironment(['APP_ENV' => 'local']);
assert($local === [
    'email' => 'admin@guanlan.local',
    'password' => 'guanlan2027',
    'displayName' => '管理员',
]);

$production = AdminCredentials::fromEnvironment([
    'APP_ENV' => 'production',
    'ADMIN_EMAIL' => '  ADMIN@EXAMPLE.COM ',
    'ADMIN_PASSWORD' => 'production-password',
    'ADMIN_DISPLAY_NAME' => 'Production Admin',
]);
assert($production === [
    'email' => 'admin@example.com',
    'password' => 'production-password',
    'displayName' => 'Production Admin',
]);

$productionDefaultDisplayName = AdminCredentials::fromEnvironment([
    'APP_ENV' => 'production',
    'ADMIN_EMAIL' => 'admin@example.com',
    'ADMIN_PASSWORD' => 'another-production-password',
]);
assert($productionDefaultDisplayName['displayName'] === '管理员');

$missingFailed = false;
try {
    AdminCredentials::fromEnvironment(['APP_ENV' => 'production']);
} catch (RuntimeException $exception) {
    $missingFailed = str_contains($exception->getMessage(), 'ADMIN_');
}
assert($missingFailed);

$shortPasswordFailed = false;
try {
    AdminCredentials::fromEnvironment([
        'APP_ENV' => 'production',
        'ADMIN_EMAIL' => 'admin@example.com',
        'ADMIN_PASSWORD' => 'short',
    ]);
} catch (RuntimeException $exception) {
    $shortPasswordFailed = str_contains($exception->getMessage(), 'ADMIN_');
}
assert($shortPasswordFailed);

echo "AdminCredentialsTest: PASS\n";
