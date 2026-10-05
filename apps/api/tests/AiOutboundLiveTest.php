<?php
declare(strict_types=1);

// Non-mutating transport smoke test. Requires installed Guzzle, Swoole and internet access.
require $argv[1] ?? dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/Support/AiOutboundPolicy.php';
require_once dirname(__DIR__) . '/src/Service/SafeAiHttpClient.php';

use App\Service\SafeAiHttpClient;
use App\Support\AiOutboundPolicy;
use GuzzleHttp\Exception\GuzzleException;

$run = static function (): void {
    $safe = new SafeAiHttpClient();
    $response = $safe->get('https://api.deepseek.com/models', ['timeout' => 20]);
    $status = $response->getStatusCode();
    $raw = $response->getBody()->getContents();
    if ($status !== 401 || $raw === '') {
        throw new RuntimeException('Expected vendor HTTP 401 without credentials');
    }
    echo "Pinned HTTPS with real TLS hostname: PASS (HTTP 401)\n";
    $wrongTls = new SafeAiHttpClient(new AiOutboundPolicy(static fn () => ['1.1.1.1']));
    try { $wrongTls->get('https://mismatch.example/', ['timeout' => 5]); throw new RuntimeException('TLS hostname verification bypassed'); }
    catch (GuzzleException) { echo "TLS hostname mismatch: rejected\n"; }
    echo "AiOutboundLiveTest: PASS\n";
};
if (extension_loaded('swoole')) {
    Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
    Swoole\Coroutine\run($run);
} else {
    $run();
}
