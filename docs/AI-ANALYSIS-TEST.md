# AI 分析测试指南

本文档说明如何测试 AI 分析功能并验证与 skills 目录的对接。

## 环境准备

### 1. 安装依赖

```bash
cd guanlan/apps/api
composer install
```

### 2. 配置数据库（如果尚未配置）

```bash
# 复制环境配置
cp .env.example .env

# 编辑 .env，配置数据库连接
# DB_HOST=localhost
# DB_PORT=3306
# DB_DATABASE=kaoyan_politics
# DB_USERNAME=root
# DB_PASSWORD=your_password
```

### 3. 执行数据库迁移

```bash
php bin/hyperf.php migrate
```

## 测试方式

### 方式一：使用测试脚本（推荐）

测试脚本位于 `apps/api/test-ai-analysis.php`，提供完整的测试流程。

#### 测试 OpenAI

```bash
cd guanlan/apps/api
php test-ai-analysis.php openai sk-your-api-key
```

#### 测试 Claude

```bash
php test-ai-analysis.php claude sk-ant-your-api-key
```

#### 测试自定义 API

```bash
# 不需要 API Key
php test-ai-analysis.php custom http://localhost:8000/api/analyze

# 需要 API Key
php test-ai-analysis.php custom http://localhost:8000/api/analyze your-api-key
```

#### 测试输出示例

```
=== AI 分析测试脚本 ===

1. 配置验证
-------------------
提供商: openai
配置: {
    "provider": "openai",
    "apiKey": "sk-xxx",
    "baseUrl": "https://api.openai.com/v1",
    "model": "gpt-4"
}
配置有效: ✓ 是

2. 创建测试数据
-------------------
考生代号: A
考生名称: 错题分析示例
错题数量: 2

3. 测试系统提示词
-------------------
系统提示词长度: 856 字符
包含关键词检查:
  - 考研政治错题分析专家: ✓
  - 知识漏洞: ✓
  - 判断漏洞: ✓
  - 做题动作漏洞: ✓
  - 不修改上游教材: ✓
  - 真题原文必须可核验: ✓
  - Markdown: ✓

4. 测试用户提示词
-------------------
用户提示词长度: 1234 字符
包含内容检查:
  - 考生： ✓
  - 错题数量： ✓
  - # 错题清单 ✓
  - 请分析以上错题 ✓
  - 第四章 ✓

5. 调用 AI 分析
-------------------
正在请求 AI 分析，这可能需要 10-30 秒...
✓ 分析成功

返回内容长度: 3456 字符
Token 使用情况:
{
    "prompt_tokens": 1234,
    "completion_tokens": 890,
    "total_tokens": 2124
}

6. 验证输出格式
-------------------
  - 包含 Markdown 标题: ✓
  - 包含「考点结论」: ✓
  - 包含「错因分析」: ✓
  - 包含「真题」: ✓
  - 包含「下次」或「复习」: ✓
  - 包含加粗标记: ✓

✓ AI 分析测试完成！
```

### 方式二：通过 API 接口测试

#### 1. 启动 API 服务

```bash
cd guanlan/apps/api
php bin/hyperf.php start
```

#### 2. 获取 AI 配置模板

```bash
curl http://localhost:9501/api/v1/mistakes/ai-config
```

响应示例：

```json
{
  "code": 200,
  "data": {
    "providers": [
      {
        "id": "openai",
        "name": "OpenAI",
        "fields": [
          {"key": "apiKey", "label": "API Key", "required": true},
          {"key": "baseUrl", "label": "Base URL", "required": false, "default": "https://api.openai.com/v1"},
          {"key": "model", "label": "Model", "required": false, "default": "gpt-4"}
        ]
      },
      {
        "id": "claude",
        "name": "Claude (Anthropic)",
        "fields": [
          {"key": "apiKey", "label": "API Key", "required": true},
          {"key": "baseUrl", "label": "Base URL", "required": false, "default": "https://api.anthropic.com/v1"},
          {"key": "model", "label": "Model", "required": false, "default": "claude-opus-4-8"}
        ]
      }
    ]
  }
}
```

#### 3. 请求 AI 分析

```bash
curl -X POST http://localhost:9501/api/v1/mistakes/students/A/ai-analysis \
  -H "Authorization: Bearer your-jwt-token" \
  -H "Content-Type: application/json" \
  -d '{
    "provider": "openai",
    "apiKey": "sk-your-api-key",
    "baseUrl": "https://api.openai.com/v1",
    "model": "gpt-4"
  }'
```

### 方式三：使用 PHPUnit 单元测试

```bash
cd guanlan/apps/api
./vendor/bin/phpunit tests/AIAnalysisTest.php
```

## Skills 对接验证

### 验证清单

AI 分析服务已与 skills 目录对接，系统提示词包含以下关键要素：

- [x] 考研政治错题分析专家身份
- [x] 四类可执行结论（知识漏洞、判断漏洞、做题动作漏洞、复习动作）
- [x] 分析原则（不修改上游教材、真题原文可核验、考生隔离）
- [x] 输出格式（Markdown、考点结论、错因分析、真题怎么考、下次怎么做）

### 提示词来源

系统提示词位于 [AIAnalysisService.php:151](apps/api/src/Service/AIAnalysisService.php:151)，基于以下文档编写：

- `/d/code_files/kaoyan-politics-mistake-analysis/kaoyan-politics-mistake-analysis/SKILL.md`
- `/d/code_files/kaoyan-politics-mistake-analysis/README.md`

### 验证步骤

1. **检查系统提示词**
   ```bash
   grep -A 20 "getSystemPrompt" apps/api/src/Service/AIAnalysisService.php
   ```

2. **检查用户提示词格式**
   ```bash
   grep -A 30 "buildPrompt" apps/api/src/Service/AIAnalysisService.php
   ```

3. **运行测试脚本验证**
   ```bash
   php test-ai-analysis.php openai sk-test-key
   ```
   查看第 3、4 步的输出，确认包含所有关键词。

## 常见问题

### Q1: API Key 无效

**错误信息**：
```
AI 分析请求失败: Client error: 401 Unauthorized
```

**解决方法**：
- 检查 API Key 是否正确
- 确认 API Key 有足够的额度
- 验证 Base URL 是否正确

### Q2: 网络连接失败

**错误信息**：
```
AI 分析请求失败: cURL error 7: Failed to connect
```

**解决方法**：
- 检查网络连接
- 确认是否需要代理
- 尝试使用国内 API 中转服务

### Q3: 超时

**错误信息**：
```
AI 分析请求失败: Operation timed out
```

**解决方法**：
- 增加超时时间（当前设置为 60 秒）
- 减少错题数量
- 更换更快的 API 端点

### Q4: Token 超限

**错误信息**：
```
AI 分析请求失败: maximum context length exceeded
```

**解决方法**：
- 减少错题数量
- 简化题目内容
- 使用支持更长上下文的模型（如 gpt-4-32k、claude-opus-4-8）

## 下一步

测试通过后，可以：

1. 在前端添加 AI 分析配置界面
2. 为用户提供保存 AI 配置的功能
3. 实现批量分析多个错题本
4. 将生成的分析保存到 `mistake_profiles` 表

## 相关文件

- [AIAnalysisService.php](apps/api/src/Service/AIAnalysisService.php) - AI 分析服务
- [AIAnalysisTest.php](apps/api/tests/AIAnalysisTest.php) - 单元测试
- [test-ai-analysis.php](apps/api/test-ai-analysis.php) - 命令行测试脚本
- [MistakeController.php:139](apps/api/src/Controller/MistakeController.php:139) - AI 分析 API 接口
- [SKILL.md](/d/code_files/kaoyan-politics-mistake-analysis/kaoyan-politics-mistake-analysis/SKILL.md) - Skills 文档
