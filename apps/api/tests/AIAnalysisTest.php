<?php

declare(strict_types=1);

/**
 * AI 分析服务纯逻辑测试（无外部依赖，与 MistakeAccessTest 同风格）。
 *
 * 用桩件替换 Hyperf\Guzzle\ClientFactory / GuzzleHttp\Client / App\Model\MistakeStudent，
 * 只覆盖不触网的纯逻辑：默认配置、配置校验、系统提示词、SSRF 出网拦截。
 * 真实 HTTP 调用路径由容器冒烟人工验证，不在单测范围内。
 */

namespace Hyperf\Guzzle {
    class ClientFactory
    {
        public function create(array $options = []): \GuzzleHttp\Client
        {
            return new \GuzzleHttp\Client();
        }
    }
}

namespace GuzzleHttp {
    class Client
    {
    }
}

namespace App\Model {
    class MistakeStudent
    {
    }
}

namespace {
    use App\Service\AIAnalysisService;

    require dirname(__DIR__) . '/src/Service/AIAnalysisService.php';

    $failures = [];
    $check = static function (string $label, mixed $actual, mixed $expected) use (&$failures): void {
        if ($actual !== $expected) {
            $failures[] = sprintf('%s: expected %s, got %s', $label, var_export($expected, true), var_export($actual, true));
        }
    };

    $service = new AIAnalysisService(new \Hyperf\Guzzle\ClientFactory());

    // 默认配置
    $config = $service->getDefaultConfig();
    $check('default provider', $config['provider'] ?? null, 'openai');
    $check('default baseUrl', $config['baseUrl'] ?? null, 'https://api.openai.com/v1');
    $check('default model', $config['model'] ?? null, 'gpt-4');

    // 配置校验
    $check('valid openai', $service->validateConfig([
        'provider' => 'openai', 'apiKey' => 'sk-test', 'baseUrl' => 'https://api.openai.com/v1', 'model' => 'gpt-4',
    ]), true);
    $check('valid claude', $service->validateConfig([
        'provider' => 'claude', 'apiKey' => 'sk-ant-test', 'baseUrl' => 'https://api.anthropic.com/v1', 'model' => 'claude-opus-4-8',
    ]), true);
    $check('valid custom', $service->validateConfig([
        'provider' => 'custom', 'endpoint' => 'https://example.com/api/analyze',
    ]), true);
    $check('missing apiKey rejected', $service->validateConfig(['provider' => 'openai']), false);
    $check('unknown provider rejected', $service->validateConfig(['provider' => 'unknown', 'apiKey' => 'x']), false);
    $check('custom without endpoint rejected', $service->validateConfig(['provider' => 'custom', 'apiKey' => 'x']), false);

    // 系统提示词关键要素
    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('getSystemPrompt');
    $systemPrompt = $method->invoke($service);
    foreach (['考研政治错题分析专家', '知识漏洞', '判断漏洞', '做题动作漏洞', '不修改上游教材和题库资料', '真题原文必须可核验', 'Markdown'] as $needle) {
        $check("system prompt contains {$needle}", str_contains($systemPrompt, $needle), true);
    }

    // SSRF 出网拦截：内网 / 保留 IP / 非法协议都在触网前被拒
    $internalUrls = [
        'http://127.0.0.1/v1',
        'http://localhost/v1',
        'http://169.254.169.254/latest/meta-data',
        'http://10.0.0.5/api',
        'http://192.168.1.10/v1',
        'http://172.16.0.1/v1',
        'file:///etc/passwd',
        'ftp://example.com/file',
    ];
    foreach ($internalUrls as $url) {
        $result = $service->analyze(new \App\Model\MistakeStudent(), [
            'provider' => 'openai', 'apiKey' => 'sk-test', 'baseUrl' => $url, 'model' => 'gpt-4',
        ]);
        $check("ssrf blocked openai baseUrl {$url}", str_contains((string) ($result['error'] ?? ''), '无效'), true);
    }
    $result = $service->analyze(new \App\Model\MistakeStudent(), [
        'provider' => 'custom', 'endpoint' => 'http://169.254.169.254/latest/meta-data',
    ]);
    $check('ssrf blocked custom endpoint', str_contains((string) ($result['error'] ?? ''), '无效'), true);
    // 公网地址通过 URL 校验（直接反射测 assertAllowedUrl，不触网）
    // chatTest：内网端点在触网前被拒
    $result = $service->chatTest([
        'provider' => 'custom', 'endpoint' => 'http://127.0.0.1:8000/api',
    ]);
    $check('chat test ssrf blocked', str_contains((string) ($result['error'] ?? ''), '无效'), true);
    $result = $service->chatTest([
        'provider' => 'openai', 'apiKey' => 'x', 'baseUrl' => 'http://192.168.1.1/v1',
    ]);
    $check('chat test ssrf blocked openai', str_contains((string) ($result['error'] ?? ''), '无效'), true);

    $urlGate = $reflection->getMethod('assertAllowedUrl');
    $check('public url allowed', $urlGate->invoke($service, 'https://api.openai.com/v1'), null);
    $check('internal url message', str_contains((string) $urlGate->invoke($service, 'http://10.0.0.5/api'), '不允许'), true);

    if ($failures !== []) {
        fwrite(STDERR, "AIAnalysisTest: FAIL\n" . implode("\n", $failures) . "\n");
        exit(1);
    }

    echo "AIAnalysisTest: PASS\n";
}
