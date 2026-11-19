<?php

declare(strict_types=1);

use App\Seeder\DatasetManifestVerifier;

if (! defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

$autoloadPath = BASE_PATH . '/vendor/autoload.php';
if (is_file($autoloadPath)) {
    require $autoloadPath;
}

if (! class_exists(DatasetManifestVerifier::class)) {
    require_once BASE_PATH . '/src/Seeder/DatasetManifestVerifier.php';
}

$basePath = $argv[1] ?? BASE_PATH;

try {
    $report = (new DatasetManifestVerifier())->verify($basePath);
    $skipped = $report['skipped'];
    fwrite(STDOUT, sprintf("Dataset integrity OK: %d verified, %d skipped%s\n",
        $report['verified'], count($skipped), $skipped ? ' (' . implode(', ', $skipped) . ')' : ''));
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
