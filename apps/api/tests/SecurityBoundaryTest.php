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
    require __DIR__ . '/../src/Support/ClientAddress.php';
    require __DIR__ . '/../src/Service/TurnstileService.php';
    $failures = [];
    $check = function ($ok, $label) use (&$failures) { if (!$ok) $failures[] = $label; };
    $controller = (new ReflectionClass(App\Controller\ArticleController::class))->newInstanceWithoutConstructor();
    $detail = new ReflectionMethod($controller, 'detailOrQuota');
    $items = array_map(fn($slug) => (object)['slug' => $slug], ['a','b','c','ocr-locked']);
    foreach (['ocr-locked', 'OCR-LOCKED', 'OcR-LoCkEd'] as $slug) {
        $check($detail->invoke($controller, $items, $slug, $items[3])->status === 403, 'locked article bypass: '.$slug);
    }
    $check($detail->invoke($controller, $items, 'A', $items[0])->status === 200, 'free article remains readable');
    $secret = str_repeat('a', 64);
    putenv('BFF_PROXY_SECRET=' . $secret);
    putenv('TURNSTILE_ENABLED=false');
    $request = new class implements Hyperf\HttpServer\Contract\RequestInterface {
        public array $headers = ['X-Real-IP' => '192.0.2.1', 'X-Forwarded-For' => '192.0.2.2'];
        public function all() { return []; }
        public function input($key, $default = null) { return $default; }
        public function getMethod() { return 'POST'; }
        public function getUri() {
            return new class {
                public function getPath() { return '/api/v1/auth/email/code'; }
            };
        }
        public function getHeaderLine($name) { return $this->headers[$name] ?? ''; }
    };
    $service = new App\Service\AuthService();
    $auth = new App\Controller\AuthController($service, $request, new App\Service\TurnstileService());
    $check($auth->sendCode()->status === 503 && $service->ip === '', 'unsigned forwarding must not reach email-code service');

    $ip = '198.51.100.8';
    $timestamp = (string) time();
    $request->headers['X-Guanlan-Client-IP'] = $ip;
    $request->headers['X-Guanlan-Client-Time'] = $timestamp;
    $request->headers['X-Guanlan-Client-Signature'] = hash_hmac('sha256', "$timestamp\nPOST\n/api/v1/auth/email/code\n$ip", $secret);
    $check($auth->sendCode()->status === 200 && $service->ip === $ip, 'verified BFF address must be used for rate limiting');
    putenv('BFF_PROXY_SECRET');
    putenv('TURNSTILE_ENABLED');
    require __DIR__ . '/../config/routes.php';
    foreach (['/api/v1/history/events', '/api/v1/stats/leaderboard', '/api/v1/questions', '/api/v1/questions/{id:\d+}', '/api/v1/papers/{pid}', '/api/v1/mocks/{slug}'] as $path) {
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
    define('BASE_PATH', '/isolated-security-test');
    $sessionConfig = require __DIR__ . '/../../../vendor/mayuan/apps/api/config/autoload/session.php';
    $check(($sessionConfig['options']['domain'] ?? null) === '', 'session cookie must be host-only, never an internal container domain');
    if ($failures) { fwrite(STDERR, implode("\n", $failures)."\n"); exit(1); }
    echo "PASS article authorization and email-code client IP boundaries\n";
}
