<?php
declare(strict_types=1);
ini_set('display_errors','0');define('BASE_PATH',dirname(__DIR__));require BASE_PATH.'/vendor/autoload.php';Hyperf\Di\ClassLoader::init();$container=require BASE_PATH.'/config/container.php';$application=$container->get(Hyperf\Contract\ApplicationInterface::class);$application->run();
