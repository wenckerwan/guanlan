<?php
declare(strict_types=1);

namespace Psr\Http\Message { interface ResponseInterface {} }
namespace Hyperf\HttpServer\Contract { interface RequestInterface {} }
namespace App\Model { class AnalysisArticle { public function __construct(public string $slug) {} } }
namespace App\Support {
    class TestResponse implements \Psr\Http\Message\ResponseInterface {
        public function __construct(public array $payload, public int $status) {}
    }
    class ApiResponse {
        public static function message($message, $status) { return self::json(['message' => $message], $status); }
        public static function json($payload, $status = 200) { return new TestResponse($payload, $status); }
        public static function data($data) { return self::json(['data' => $data]); }
    }
    class GuestQuota {
        public static bool $signedIn = false;
        public static function locked($index) { return !self::$signedIn && $index >= 3; }
    }
}
namespace App\Resource {
    class ArticleResource { public static function detail($model) { return ['slug' => $model->slug]; } }
}
namespace App\Service {
    class ArticleService {
        public array $visible = [];
        public function analysisBySlug(string $slug): ?\App\Model\AnalysisArticle { return $this->visible[$slug] ?? null; }
        public function analysis(): array { return array_values($this->visible); }
    }
}
namespace {
    require __DIR__ . '/../src/Controller/ArticleController.php';
    $helper = __DIR__ . '/../src/Support/AnalysisRelease.php';
    if (is_file($helper)) require $helper;
    $service = new App\Service\ArticleService();
    $request = new class implements Hyperf\HttpServer\Contract\RequestInterface {};
    $controller = new App\Controller\ArticleController($service, $request);
    $failures = [];
    $check = function ($ok, $label) use (&$failures) { if (!$ok) $failures[] = $label; };
    $target = '2012-2026-649d1f95';
    $oldSlugs = ['2012-2026-45208c3f', '2012-2026-v2-4f5624ad'];
    foreach ($oldSlugs as $old) {
        $service->visible = [$target => new App\Model\AnalysisArticle($target)];
        $response = $controller->analysisShow($old);
        $check($response->status === 410, "$old is explicitly archived");
        $check(($response->payload['code'] ?? null) === 'ANALYSIS_VERSION_ARCHIVED', 'structured archive code');
        $check(($response->payload['replacementSlug'] ?? null) === $target, 'visible replacement offered');
        $check(!isset($response->payload['data']), 'archive contains no article body');
        $service->visible = [];
        $response = $controller->analysisShow($old);
        $check($response->status === 410, 'archive remains explainable without visible replacement');
        $check(!isset($response->payload['replacementSlug']), 'hidden or missing replacement is not disclosed');
        $service->visible = [$old => new App\Model\AnalysisArticle($old)];
        $check($controller->analysisShow($old)->status === 200, 'visible old version works before publication');
    }
    $service->visible = [];
    $check($controller->analysisShow('unknown')->status === 404, 'unknown slug stays 404');
    foreach (['first', 'second', 'third', $target] as $slug) {
        $service->visible[$slug] = new App\Model\AnalysisArticle($slug);
    }
    $check($controller->analysisShow($oldSlugs[0])->status === 410, 'archive is not a redirect or a body');
    $check($controller->analysisShow($target)->status === 403, 'replacement retains guest quota');
    App\Support\GuestQuota::$signedIn = true;
    $check($controller->analysisShow($target)->status === 200, 'signed-in replacement works normally');
    if ($failures) { fwrite(STDERR, implode("\n", $failures) . "\n"); exit(1); }
    echo "AnalysisReleaseLinksTest: PASS\n";
}
