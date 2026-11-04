<?php

declare(strict_types=1);

// Execute real controller/service code with isolated boundary doubles; no production data or AI requests.
namespace Psr\Http\Message { interface ResponseInterface {} }
namespace Hyperf\HttpServer\Contract {
    interface RequestInterface {}
    interface ResponseInterface {}
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
    echo "MistakeImportAccessTest: PASS (ordinary import ownership preserved)\n";
}
