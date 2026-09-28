#!/usr/bin/env php
<?php

/**
 * AI 分析测试脚本
 *
 * 用于测试 AI 分析功能，包括：
 * 1. 配置验证
 * 2. API 连接测试
 * 3. Skills 提示词对接
 * 4. 完整分析流程
 *
 * 使用方法：
 * php test-ai-analysis.php [provider] [api-key]
 *
 * 示例：
 * php test-ai-analysis.php openai sk-xxx
 * php test-ai-analysis.php claude sk-ant-xxx
 * php test-ai-analysis.php custom http://localhost:8000/api/analyze
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use App\Model\MistakeItem;
use App\Model\MistakeStudent;
use App\Service\AIAnalysisService;
use Hyperf\Guzzle\ClientFactory;

// 解析命令行参数
$provider = $argv[1] ?? 'openai';
$apiKeyOrEndpoint = $argv[2] ?? '';

if (empty($apiKeyOrEndpoint)) {
    echo "错误：请提供 API Key 或端点地址\n";
    echo "使用方法：php test-ai-analysis.php [provider] [api-key]\n";
    exit(1);
}

echo "=== AI 分析测试脚本 ===\n\n";

// 创建服务实例
$service = new AIAnalysisService(new ClientFactory());

// 1. 配置验证
echo "1. 配置验证\n";
echo "-------------------\n";

$config = match ($provider) {
    'openai' => [
        'provider' => 'openai',
        'apiKey' => $apiKeyOrEndpoint,
        'baseUrl' => 'https://api.openai.com/v1',
        'model' => 'gpt-4',
    ],
    'claude' => [
        'provider' => 'claude',
        'apiKey' => $apiKeyOrEndpoint,
        'baseUrl' => 'https://api.anthropic.com/v1',
        'model' => 'claude-opus-4-8',
    ],
    'custom' => [
        'provider' => 'custom',
        'endpoint' => $apiKeyOrEndpoint,
        'apiKey' => $argv[3] ?? '',
    ],
    default => throw new InvalidArgumentException("不支持的提供商: {$provider}"),
};

echo "提供商: {$provider}\n";
echo "配置: " . json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

$isValid = $service->validateConfig($config);
echo "配置有效: " . ($isValid ? '✓ 是' : '✗ 否') . "\n\n";

if (!$isValid) {
    echo "配置无效，请检查参数\n";
    exit(1);
}

// 2. 创建测试数据
echo "2. 创建测试数据\n";
echo "-------------------\n";

$student = new MistakeStudent();
$student->id = 1;
$student->code = 'A';
$student->name = '错题分析示例';

// 创建一些测试错题
$items = [
    [
        'module' => '马原',
        'chapter' => '第四章',
        'source_no' => '3',
        'stem' => '商品是用来交换、能满足人们某种需要的劳动产品，具有使用价值和价值两个因素，是使用价值和价值的矛盾统一体。商品内在的使用价值和价值的矛盾，其完备的外在表现是（ ）',
        'my_answer' => 'B',
        'correct_answer' => 'D',
        'error_type' => '纯错选',
    ],
    [
        'module' => '马原',
        'chapter' => '第四章',
        'source_no' => '8',
        'stem' => '在资本主义生产过程中，生产剩余价值是资本家的目的。剩余价值是在生产过程中生产出来的，表现为（ ）',
        'my_answer' => 'AB',
        'correct_answer' => 'CD',
        'error_type' => '纯错选',
    ],
];

foreach ($items as $data) {
    $item = new MistakeItem();
    foreach ($data as $key => $value) {
        $item->{$key} = $value;
    }
    $student->items->add($item);
}

echo "考生代号: {$student->code}\n";
echo "考生名称: {$student->name}\n";
echo "错题数量: " . $student->items->count() . "\n\n";

// 3. 测试系统提示词
echo "3. 测试系统提示词\n";
echo "-------------------\n";

$reflection = new ReflectionClass($service);
$method = $reflection->getMethod('getSystemPrompt');
$method->setAccessible(true);
$systemPrompt = $method->invoke($service);

echo "系统提示词长度: " . mb_strlen($systemPrompt) . " 字符\n";
echo "包含关键词检查:\n";

$keywords = [
    '考研政治错题分析专家',
    '知识漏洞',
    '判断漏洞',
    '做题动作漏洞',
    '不修改上游教材',
    '真题原文必须可核验',
    'Markdown',
];

foreach ($keywords as $keyword) {
    $found = str_contains($systemPrompt, $keyword);
    echo "  - {$keyword}: " . ($found ? '✓' : '✗') . "\n";
}

echo "\n";

// 4. 测试用户提示词
echo "4. 测试用户提示词\n";
echo "-------------------\n";

$method = $reflection->getMethod('buildPrompt');
$method->setAccessible(true);
$userPrompt = $method->invoke($service, $student);

echo "用户提示词长度: " . mb_strlen($userPrompt) . " 字符\n";
echo "包含内容检查:\n";

$checks = [
    '考生：' => str_contains($userPrompt, '考生：'),
    '错题数量：' => str_contains($userPrompt, '错题数量：'),
    '# 错题清单' => str_contains($userPrompt, '# 错题清单'),
    '请分析以上错题' => str_contains($userPrompt, '请分析以上错题'),
    '第四章' => str_contains($userPrompt, '第四章'),
];

foreach ($checks as $label => $found) {
    echo "  - {$label} " . ($found ? '✓' : '✗') . "\n";
}

echo "\n前 500 字符预览:\n";
echo str_repeat('-', 50) . "\n";
echo mb_substr($userPrompt, 0, 500) . "...\n";
echo str_repeat('-', 50) . "\n\n";

// 5. 调用 AI 分析
echo "5. 调用 AI 分析\n";
echo "-------------------\n";
echo "正在请求 AI 分析，这可能需要 10-30 秒...\n";

try {
    $result = $service->analyze($student, $config);

    if (isset($result['error'])) {
        echo "✗ 分析失败: {$result['error']}\n";
        exit(1);
    }

    echo "✓ 分析成功\n\n";

    echo "返回内容长度: " . mb_strlen($result['content']) . " 字符\n";

    if (isset($result['usage'])) {
        echo "Token 使用情况:\n";
        echo json_encode($result['usage'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }

    echo "\n前 1000 字符预览:\n";
    echo str_repeat('=', 50) . "\n";
    echo mb_substr($result['content'], 0, 1000) . "...\n";
    echo str_repeat('=', 50) . "\n\n";

    // 6. 验证输出格式
    echo "6. 验证输出格式\n";
    echo "-------------------\n";

    $content = $result['content'];

    $formatChecks = [
        '包含 Markdown 标题' => preg_match('/^#+ /m', $content) > 0,
        '包含「考点结论」' => str_contains($content, '考点') || str_contains($content, '结论'),
        '包含「错因分析」' => str_contains($content, '错') && str_contains($content, '分析'),
        '包含「真题」' => str_contains($content, '真题'),
        '包含「下次」或「复习」' => str_contains($content, '下次') || str_contains($content, '复习'),
        '包含加粗标记' => str_contains($content, '**'),
    ];

    foreach ($formatChecks as $label => $passed) {
        echo "  - {$label}: " . ($passed ? '✓' : '✗') . "\n";
    }

    echo "\n✓ AI 分析测试完成！\n";

} catch (Exception $e) {
    echo "✗ 发生错误: " . $e->getMessage() . "\n";
    echo "错误追踪:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
