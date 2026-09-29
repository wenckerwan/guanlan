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
                'version' => $this->version(),
            ],
        ];
    }

    /**
     * 版本号以仓库根目录 VERSION 文件为唯一事实来源，读取失败时回退 unknown。
     */
    private function version(): string
    {
        $path = BASE_PATH . '/VERSION';
        $version = is_readable($path) ? trim((string) file_get_contents($path)) : '';

        return $version !== '' ? $version : 'unknown';
    }
}
