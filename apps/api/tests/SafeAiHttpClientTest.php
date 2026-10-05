<?php
declare(strict_types=1);

// Run with installed Guzzle: php tests/SafeAiHttpClientTest.php [autoload path].
require $argv[1] ?? dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/Support/AiOutboundPolicy.php';
require_once dirname(__DIR__) . '/src/Service/SafeAiHttpClient.php';

use App\Service\SafeAiHttpClient;
use App\Support\AiOutboundPolicy;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\HandlerStack;

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    ++$checks;
    if (! $ok) throw new RuntimeException($label);
};
$calls = 0;
$response = new Response(200, [], '{"ok":true}');
$handler = static function ($request, $options) use (&$calls, &$response, $check) {
    ++$calls;
    $check($request->getUri()->getHost() === '93.184.215.14' && $request->getUri()->getPath() === '/v1', 'pin approved IP');
    $check($request->getHeaderLine('Host') === 'public.example:443', 'original Host');
    $check($options['stream_context']['ssl']['peer_name'] === 'public.example', 'TLS hostname');
    $check($options['verify'] === true && $options['proxy'] === '' && $options['allow_redirects'] === false, 'security options cannot be overridden');
    $check($options['stream'] === true && $options['decode_content'] === false, 'bounded raw stream');
    return Create::promiseFor($response);
};
$stack = HandlerStack::create($handler);
$client = new Client(['handler' => $stack]);
$policy = new AiOutboundPolicy(static fn () => ['93.184.215.14']);
$safe = new SafeAiHttpClient($policy, $client);
$result = $safe->post('https://public.example/v1', ['headers' => ['Host' => 'localhost'], 'verify' => false, 'proxy' => 'http://localhost', 'allow_redirects' => true, 'json' => ['prompt' => 'private']]);
$check($result->getBody()->getContents() === '{"ok":true}', 'normal response');
foreach ([
    new Response(302, ['Location' => 'http://127.0.0.1/private']),
    new Response(200, ['Content-Length' => SafeAiHttpClient::MAX_RESPONSE_BYTES + 1]),
    new Response(200, [], new PumpStream(static fn ($size) => str_repeat('x', min(8192, $size)))),
] as $response) {
    try { $safe->get('https://public.example/v1')->getBody()->getContents(); $check(false, 'forbidden response accepted'); }
    catch (GuzzleException $e) { $check(! str_contains($e->getMessage(), 'private'), 'sanitized error'); }
}
$before = $calls;
try { $safe->get('http://127.0.0.1/'); $check(false, 'forbidden host accepted'); }
catch (GuzzleException) { $check($calls === $before, 'blocked before transport'); }
$check($calls === 4, 'redirect never followed');
echo "SafeAiHttpClientTest: PASS ({$checks} checks)\n";
