<?php

declare(strict_types=1);

use App\Controller\AdminController;
use App\Controller\ArticleController;
use App\Controller\AuthController;
use App\Controller\HealthController;
use App\Controller\HomeController;
use App\Controller\MistakeController;
use App\Controller\MockController;
use App\Controller\PaperController;
use App\Controller\QuestionController;
use App\Controller\SearchController;
use App\Controller\StatsController;
use App\Controller\StudyController;
use App\Controller\SubjectController;
use App\Middleware\AuthMiddleware;
use App\Middleware\RequireAdminMiddleware;
use App\Middleware\RequireAuthMiddleware;
use Hyperf\HttpServer\Router\Router;

// 基础
Router::get('/api/v1/health', [HealthController::class, 'index']);
Router::get('/api/v1/home', [HomeController::class, 'index']);
Router::get('/api/v1/stats/overview', [StatsController::class, 'overview']);
Router::get('/api/v1/search', [SearchController::class, 'index'], ['middleware' => [AuthMiddleware::class]]);

// 学科
Router::get('/api/v1/subjects', [SubjectController::class, 'index']);
Router::get('/api/v1/subjects/{slug}', [SubjectController::class, 'show']);

// 真题回顾
Router::get('/api/v1/papers', [PaperController::class, 'index']);
Router::get('/api/v1/papers/modules', [PaperController::class, 'modules']);
Router::get('/api/v1/papers/{pid}', [PaperController::class, 'show']);
Router::get('/api/v1/questions', [QuestionController::class, 'index']);
Router::get('/api/v1/questions/{id:\d+}', [QuestionController::class, 'show']);

// 真题分析 / 时政热点 / 时政预测（AuthMiddleware 只解析身份，游客配额据此计算）
Router::addGroup('/api/v1', function () {
    Router::get('/analysis', [ArticleController::class, 'analysisIndex']);
    Router::get('/analysis/{slug}', [ArticleController::class, 'analysisShow']);
    Router::get('/hotspots', [ArticleController::class, 'hotspotIndex']);
    Router::get('/hotspots/{slug}', [ArticleController::class, 'hotspotShow']);
    Router::get('/predictions', [ArticleController::class, 'predictionIndex']);
    Router::get('/predictions/{slug}', [ArticleController::class, 'predictionShow']);
}, ['middleware' => [AuthMiddleware::class]]);

// 模拟押题
Router::get('/api/v1/mocks', [MockController::class, 'index']);
Router::get('/api/v1/mocks/{slug}', [MockController::class, 'show']);

// 个人错题分析（AuthMiddleware 解析身份；可见性在控制器内统一裁决）
Router::addGroup('/api/v1/mistakes', function () {
    Router::get('/students', [MistakeController::class, 'students']);
    Router::get('/handbooks/{id:\d+}', [MistakeController::class, 'handbook']);
    Router::get('/students/{code}/items', [MistakeController::class, 'items']);
    Router::get('/students/{code}/handbooks', [MistakeController::class, 'handbooks']);
    Router::get('/students/{code}/detail', [MistakeController::class, 'detail']);
    Router::get('/students/{code}/review-summary', [MistakeController::class, 'reviewSummary']);
    Router::get('/students/{code}', [MistakeController::class, 'show']);
    Router::post('/students/{code}/ai-analysis', [MistakeController::class, 'requestAIAnalysis']);
    Router::get('/ai-config', [MistakeController::class, 'getAIConfig']);
}, ['middleware' => [AuthMiddleware::class]]);
Router::patch('/api/v1/mistakes/items/{id:\d+}/action', [MistakeController::class, 'updateAction'], ['middleware' => [AuthMiddleware::class, RequireAuthMiddleware::class]]);
Router::post('/api/v1/mistakes/items/{id:\d+}/review', [MistakeController::class, 'submitReview'], ['middleware' => [AuthMiddleware::class, RequireAuthMiddleware::class]]);
Router::patch('/api/v1/mistakes/items/{id:\d+}/review-status', [MistakeController::class, 'updateReviewStatus'], ['middleware' => [AuthMiddleware::class, RequireAuthMiddleware::class]]);

// 认证
Router::post('/api/v1/auth/register', [AuthController::class, 'register']);
Router::post('/api/v1/auth/login', [AuthController::class, 'login']);
Router::get('/api/v1/auth/me', [AuthController::class, 'me'], ['middleware' => [AuthMiddleware::class]]);
Router::post('/api/v1/auth/logout', [AuthController::class, 'logout'], ['middleware' => [AuthMiddleware::class]]);

// 用户态：收藏 / 笔记 / 做题记录 / 进度
Router::addGroup('/api/v1/study', function () {
    Router::get('/favorites', [StudyController::class, 'favorites']);
    Router::post('/favorites', [StudyController::class, 'toggleFavorite']);
    Router::delete('/favorites/{id:\d+}', [StudyController::class, 'removeFavorite']);
    Router::get('/notes', [StudyController::class, 'notes']);
    Router::post('/notes', [StudyController::class, 'createNote']);
    Router::delete('/notes/{id:\d+}', [StudyController::class, 'removeNote']);
    Router::post('/attempts', [StudyController::class, 'createAttempt']);
    Router::get('/stats', [StudyController::class, 'stats']);
    Router::get('/progress', [StudyController::class, 'progress']);
    Router::post('/progress', [StudyController::class, 'saveProgress']);
}, ['middleware' => [AuthMiddleware::class, RequireAuthMiddleware::class]]);

// 后台
Router::addGroup('/api/v1/admin', function () {
    Router::get('/overview', [AdminController::class, 'overview']);
    Router::get('/papers', [AdminController::class, 'papers']);
    Router::get('/papers/{pid}/questions', [AdminController::class, 'paperQuestions']);
    Router::get('/mocks', [AdminController::class, 'mocks']);
    Router::get('/predictions', [AdminController::class, 'predictions']);
    Router::get('/users', [AdminController::class, 'users']);
    Router::patch('/users/{id:\d+}', [AdminController::class, 'updateUser']);
    Router::get('/attempts', [AdminController::class, 'attempts']);
    Router::get('/mistakes', [AdminController::class, 'mistakes']);
    Router::get('/mistakes/students', [AdminController::class, 'mistakeStudents']);
    Router::get('/mistakes/students/{code}/items', [AdminController::class, 'mistakeItems']);
    Router::get('/mistakes/students/{code}/profile', [AdminController::class, 'mistakeProfile']);
    Router::put('/mistakes/students/{code}/profile', [AdminController::class, 'saveMistakeProfile']);
    Router::patch('/mistakes/items/{id:\d+}', [AdminController::class, 'updateMistakeItem']);
    Router::get('/mistakes/review-stats', [AdminController::class, 'mistakeReviewStats']);
    Router::get('/hotspots', [AdminController::class, 'hotspots']);
    Router::post('/hotspots', [AdminController::class, 'createHotspot']);
    Router::patch('/hotspots/{id:\d+}', [AdminController::class, 'updateHotspot']);
    Router::delete('/hotspots/{id:\d+}', [AdminController::class, 'deleteHotspot']);
    Router::get('/analysis', [AdminController::class, 'analysis']);
    Router::post('/analysis', [AdminController::class, 'createAnalysis']);
    Router::patch('/analysis/{id:\d+}', [AdminController::class, 'updateAnalysis']);
    Router::delete('/analysis/{id:\d+}', [AdminController::class, 'deleteAnalysis']);
}, ['middleware' => [AuthMiddleware::class, RequireAdminMiddleware::class]]);
