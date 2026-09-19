<?php

declare(strict_types=1);

use function Hyperf\Support\env;

return [
    'default' => [
        'driver' => 'mysql',
        'host' => env('DB_HOST', 'mysql'),
        'port' => (int) env('DB_PORT', 3306),
        'database' => env('DB_DATABASE', 'guanlan'),
        'username' => env('DB_USERNAME', 'guanlan'),
        'password' => env('DB_PASSWORD', ''),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'timezone' => '+08:00',
        'pool' => [
            'min_connections' => 1,
            'max_connections' => 10,
            'connect_timeout' => 10.0,
            'wait_timeout' => 3.0,
            'heartbeat' => -1,
            'max_idle_time' => 60.0,
        ],
        'commands' => [
            'migrations' => [
                'path' => BASE_PATH . '/migrations',
            ],
            'seeders' => [
                'path' => BASE_PATH . '/seeders',
            ],
            'gen_model' => [
                'path' => BASE_PATH . '/src/Model',
            ],
        ],
    ],
];
