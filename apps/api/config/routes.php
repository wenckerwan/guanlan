<?php

declare(strict_types=1);

use App\Controller\HealthController;
use Hyperf\HttpServer\Router\Router;

Router::get('/api/v1/health', [HealthController::class, 'index']);
Router::get('/api/v1/home', [HealthController::class, 'home']);
