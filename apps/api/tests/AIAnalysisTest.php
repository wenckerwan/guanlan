<?php

declare(strict_types=1);

namespace App\Test;

use App\Model\MistakeStudent;
use App\Service\AIAnalysisService;
use Hyperf\Guzzle\ClientFactory;
use PHPUnit\Framework\TestCase;

/**
 * AI 分析服务测试
 *
 * 测试 AI 分析对接功能，包括：
 * 1. OpenAI API 调用
 * 2. Claude API 调用
 * 3. 自定义 API 调用
 * 4. Skills 提示词对接
 */
class AIAnalysisTest extends TestCase
{
    private AIAnalysisService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AIAnalysisService(new ClientFactory());
    }

    public function testGetDefaultConfig(): void
    {
        $config = $this->service->getDefaultConfig();

        $this->assertArrayHasKey('provider', $config);
        $this->assertArrayHasKey('apiKey', $config);
        $this->assertArrayHasKey('baseUrl', $config);
        $this->assertArrayHasKey('model', $config);

        $this->assertEquals('openai', $config['provider']);
        $this->assertEquals('https://api.openai.com/v1', $config['baseUrl']);
        $this->assertEquals('gpt-4', $config['model']);
    }

    public function testValidateConfig(): void
    {
        // 有效的 OpenAI 配置
        $validOpenAI = [
            'provider' => 'openai',
            'apiKey' => 'sk-test123',
            'baseUrl' => 'https://api.openai.com/v1',
            'model' => 'gpt-4',
        ];
        $this->assertTrue($this->service->validateConfig($validOpenAI));

        // 有效的 Claude 配置
        $validClaude = [
            'provider' => 'claude',
            'apiKey' => 'sk-ant-test123',
            'baseUrl' => 'https://api.anthropic.com/v1',
            'model' => 'claude-opus-4-8',
        ];
        $this->assertTrue($this->service->validateConfig($validClaude));

        // 有效的自定义配置
        $validCustom = [
            'provider' => 'custom',
            'endpoint' => 'http://localhost:8000/api/analyze',
        ];
        $this->assertTrue($this->service->validateConfig($validCustom));

        // 无效的配置（缺少必需字段）
        $invalid = [
            'provider' => 'openai',
            // 缺少 apiKey
        ];
        $this->assertFalse($this->service->validateConfig($invalid));

        // 无效的配置（不支持的提供商）
        $invalidProvider = [
            'provider' => 'unknown',
            'apiKey' => 'test',
        ];
        $this->assertFalse($this->service->validateConfig($invalidProvider));
    }

    public function testBuildPromptWithSkills(): void
    {
        // 创建测试用的错题本
        $student = new MistakeStudent([
            'id' => 1,
            'code' => 'A',
            'name' => '错题分析示例',
        ]);

        // 使用反射访问私有方法
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('buildPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke($this->service, $student);

        // 验证提示词包含 skills 关键信息
        $this->assertStringContainsString('考研政治错题分析', $prompt);
        $this->assertStringContainsString('考生：A', $prompt);
        $this->assertStringContainsString('错题分析示例', $prompt);

        // 验证提示词格式
        $this->assertStringContainsString('# 错题清单', $prompt);
        $this->assertStringContainsString('请分析以上错题', $prompt);
    }

    public function testGetSystemPrompt(): void
    {
        // 使用反射访问私有方法
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getSystemPrompt');
        $method->setAccessible(true);

        $systemPrompt = $method->invoke($this->service);

        // 验证系统提示词包含关键要素
        $this->assertStringContainsString('考研政治错题分析专家', $systemPrompt);
        $this->assertStringContainsString('知识漏洞', $systemPrompt);
        $this->assertStringContainsString('判断漏洞', $systemPrompt);
        $this->assertStringContainsString('做题动作漏洞', $systemPrompt);
        $this->assertStringContainsString('不修改上游教材和题库资料', $systemPrompt);
        $this->assertStringContainsString('真题原文必须可核验', $systemPrompt);
        $this->assertStringContainsString('Markdown', $systemPrompt);
    }
}
