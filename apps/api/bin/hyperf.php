<?php

declare(strict_types=1);

/**
 * Hyperf 标准控制台入口。
 *
 * 定义 BASE_PATH -> 加载 composer autoload -> 构建 DI 容器 -> 运行应用。
 */

! defined('BASE_PATH') && define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/vendor/autoload.php';

$container = require BASE_PATH . '/config/container.php';

$application = $container->get(\Hyperf\Contract\ApplicationInterface::class);

$application->run();
