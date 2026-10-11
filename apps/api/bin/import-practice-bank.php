<?php
declare(strict_types=1);

use App\Service\PracticeBankImporter;
use Hyperf\Database\Model\Register;

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';
$container = require BASE_PATH . '/config/container.php';
Register::setConnectionResolver($container->get(Hyperf\Database\ConnectionResolverInterface::class));
$publish = in_array('--publish', $argv, true);
Swoole\Coroutine\run(static function () use ($publish): void {
    $package = PracticeBankImporter::readPackage(BASE_PATH . '/resources/private/current-affairs-2026.json');
    $result = (new PracticeBankImporter())->import($package, $publish);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
});
