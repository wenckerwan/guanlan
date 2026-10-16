<?php
declare(strict_types=1);

namespace Psr\Http\Message { interface ResponseInterface {} }
namespace Hyperf\HttpServer\Contract { interface RequestInterface {} }
namespace App\Support {
    class TestResponse implements \Psr\Http\Message\ResponseInterface {
        public function __construct(public int $status) {}
    }
    class ApiResponse {
        public static function message($message, $status = 200, $errors = null) { return new TestResponse($status); }
        public static function data($data) { return new TestResponse(200); }
    }
    class GuestQuota { public static function locked($index) { return $index >= 3; } }
    class Validator {
        public function __construct($data) {}
        public function required(...$args) { return $this; }
        public function email(...$args) { return $this; }
        public function fails() { return false; }
        public function string($key) { return 'unused@example.invalid'; }
    }
}
namespace App\Resource { class ArticleResource { public static function detail($model) { return []; } } }
namespace App\Service {
    class AuthService {
        public string $ip = '';
        public function sendRegisterCode($email, $ip) { $this->ip = $ip; return []; }
    }
}
namespace Hyperf\HttpServer\Router {
    class Router {
        public static array $routes = [];
        public static function get($path, $handler, $options = []) { self::$routes[$path] = $options; }
        public static function addGroup($prefix, $callback, $options = []) { /* Top-level routes are tested below. */ }
        public static function __callStatic($name, $args) {}
    }
}
namespace Mayuan {
    class GuanlanAdapter { public static function status() { return ['configured' => false]; } }
    class Storage { public function get($key) { return ['published' => ['nodes' => []]]; } }
    class Content { public static function publicContent($content) { return $content; } }
}
namespace {
    require __DIR__ . '/../src/Controller/ArticleController.php';
    require __DIR__ . '/../src/Controller/AuthController.php';
    $failures = [];
    $check = function ($ok, $label) use (&$failures) { if (!$ok) $failures[] = $label; };
    $controller = (new ReflectionClass(App\Controller\ArticleController::class))->newInstanceWithoutConstructor();
    $detail = new ReflectionMethod($controller, 'detailOrQuota');
    $items = array_map(fn($slug) => (object)['slug' => $slug], ['a','b','c','ocr-locked']);
    foreach (['ocr-locked', 'OCR-LOCKED', 'OcR-LoCkEd'] as $slug) {
        $check($detail->invoke($controller, $items, $slug, $items[3])->status === 403, 'locked article bypass: '.$slug);
    }
    $check($detail->invoke($controller, $items, 'A', $items[0])->status === 200, 'free article remains readable');
    putenv('TRUSTED_PROXY_IPS');
    $request = new class implements Hyperf\HttpServer\Contract\RequestInterface {
        public function all() { return []; }
        public function getServerParams() { return ['remote_addr' => '198.51.100.2']; }
        public function getHeaderLine($name) { return $name === 'X-Real-IP' ? '1.2.3.4' : '5.6.7.8'; }
    };
    $service = new App\Service\AuthService();
    (new App\Controller\AuthController($service, $request))->sendCode();
    $check($service->ip === '198.51.100.2', 'untrusted peer must not spoof rate-limit IP');
    putenv('TRUSTED_PROXY_IPS=198.51.100.2');
    (new App\Controller\AuthController($service, $request))->sendCode();
    $check($service->ip === '5.6.7.8', 'explicit trusted proxy may forward client IP');
    putenv('TRUSTED_PROXY_IPS');
    require __DIR__ . '/../config/routes.php';
    foreach (['/api/v1/stats/leaderboard', '/api/v1/questions', '/api/v1/questions/{id:\d+}', '/api/v1/papers/{pid}', '/api/v1/mocks/{slug}'] as $path) {
        $middleware = Hyperf\HttpServer\Router\Router::$routes[$path]['middleware'] ?? [];
        $check($middleware === [App\Middleware\AuthMiddleware::class, App\Middleware\RequireAuthMiddleware::class], 'protected route requires identity: '.$path);
    }
    require __DIR__ . '/../../../vendor/mayuan/apps/api/src/ApiError.php';
    require __DIR__ . '/../../../vendor/mayuan/apps/api/src/Application.php';
    $app = (new ReflectionClass(Mayuan\Application::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($app, 'mode'))->setValue($app, 'guanlan');
    (new ReflectionProperty($app, 'store'))->setValue($app, new Mayuan\Storage());
    foreach ([null, ['id' => 'dev:admin']] as $user) {
        try { $app->dispatch('GET', '/content', [], $user); $check(false, 'production content accepted anonymous/development identity'); }
        catch (Mayuan\ApiError $e) { $check($e->status === 401, 'production content denies unauthorized access'); }
    }
    $check($app->dispatch('GET', '/content', [], ['id' => 'guanlan:123']) === ['nodes' => []], 'authenticated content remains readable');
    $check(!isset($app->dispatch('GET', '/session', [], null)['integration']), 'session hides integration internals');
    if ($failures) { fwrite(STDERR, implode("\n", $failures)."\n"); exit(1); }
    echo "PASS article authorization and email-code client IP boundaries\n";
}
