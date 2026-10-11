<?php
declare(strict_types=1);
if (!str_ends_with((string)getenv('DB_DATABASE'), '_admin_test')) throw new RuntimeException('Disposable database required');
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';
$container = require BASE_PATH . '/config/container.php';
Hyperf\Database\Model\Register::setConnectionResolver($container->get(Hyperf\Database\ConnectionResolverInterface::class));
Swoole\Coroutine\run(function (): void {
    foreach (['a'=>'user', 'b'=>'user', 'admin'=>'admin'] as $key=>$role) {
        App\Model\User::query()->updateOrCreate(['email'=>"practice-$key@example.test"], [
            'password_hash'=>password_hash('Practice-test-20261011!', PASSWORD_DEFAULT),
            'display_name'=>"时政测试$key", 'role'=>$role, 'status'=>'active', 'user_group'=>'user',
        ]);
    }
    echo "DISPOSABLE_FIXTURES_READY\n";
});
