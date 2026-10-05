<?php
declare(strict_types=1);

namespace App\Service;

use App\Support\AiOutboundPolicy;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;

/** IP-pinned transport; StreamHandler cannot silently select an unpinned coroutine/cURL handler. */
final class SafeAiHttpClient
{
    public const MAX_RESPONSE_BYTES = 4 * 1024 * 1024;

    public function __construct(private ?AiOutboundPolicy $policy = null, private ?Client $client = null)
    {
        $this->policy ??= new AiOutboundPolicy();
    }

    public function get(string $url, array $options = []): ResponseInterface { return $this->request('GET', $url, $options); }
    public function post(string $url, array $options = []): ResponseInterface { return $this->request('POST', $url, $options); }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $safeRequest = new Request($method, 'https://ai-outbound.invalid/');
        try {
            $target = $this->policy->resolve($url);
            $duration = max(1, min(300, (float) ($options['timeout'] ?? 60)));
            $deadline = microtime(true) + $duration;
            $headers = $options['headers'] ?? [];
            // Never allow a caller-supplied authority or proxy to undo address binding.
            foreach (array_keys($headers) as $name) {
                if (in_array(strtolower($name), ['host', 'accept-encoding'], true)) unset($headers[$name]);
            }
            $headers['Host'] = $target['authority'];
            $headers['Accept-Encoding'] = 'identity';
            $options = [
                'headers' => $headers,
                'allow_redirects' => false, 'proxy' => '', 'verify' => true,
                'http_errors' => false, 'stream' => true, 'decode_content' => false,
                'timeout' => min(15, $duration), 'read_timeout' => min(15, $duration),
                'stream_context' => ['ssl' => ['peer_name' => $target['host'], 'SNI_enabled' => true]],
            ] + array_intersect_key($options, ['json' => true]);
            $this->client ??= new Client(['handler' => new StreamHandler()]);
            $response = $this->client->request($method, $target['url'], $options);
            if ($response->getStatusCode() >= 300 && $response->getStatusCode() < 400) {
                $response->getBody()->close();
                throw new \RuntimeException('AI endpoint redirect is forbidden');
            }
            $source = $response->getBody();
            if ((float) $response->getHeaderLine('Content-Length') > self::MAX_RESPONSE_BYTES) {
                $source->close();
                throw new \RuntimeException('AI response is too large');
            }
            $bytes = 0;
            $body = new PumpStream(static function (int $length) use ($source, &$bytes, $deadline, $safeRequest) {
                try {
                    if (microtime(true) >= $deadline) throw new \RuntimeException('AI request timed out');
                    if ($source->eof()) { $source->close(); return false; }
                    $chunk = $source->read(min($length, 8192, self::MAX_RESPONSE_BYTES - $bytes + 1));
                    $bytes += strlen($chunk);
                    if ($bytes > self::MAX_RESPONSE_BYTES) throw new \RuntimeException('AI response is too large');
                    if ($chunk === '' && ! $source->eof()) throw new \RuntimeException('AI stream stalled');
                    return $chunk === '' ? false : $chunk;
                } catch (\Throwable) {
                    $source->close();
                    throw new RequestException('AI 响应读取失败或超过安全限制', $safeRequest);
                }
            });
            return $response->withBody($body);
        } catch (\Throwable) {
            // Do not propagate upstream body, URL query, authorization, or private prompt in exception messages.
            throw new RequestException('AI 安全请求失败（地址、连接或响应不符合要求）', $safeRequest);
        }
    }
}
