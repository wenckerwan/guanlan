<?php
declare(strict_types=1);

namespace Psr\Http\Message { interface ResponseInterface {} }
namespace Hyperf\HttpServer\Contract { interface RequestInterface {} }
namespace Hyperf\Contract { interface StdoutLoggerInterface {} }
namespace Hyperf\Support { function env($name, $default = null) { return getenv($name) ?: $default; } }
namespace App\Support {
    class TestResponse implements \Psr\Http\Message\ResponseInterface {
        public array $headers = [];
        public function __construct(public array $data, public int $status = 200) {}
        public function withHeader($name, $value) { $this->headers[$name] = $value; return $this; }
    }
    class ApiResponse {
        public static function data($data) { return new TestResponse($data); }
        public static function message($message, $status = 200) { return new TestResponse(['message' => $message], $status); }
    }
}
namespace App\Service {
    class VisitService {
        public array $recorded = [];
        public function overview() { return ['total' => 1]; }
        public function record($id) { $this->recorded[] = $id; return ['counted' => true]; }
    }
}
namespace App\Model {
    class HistoryDataset {
        public static array $payload;
        public static function query() { return new self(); }
        public function where(...$args) { return $this; }
        public function orderByDesc(...$args) { return $this; }
        public function first(...$args) { return (object)['id' => 1, 'payload' => self::$payload]; }
    }
    class UserStudyStat {
        public static function query() { return new self(); }
        public function __call($name, $args) { return $this; }
        public function get() { return $this; }
        public function map($fn) { return new class($fn) {
            public function __construct(private $fn) {}
            public function all() { return [($this->fn)((object)['user_id' => 1, 'display_name' => '同学', 'role' => 'admin', 'user_group' => 'admin', 'total_seconds' => 10])]; }
        }; }
    }
}
namespace {
    require __DIR__ . '/../src/Controller/VisitController.php';
    require __DIR__ . '/../src/Service/HistoryService.php';
    require __DIR__ . '/../src/Service/AuthService.php';
    require __DIR__ . '/../src/Service/StatsService.php';
    require __DIR__ . '/../src/Support/Mailer.php';
    require __DIR__ . '/../src/Support/UserGroup.php';
    $failures = [];
    $check = function ($ok, $label) use (&$failures) { if (!$ok) $failures[] = $label; };
    putenv('APP_ENV=production'); putenv('APP_KEY=test-only-1022-signing-key');
    $request = new class implements \Hyperf\HttpServer\Contract\RequestInterface {
        public ?string $cookie = null;
        public function cookie($name) { return $this->cookie; }
        public function getHeaderLine($name) { return 'same-origin'; }
    };
    $visits = new \App\Service\VisitService();
    $controller = new \App\Controller\VisitController($visits, $request);
    $request->cookie = str_repeat('a', 64);
    $check($controller->record()->status === 428 && $visits->recorded === [], 'unsigned cookie cannot record a visit');
    $issued = $controller->overview();
    preg_match('/guanlan_browser=([^;]+)/', $issued->headers['Set-Cookie'] ?? '', $matches);
    $cookie = $matches[1] ?? '';
    $check(preg_match('/^[a-f0-9]{64}\.[a-f0-9]{64}$/', $cookie) === 1, 'server issues signed cookie');
    $request->cookie = $cookie;
    $check($controller->record()->status === 200, 'signed cookie accepted');
    $check(($visits->recorded[count($visits->recorded) - 1] ?? null) === explode('.', $cookie)[0], 'visit persistence uses stable browser ID without signature');
    $request->cookie = (($cookie[0] ?? '') === 'a' ? 'b' : 'a') . substr($cookie, 1);
    $check($controller->record()->status === 428, 'modified ID rejected');
    $request->cookie = substr($cookie, 0, -1) . (substr($cookie, -1) === 'a' ? 'b' : 'a');
    $check($controller->record()->status === 428, 'modified signature rejected');
    $request->cookie = $cookie;
    putenv('APP_KEY=rotated-test-key');
    $check($controller->record()->status === 428, 'signature from previous key rejected');
    putenv('APP_KEY');
    $check($controller->overview()->status === 503, 'missing key fails closed');

    \App\Model\HistoryDataset::$payload = json_decode(file_get_contents(__DIR__ . '/../../../storage/history/history.v1.json'), true, 512, JSON_THROW_ON_ERROR);
    // Comparisons are an editorial-only collection, even if a payload mislabels a row.
    \App\Model\HistoryDataset::$payload['comparisons'][] = ['publication' => 'verified', 'textbook' => ['status' => 'pending', 'label' => 'private draft']];
    // Include all publication boundaries, including unknown and missing states.
    foreach (['pending', 'draft', 'verified', 'original', 'future'] as $state) {
        \App\Model\HistoryDataset::$payload['events'][] = ['id' => $state, 'publication' => $state, 'dates' => ['textbook' => ['status' => 'pending'], 'original' => ['status' => 'original']]];
    }
    \App\Model\HistoryDataset::$payload['events'][] = ['id' => 'missing'];
    $logger = new class implements \Hyperf\Contract\StdoutLoggerInterface {};
    $history = (new \App\Service\HistoryService($logger))->latestPublished();
    $check($history['comparisons'] === [], '34 pending textbook comparisons never leave API');
    $ids = array_column($history['events'], 'id');
    $check(!array_intersect(['pending', 'draft', 'future', 'missing'], $ids), 'only original and verified events are readable');
    $check(in_array('verified', $ids) && in_array('original', $ids), 'published events preserved');
    foreach ($history['events'] as $event) $check(($event['dates']['textbook']['status'] ?? '') !== 'pending', 'pending date assertions removed');

    putenv('MAIL_ENABLED=false');
    $auth = (new \ReflectionClass(\App\Service\AuthService::class))->newInstanceWithoutConstructor();
    try {
        $result = $auth->register('test@example.invalid', 'password', 'test');
        $check(($result['status'] ?? null) === 503, 'registration disabled without mail verification');
    } catch (\Throwable $e) { $check(false, 'registration reached persistence without mail verification: ' . get_class($e)); }
    $check(($auth->sendRegisterCode('test@example.invalid', '198.51.100.1')['status'] ?? null) === 503, 'unavailable mail code returns 503');
    $entries = (new \App\Service\StatsService())->leaderboard('total');
    $check(array_keys($entries[0]) === ['displayName', 'seconds'], 'leaderboard excludes account identifiers and privilege metadata');
    if ($failures) { fwrite(STDERR, implode("\n", array_unique($failures)) . "\n"); exit(1); }
    echo "Bugs1022Test: PASS\n";
}
