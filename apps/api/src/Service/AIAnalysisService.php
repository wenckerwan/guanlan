<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\MistakeStudent;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Hyperf\Guzzle\ClientFactory;

class AIAnalysisService
{
    private Client $httpClient;

    public function __construct(ClientFactory $clientFactory)
    {
        $this->httpClient = $clientFactory->create();
    }

    /**
     * 调用 AI 分析接口（提示词由考生错题数据构建）
     */
    public function analyze(MistakeStudent $student, array $config): array
    {
        $prompt = $this->buildPrompt($student);

        return $this->dispatch($config, $prompt);
    }

    /**
     * 调用 AI 分析接口（提示词来自用户上传的 Markdown，服务端代理转发）
     */
    public function analyzeMarkdown(MistakeStudent $student, string $markdown, array $config): array
    {
        $prompt = $this->buildMarkdownPrompt($student, $markdown);

        return $this->dispatch($config, $prompt);
    }

    private function dispatch(array $config, string $prompt): array
    {
        $provider = $config['provider'] ?? 'openai';

        return match ($provider) {
            'openai' => $this->analyzeWithOpenAI($prompt, $config),
            'claude' => $this->analyzeWithClaude($prompt, $config),
            'custom' => $this->analyzeWithCustom($prompt, $config),
            default => throw new \InvalidArgumentException("不支持的 AI 提供商: {$provider}"),
        };
    }

    /**
     * 连接测试 + 模型列表获取：不触库，仅验证出网可达性与凭证有效性。
     * openai/claude 走官方 /models 端点；custom 仅探测端点可达性。
     *
     * @return array{ok: bool, latencyMs: int, models: array<int, string>, error?: string}
     */
    public function testConnection(array $config): array
    {
        if (! $this->validateConfig($config)) {
            return ['ok' => false, 'latencyMs' => 0, 'models' => [], 'error' => 'AI 配置无效'];
        }

        $provider = $config['provider'];
        $start = microtime(true);

        try {
            if ($provider === 'custom') {
                $endpoint = (string) $config['endpoint'];
                $error = $this->assertAllowedUrl($endpoint);
                if ($error !== null) {
                    return ['ok' => false, 'latencyMs' => 0, 'models' => [], 'error' => "API 端点无效: {$error}"];
                }

                $headers = ['Content-Type' => 'application/json'];
                if (! empty($config['apiKey'])) {
                    $headers['Authorization'] = "Bearer {$config['apiKey']}";
                }

                // 大多数「自定义 API」是 OpenAI 兼容中转站：优先尝试拉取 /models
                $modelUrls = [];
                if (preg_match('~/chat/completions/?$~i', $endpoint)) {
                    $modelUrls[] = preg_replace('~/chat/completions/?$~i', '/models', $endpoint);
                }
                $modelUrls[] = rtrim($endpoint, '/') . '/models';
                foreach ($modelUrls as $url) {
                    if ($this->assertAllowedUrl($url) !== null) {
                        continue;
                    }
                    try {
                        $response = $this->httpClient->get($url, [
                            'headers' => $headers,
                            'http_errors' => false,
                            'timeout' => 8,
                        ]);
                        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                            $models = $this->extractModelIds($response->getBody()->getContents());
                            if ($models !== []) {
                                return [
                                    'ok' => true,
                                    'latencyMs' => (int) round((microtime(true) - $start) * 1000),
                                    'models' => $models,
                                ];
                            }
                        }
                    } catch (GuzzleException) {
                        continue;
                    }
                }

                // 回退：仅探测端点本身可达性
                $response = $this->httpClient->get($endpoint, [
                    'headers' => $headers,
                    'http_errors' => false,
                    'timeout' => 12,
                ]);
                $latencyMs = (int) round((microtime(true) - $start) * 1000);
                $status = $response->getStatusCode();
                // 4xx 说明端点可达（协议/路径不符由调用方自行保证），5xx 视为不可用
                if ($status < 500) {
                    return ['ok' => true, 'latencyMs' => $latencyMs, 'models' => []];
                }

                return ['ok' => false, 'latencyMs' => $latencyMs, 'models' => [], 'error' => "端点不可用，HTTP {$status}"];
            }

            if ($provider === 'openai') {
                $baseUrl = (string) ($config['baseUrl'] ?? 'https://api.openai.com/v1');
                $error = $this->assertAllowedUrl($baseUrl);
                if ($error !== null) {
                    return ['ok' => false, 'latencyMs' => 0, 'models' => [], 'error' => "Base URL 无效: {$error}"];
                }

                $response = $this->httpClient->get("{$baseUrl}/models", [
                    'headers' => ['Authorization' => 'Bearer ' . (string) ($config['apiKey'] ?? '')],
                    'http_errors' => false,
                    'timeout' => 12,
                ]);

                return $this->interpretModelList($response, $start);
            }

            // claude
            $baseUrl = (string) ($config['baseUrl'] ?? 'https://api.anthropic.com/v1');
            $error = $this->assertAllowedUrl($baseUrl);
            if ($error !== null) {
                return ['ok' => false, 'latencyMs' => 0, 'models' => [], 'error' => "Base URL 无效: {$error}"];
            }

            $response = $this->httpClient->get("{$baseUrl}/models", [
                'headers' => [
                    'x-api-key' => (string) ($config['apiKey'] ?? ''),
                    'anthropic-version' => '2023-06-01',
                ],
                'http_errors' => false,
                'timeout' => 12,
            ]);

            return $this->interpretModelList($response, $start);
        } catch (GuzzleException $e) {
            $latencyMs = (int) round((microtime(true) - $start) * 1000);

            return ['ok' => false, 'latencyMs' => $latencyMs, 'models' => [], 'error' => '连接失败: ' . mb_substr($e->getMessage(), 0, 200)];
        }
    }

    /**
     * 真实对话测试：发送「请只回复：ABC」并校验 AI 实际回复，验证模型真的可用。
     *
     * @return array{ok: bool, latencyMs: int, reply: string, error?: string}
     */
    public function chatTest(array $config): array
    {
        if (! $this->validateConfig($config)) {
            return ['ok' => false, 'latencyMs' => 0, 'reply' => '', 'error' => 'AI 配置无效'];
        }

        $provider = $config['provider'];
        $start = microtime(true);
        $message = '这是一条连通性测试。请只回复三个字母：ABC';

        try {
            if ($provider === 'custom') {
                $endpoint = (string) $config['endpoint'];
                $error = $this->assertAllowedUrl($endpoint);
                if ($error !== null) {
                    return ['ok' => false, 'latencyMs' => 0, 'reply' => '', 'error' => "API 端点无效: {$error}"];
                }

                $headers = ['Content-Type' => 'application/json'];
                if (! empty($config['apiKey'])) {
                    $headers['Authorization'] = "Bearer {$config['apiKey']}";
                }

                $isChatCompletions = (bool) preg_match('~/chat/completions/?$~i', $endpoint);
                if ($isChatCompletions) {
                    $payload = [
                        'model' => (string) ($config['model'] ?? '') ?: 'gpt-4o-mini',
                        'messages' => [['role' => 'user', 'content' => $message]],
                        'max_tokens' => 20,
                    ];
                } else {
                    $payload = ['prompt' => $message];
                }

                $response = $this->httpClient->post($endpoint, [
                    'headers' => $headers,
                    'json' => $payload,
                    'http_errors' => false,
                    'timeout' => 30,
                ]);
                $raw = $response->getBody()->getContents();
                $reply = $this->extractReply($raw);
                return $this->interpretChatReply($reply, $raw, $response->getStatusCode(), $start);
            }

            if ($provider === 'openai') {
                $baseUrl = (string) ($config['baseUrl'] ?? 'https://api.openai.com/v1');
                $error = $this->assertAllowedUrl($baseUrl);
                if ($error !== null) {
                    return ['ok' => false, 'latencyMs' => 0, 'reply' => '', 'error' => "Base URL 无效: {$error}"];
                }

                $response = $this->httpClient->post("{$baseUrl}/chat/completions", [
                    'headers' => ['Authorization' => 'Bearer ' . (string) ($config['apiKey'] ?? '')],
                    'json' => [
                        'model' => (string) ($config['model'] ?? '') ?: 'gpt-4o-mini',
                        'messages' => [['role' => 'user', 'content' => $message]],
                        'max_tokens' => 20,
                    ],
                    'http_errors' => false,
                    'timeout' => 30,
                ]);
                $raw = $response->getBody()->getContents();
                return $this->interpretChatReply($this->extractReply($raw), $raw, $response->getStatusCode(), $start);
            }

            // claude
            $baseUrl = (string) ($config['baseUrl'] ?? 'https://api.anthropic.com/v1');
            $error = $this->assertAllowedUrl($baseUrl);
            if ($error !== null) {
                return ['ok' => false, 'latencyMs' => 0, 'reply' => '', 'error' => "Base URL 无效: {$error}"];
            }

            $response = $this->httpClient->post("{$baseUrl}/messages", [
                'headers' => [
                    'x-api-key' => (string) ($config['apiKey'] ?? ''),
                    'anthropic-version' => '2023-06-01',
                ],
                'json' => [
                    'model' => (string) ($config['model'] ?? '') ?: 'claude-3-5-haiku-latest',
                    'max_tokens' => 32,
                    'messages' => [['role' => 'user', 'content' => $message]],
                ],
                'http_errors' => false,
                'timeout' => 30,
            ]);
            $raw = $response->getBody()->getContents();
            return $this->interpretChatReply($this->extractReply($raw), $raw, $response->getStatusCode(), $start);
        } catch (GuzzleException $e) {
            $latencyMs = (int) round((microtime(true) - $start) * 1000);

            return ['ok' => false, 'latencyMs' => $latencyMs, 'reply' => '', 'error' => '连接失败: ' . mb_substr($e->getMessage(), 0, 200)];
        }
    }

    private function extractReply(string $raw): string
    {
        $body = json_decode($raw, true);
        if (! is_array($body)) {
            return '';
        }

        return (string) ($body['choices'][0]['message']['content']
            ?? $body['choices'][0]['text']
            ?? $body['content'][0]['text']
            ?? $body['content']
            ?? $body['response']
            ?? $body['output_text']
            ?? '');
    }

    private function interpretChatReply(string $reply, string $raw, int $status, float $start): array
    {
        $latencyMs = (int) round((microtime(true) - $start) * 1000);
        $reply = trim($reply);

        if ($status === 401 || $status === 403) {
            return ['ok' => false, 'latencyMs' => $latencyMs, 'reply' => '', 'error' => "认证失败（API Key 无效或无权限），HTTP {$status}"];
        }
        if ($status >= 400) {
            return ['ok' => false, 'latencyMs' => $latencyMs, 'reply' => '', 'error' => "请求被拒绝，HTTP {$status}: " . mb_substr($raw, 0, 200)];
        }
        if ($reply === '') {
            return ['ok' => false, 'latencyMs' => $latencyMs, 'reply' => '', 'error' => '模型没有返回内容，原始响应: ' . mb_substr($raw, 0, 300)];
        }

        $usedModel = (bool) preg_match('/A\s*B\s*C/i', $reply);

        return ['ok' => true, 'latencyMs' => $latencyMs, 'reply' => mb_substr($reply, 0, 200), 'followedInstruction' => $usedModel];
    }

    /** 从 OpenAI 兼容的 /models 响应中提取模型 id 列表 */
    private function extractModelIds(string $body): array
    {
        $decoded = json_decode($body, true);
        $rows = [];
        if (is_array($decoded) && array_is_list($decoded)) {
            $rows = $decoded;
        } elseif (is_array($decoded) && isset($decoded['data']) && is_array($decoded['data'])) {
            $rows = $decoded['data'];
        }

        $models = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['id'])) {
                $models[] = (string) $row['id'];
            } elseif (is_string($row)) {
                $models[] = $row;
            }
        }
        sort($models);

        return $models;
    }

    /** @return array{ok: bool, latencyMs: int, models: array<int, string>, error?: string} */
    private function interpretModelList($response, float $start): array
    {
        $latencyMs = (int) round((microtime(true) - $start) * 1000);
        $status = $response->getStatusCode();
        $body = json_decode($response->getBody()->getContents(), true);

        if ($status >= 200 && $status < 300) {
            $models = [];
            foreach ((array) ($body['data'] ?? []) as $model) {
                if (isset($model['id'])) {
                    $models[] = (string) $model['id'];
                }
            }
            sort($models);

            return ['ok' => true, 'latencyMs' => $latencyMs, 'models' => $models];
        }

        if (in_array($status, [401, 403], true)) {
            return ['ok' => false, 'latencyMs' => $latencyMs, 'models' => [], 'error' => "认证失败（API Key 无效或无权限），HTTP {$status}"];
        }

        return ['ok' => false, 'latencyMs' => $latencyMs, 'models' => [], 'error' => "HTTP {$status}"];
    }

    /**
     * URL 出网校验：baseUrl / endpoint 来自用户输入，拒绝内网与保留地址，防 SSRF。
     */
    private function assertAllowedUrl(string $url): ?string
    {
        if (! preg_match('~^https?://~i', $url)) {
            return '仅支持 http/https 地址';
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return '地址缺少主机名';
        }

        if ($host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')) {
            return '不允许访问内网地址';
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
            if (filter_var($host, FILTER_VALIDATE_IP, $flags) === false) {
                return '不允许访问内网/保留 IP 地址';
            }
        }

        return null;
    }

    /**
     * 使用 OpenAI API
     */
    private function analyzeWithOpenAI(string $prompt, array $config): array
    {
        $apiKey = $config['apiKey'] ?? '';
        $baseUrl = $config['baseUrl'] ?? 'https://api.openai.com/v1';
        $model = $config['model'] ?? 'gpt-4';

        if (empty($apiKey)) {
            return ['error' => 'OpenAI API Key 未配置'];
        }

        $baseUrlError = $this->assertAllowedUrl($baseUrl);
        if ($baseUrlError !== null) {
            return ['error' => "Base URL 无效: {$baseUrlError}"];
        }

        try {
            $response = $this->httpClient->post("{$baseUrl}/chat/completions", [
                'headers' => [
                    'Authorization' => "Bearer {$apiKey}",
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $this->getSystemPrompt(),
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                    'temperature' => 0.7,
                    'max_tokens' => 4000,
                ],
                'timeout' => 60,
            ]);

            $raw = $response->getBody()->getContents();
            $body = json_decode($raw, true);
            $content = $body['choices'][0]['message']['content'] ?? $body['choices'][0]['text'] ?? '';

            if (trim((string) $content) === '') {
                return ['error' => 'AI 返回内容为空，原始响应: ' . mb_substr($raw, 0, 300)];
            }

            return [
                'success' => true,
                'content' => $content,
                'usage' => $body['usage'] ?? [],
            ];
        } catch (GuzzleException $e) {
            return [
                'error' => 'AI 分析请求失败: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * 使用 Claude API (Anthropic)
     */
    private function analyzeWithClaude(string $prompt, array $config): array
    {
        $apiKey = $config['apiKey'] ?? '';
        $baseUrl = $config['baseUrl'] ?? 'https://api.anthropic.com/v1';
        $model = $config['model'] ?? 'claude-opus-4-8';

        if (empty($apiKey)) {
            return ['error' => 'Claude API Key 未配置'];
        }

        $baseUrlError = $this->assertAllowedUrl($baseUrl);
        if ($baseUrlError !== null) {
            return ['error' => "Base URL 无效: {$baseUrlError}"];
        }

        try {
            $response = $this->httpClient->post("{$baseUrl}/messages", [
                'headers' => [
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $model,
                    'max_tokens' => 4000,
                    'system' => $this->getSystemPrompt(),
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                ],
                'timeout' => 60,
            ]);

            $raw = $response->getBody()->getContents();
            $body = json_decode($raw, true);
            $content = $body['content'][0]['text'] ?? $body['content'][0]['content'] ?? '';

            if (trim((string) $content) === '') {
                return ['error' => 'AI 返回内容为空，原始响应: ' . mb_substr($raw, 0, 300)];
            }

            return [
                'success' => true,
                'content' => $content,
                'usage' => $body['usage'] ?? [],
            ];
        } catch (GuzzleException $e) {
            return [
                'error' => 'AI 分析请求失败: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * 使用自定义 API
     */
    private function analyzeWithCustom(string $prompt, array $config): array
    {
        $endpoint = $config['endpoint'] ?? '';
        $apiKey = $config['apiKey'] ?? '';

        if (empty($endpoint)) {
            return ['error' => '自定义 API 端点未配置'];
        }

        $endpointError = $this->assertAllowedUrl($endpoint);
        if ($endpointError !== null) {
            return ['error' => "API 端点无效: {$endpointError}"];
        }

        try {
            $headers = ['Content-Type' => 'application/json'];

            if (! empty($apiKey)) {
                $headers['Authorization'] = "Bearer {$apiKey}";
            }

            // OpenAI 兼容端点（/chat/completions 结尾）走标准 chat 格式，其余保持旧格式
            $isChatCompletions = (bool) preg_match('~/chat/completions/?$~i', $endpoint);
            if ($isChatCompletions) {
                $payload = [
                    'model' => (string) ($config['model'] ?? '') ?: 'gpt-4o-mini',
                    'messages' => [
                        ['role' => 'system', 'content' => $this->getSystemPrompt()],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0.7,
                    'max_tokens' => 4000,
                ];
            } else {
                $payload = [
                    'system' => $this->getSystemPrompt(),
                    'prompt' => $prompt,
                    'student_code' => '',
                ];
            }

            $response = $this->httpClient->post($endpoint, [
                'headers' => $headers,
                'json' => $payload,
                'timeout' => 120,
            ]);

            $raw = $response->getBody()->getContents();
            $body = json_decode($raw, true);
            $content = $body['choices'][0]['message']['content']
                ?? $body['choices'][0]['text']
                ?? $body['content']
                ?? $body['response']
                ?? $body['output_text']
                ?? '';

            if (trim((string) $content) === '') {
                return ['error' => 'AI 返回内容为空，原始响应: ' . mb_substr($raw, 0, 300)];
            }

            return [
                'success' => true,
                'content' => $content,
                'usage' => $body['usage'] ?? [],
            ];
        } catch (GuzzleException $e) {
            return [
                'error' => 'AI 分析请求失败: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * 构建系统提示词
     */
    private function getSystemPrompt(): string
    {
        return <<<'PROMPT'
你是一个专业的考研政治错题分析专家。你的任务是：

1. 分析考生的错题，找出知识漏洞、判断漏洞和做题动作漏洞
2. 回到教材、真题和时政，给出可背的结论式短句
3. 提供具体的下一步复习动作和回访验收方式
4. 不要机械复述题目，而是要归纳错因并给出举一反三的建议

请严格遵循以下原则：
- 只分析当前考生的数据，不与其他考生比较
- 不修改上游教材和题库资料
- 真题原文必须可核验，不得编造
- 给出的复习建议必须具体可执行

输出格式为 Markdown，包含：
- 考点结论（结论式短句、关键词加粗、表格对照）
- 错因分析（归纳 3-5 类，不写成流水账）
- 真题怎么考（粘贴可核验原文）
- 下次怎么做（最重要的具体动作）
PROMPT;
    }

    /**
     * 构建分析提示词
     */
    private function buildPrompt(MistakeStudent $student): string
    {
        $items = $student->items()->with('student')->get();

        $itemsText = $items->map(function ($item) {
            return sprintf(
                "## %s - %s\n\n**题干**: %s\n\n**我的答案**: %s\n**正确答案**: %s\n**错误类型**: %s\n\n",
                $item->chapter,
                $item->source_no,
                $item->stem,
                $item->my_answer,
                $item->correct_answer,
                $item->error_type
            );
        })->implode("\n");

        return <<<PROMPT
请使用考研政治错题分析 Skill。

考生：{$student->code}
考生名称：{$student->name}
错题数量：{$items->count()}

# 错题清单

{$itemsText}

请分析以上错题，给出：
1. 主要的知识漏洞和错因归纳
2. 相关考点的结论式总结
3. 具体的复习建议和验收方式

注意：
- 这是 {$student->name} 的个人错题分析
- 请按模块和章节组织内容
- 给出的建议要具体可执行
- 不要编造真题内容
PROMPT;
    }

    /**
     * 用户上传 Markdown 场景的提示词（内容由前端传入，服务端只做包装）
     */
    private function buildMarkdownPrompt(MistakeStudent $student, string $markdown): string
    {
        return <<<PROMPT
请使用考研政治错题分析 Skill。

考生：{$student->code}
考生名称：{$student->name}

# 错题清单（用户上传的 Markdown）

{$markdown}

请分析以上错题，给出：
1. 主要的知识漏洞和错因归纳
2. 相关考点的结论式总结
3. 具体的复习建议和验收方式

注意：
- 请按模块和章节组织内容
- 给出的建议要具体可执行
- 不要编造真题内容
PROMPT;
    }

    /**
     * 获取默认配置
     */
    public function getDefaultConfig(): array
    {
        return [
            'provider' => 'openai',
            'apiKey' => '',
            'baseUrl' => 'https://api.openai.com/v1',
            'model' => 'gpt-4',
        ];
    }

    /**
     * 验证配置
     */
    public function validateConfig(array $config): bool
    {
        $provider = $config['provider'] ?? '';

        if (! in_array($provider, ['openai', 'claude', 'custom'])) {
            return false;
        }

        if (empty($config['apiKey']) && $provider !== 'custom') {
            return false;
        }

        if ($provider === 'custom' && empty($config['endpoint'])) {
            return false;
        }

        return true;
    }
}
