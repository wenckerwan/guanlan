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
     * 调用 AI 分析接口
     */
    public function analyze(MistakeStudent $student, array $config): array
    {
        $provider = $config['provider'] ?? 'openai';

        return match ($provider) {
            'openai' => $this->analyzeWithOpenAI($student, $config),
            'claude' => $this->analyzeWithClaude($student, $config),
            'custom' => $this->analyzeWithCustom($student, $config),
            default => throw new \InvalidArgumentException("不支持的 AI 提供商: {$provider}"),
        };
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
    private function analyzeWithOpenAI(MistakeStudent $student, array $config): array
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

        $prompt = $this->buildPrompt($student);

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

            $body = json_decode($response->getBody()->getContents(), true);
            $content = $body['choices'][0]['message']['content'] ?? '';

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
    private function analyzeWithClaude(MistakeStudent $student, array $config): array
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

        $prompt = $this->buildPrompt($student);

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

            $body = json_decode($response->getBody()->getContents(), true);
            $content = $body['content'][0]['text'] ?? '';

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
    private function analyzeWithCustom(MistakeStudent $student, array $config): array
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

        $prompt = $this->buildPrompt($student);

        try {
            $headers = ['Content-Type' => 'application/json'];

            if (! empty($apiKey)) {
                $headers['Authorization'] = "Bearer {$apiKey}";
            }

            $response = $this->httpClient->post($endpoint, [
                'headers' => $headers,
                'json' => [
                    'system' => $this->getSystemPrompt(),
                    'prompt' => $prompt,
                    'student_code' => $student->code,
                ],
                'timeout' => 60,
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            return [
                'success' => true,
                'content' => $body['content'] ?? $body['response'] ?? '',
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
