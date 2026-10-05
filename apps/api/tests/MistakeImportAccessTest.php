<?php

declare(strict_types=1);

// Execute real controller/service code with isolated boundary doubles; no production data or AI requests.
namespace Psr\Http\Message { interface ResponseInterface {} }
namespace Hyperf\HttpServer\Contract {
    interface RequestInterface {}
    interface ResponseInterface {}
}
namespace Hyperf\Context {
    class ApplicationContext {
        public static int $calls = 0;
        public static ?\App\Service\AIAnalysisService $ai = null;
        public static function getContainer(): object {
            ++self::$calls;
            if (self::$ai !== null) return new class {
                public function get(string $class): \App\Service\AIAnalysisService { return ApplicationContext::$ai; }
            };
            throw new \RuntimeException('AI must not be resolved for denied imports');
        }
    }
}
namespace App\Model {
    class User {
        public function __construct(public int $id, public string $role = 'user', public string $mistake_code = '') {}
        public function isAdmin(): bool { return $this->role === 'admin'; }
    }
    class MistakeStudent {
        public function __construct(public string $code, public int $owner_user_id = 0) {}
    }
    class MistakeItem {
        public static int $queries = 0;
        public static function query(): never {
            ++self::$queries;
            throw new \RuntimeException('database reached');
        }
    }
}
namespace App\Support {
    class Auth {
        public static ?\App\Model\User $current = null;
        public static function user(): ?\App\Model\User { return self::$current; }
    }
    class TestResponse implements \Psr\Http\Message\ResponseInterface {
        public function __construct(public int $status, public string $message) {}
    }
    class ApiResponse {
        public static function message(string $message, int $status, array $errors = []): TestResponse {
            return new TestResponse($status, $message);
        }
    }
}
namespace App\Service { class MistakeReviewService {} }
namespace Hyperf\Guzzle { class ClientFactory {} }
namespace {
    $root = dirname(__DIR__);
    require $root . '/src/Support/MistakeAccess.php';
    require $root . '/src/Support/Validator.php';
    require $root . '/src/Service/MistakeService.php';
    require $root . '/src/Controller/MistakeController.php';
    require $root . '/src/Support/AiOutboundPolicy.php';
    require $root . '/src/Service/SafeAiHttpClient.php';
    require $root . '/src/Service/AIAnalysisService.php';

    class StudentService extends \App\Service\MistakeService {
        public function __construct(private \App\Model\MistakeStudent $value) {}
        public function student(string $code): ?\App\Model\MistakeStudent { return $this->value; }
    }
    class Request implements \Hyperf\HttpServer\Contract\RequestInterface {
        public function __construct(private mixed $flag, private array $data = []) {}
        public function input(string $key, mixed $default = null): mixed {
            return $key === 'importToMistakes' ? $this->flag : ($this->data[$key] ?? $default);
        }
        public function all(): array { return $this->data; }
    }
    class Response implements \Hyperf\HttpServer\Contract\ResponseInterface {
        public function withHeader(string $name, string $value): never {
            throw new \RuntimeException('SSE headers must not be prepared for denied imports');
        }
    }
    function check(bool $condition, string $label): void {
        if (! $condition) { throw new \RuntimeException($label); }
    }

    $public = new \App\Model\MistakeStudent('A');
    foreach (['requestAIAnalysis', 'requestAIAnalysisStream'] as $method) {
        foreach ([true, 'true', '1', 1] as $flag) {
            \App\Support\Auth::$current = new \App\Model\User(7, 'user', 'A');
            $controller = new \App\Controller\MistakeController(new StudentService($public), new \App\Service\MistakeReviewService(), new Request($flag), new Response());
            check($controller->$method('A')->status === 403, "$method denies A import before validation/AI/SSE");
        }
        foreach ([false, 'false', '0', 0] as $flag) {
            $controller = new \App\Controller\MistakeController(new StudentService($public), new \App\Service\MistakeReviewService(), new Request($flag), new Response());
            check($controller->$method('A')->status === 422, "$method permits read-only analysis to reach configuration validation");
        }
        \App\Support\Auth::$current = null;
        $controller = new \App\Controller\MistakeController(new StudentService($public), new \App\Service\MistakeReviewService(), new Request(true), new Response());
        check($controller->$method('A')->status === 401, "$method denies guests");
        foreach ([[new \App\Model\User(7, 'user', '7'), new \App\Model\MistakeStudent('7', 7)], [new \App\Model\User(9, 'admin'), $public]] as [$user, $student]) {
            \App\Support\Auth::$current = $user;
            $controller = new \App\Controller\MistakeController(new StudentService($student), new \App\Service\MistakeReviewService(), new Request(true), new Response());
            check($controller->$method($student->code)->status === 422, "$method allows owner/admin to reach configuration validation");
        }
    }
    check(\Hyperf\Context\ApplicationContext::$calls === 0, 'no AI resolution');

    $service = new \App\Service\MistakeService();
    foreach ([null, new \App\Model\User(7, 'user', 'A')] as $user) {
        \App\Support\Auth::$current = $user;
        try {
            $service->importUploadedItems($public, '', [['stem' => 'test', 'correctAnswer' => 'A']]);
            throw new \RuntimeException('service unexpectedly accepted import');
        } catch (\RuntimeException $error) {
            check($error->getMessage() === '该错题本没有导入权限', 'service denies direct import');
        }
    }
    check(\App\Model\MistakeItem::$queries === 0, 'denied service imports do not access database');
    \App\Support\Auth::$current = new \App\Model\User(7, 'user', '8');
    try {
        $service->importUploadedItems(new \App\Model\MistakeStudent('8', 8), '');
        throw new \RuntimeException('foreign student import accepted');
    } catch (\RuntimeException $error) {
        check($error->getMessage() === '该错题本没有导入权限', 'matching bound code does not grant service ownership');
    }
    foreach ([[new \App\Model\User(7, 'user', '7'), new \App\Model\MistakeStudent('7', 7)], [new \App\Model\User(9, 'admin'), $public]] as [$user, $student]) {
        \App\Support\Auth::$current = $user;
        check($service->importUploadedItems($student, '') === ['imported' => 0, 'updated' => 0, 'skipped' => 0], 'owner/admin passes service write gate');
    }
    // Execute the real URL gate before any upload import, provider request, or SSE preparation.
    \Hyperf\Context\ApplicationContext::$ai = new \App\Service\AIAnalysisService(new \Hyperf\Guzzle\ClientFactory());
    \App\Support\Auth::$current = new \App\Model\User(9, 'admin');
    foreach (['http://127.0.0.1/v1', 'http://169.254.169.254/v1', 'https://[::ffff:127.0.0.1]/v1'] as $url) {
        foreach (['requestAIAnalysis', 'requestAIAnalysisStream', 'testAIConnection', 'chatTestAIConnection'] as $method) {
            $controller = new \App\Controller\MistakeController(new StudentService($public), new \App\Service\MistakeReviewService(), new Request(true, [
                'provider' => 'openai', 'apiKey' => 'fake', 'baseUrl' => $url, 'markdown' => 'Must never be imported',
            ]), new Response());
            $result = str_starts_with($method, 'request') ? $controller->$method('A') : $controller->$method();
            check($result->status === 422 && str_contains($result->message, '出网地址'), "$method rejects URL before AI/SSE/import");
        }
    }
    check(\App\Model\MistakeItem::$queries === 0, 'URL rejection does not reach database');
    echo "MistakeImportAccessTest: PASS (including 12 controller outbound rejection cases)\n";
}
