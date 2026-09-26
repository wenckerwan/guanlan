<?php

declare(strict_types=1);

namespace App\Controller;

use Hyperf\DbConnection\Db;
use Throwable;

final class HealthController
{
    public function index(): array
    {
        $db = 'fail';
        try {
            Db::select('select 1');
            $db = 'ok';
        } catch (Throwable) {
            $db = 'fail';
        }

        return [
            'data' => [
                'status' => 'ok',
                'db' => $db,
                'version' => 'V0.1-dev.5',
            ],
        ];
    }
}
