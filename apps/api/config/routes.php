<?php

declare(strict_types=1);

use App\Controller\HealthController;
use App\Controller\HomeController;
use App\Controller\SubjectController;
use Hyperf\HttpServer\Router\Router;

Router::get('/api/v1/health', [HealthController::class, 'index']);
Router::get('/api/v1/home', [HomeController::class, 'index']);
Router::get('/api/v1/subjects', [SubjectController::class, 'index']);
Router::get('/api/v1/subjects/{slug}', [SubjectController::class, 'show']);
