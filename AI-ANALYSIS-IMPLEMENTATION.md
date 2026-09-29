# AI 错题分析功能实现文档

## 📋 功能概述

为「观澜｜考研政治知识库」项目实现了完整的 AI 错题分析前端功能，支持用户上传错题 Markdown 文件，配置自定义 AI API，并使用内嵌的错题分析 Skills 自动生成专业的错题分析报告。

---

## ✅ 已实现的功能

### 1. **AI 分析页面** 
**文件**: [apps/web/pages/mistakes/[code]/analyze.vue](apps/web/pages/mistakes/[code]/analyze.vue)

**核心功能**:
- ✅ 错题 Markdown 文件上传
- ✅ AI API 配置管理（支持 3 种提供商）
- ✅ 内嵌专业的错题分析 System Prompt
- ✅ 实时 AI 分析调用
- ✅ Markdown 格式分析结果展示
- ✅ 分析报告下载
- ✅ 分析结果保存到服务器

### 2. **AI 提供商支持**

#### OpenAI (GPT-4)
- Base URL: `https://api.openai.com/v1`
- 默认模型: `gpt-4`
- 支持自定义 Base URL（兼容第三方代理）

#### Claude (Anthropic)
- Base URL: `https://api.anthropic.com/v1`
- 默认模型: `claude-opus-4-8`
- 使用最新的 Messages API

#### 自定义 API
- 完全自定义的 API 端点
- 可选的 API Key 认证
- 灵活的请求/响应格式

### 3. **内嵌的错题分析 Skills**

专业的 System Prompt，包含：
- 分析知识漏洞、判断漏洞和做题动作漏洞
- 给出可背的结论式短句
- 提供具体的下一步复习动作和回访验收方式
- 不机械复述题目，而是归纳错因并举一反三

**输出格式**（Markdown）:
1. **考点结论** - 结论式短句、关键词加粗、表格对照
2. **错因分析** - 归纳 3-5 类，不写成流水账
3. **真题怎么考** - 粘贴可核验原文
4. **下次怎么做** - 最重要的具体动作

### 4. **UI 组件**

- **文件上传区域** - 拖拽式设计，支持 .md 文件
- **AI 配置弹窗** - 三种提供商切换，表单验证
- **分析状态指示** - 加载动画，错误提示
- **结果展示区** - Markdown 渲染，代码高亮
- **操作按钮** - 下载报告，保存到服务器

### 5. **集成到现有错题页面**

在错题详情页（[apps/web/pages/mistakes/[code].vue](apps/web/pages/mistakes/[code].vue)）添加了：
- ✅ 「AI 分析」按钮（使用 Sparkles 图标）
- ✅ 导入 Sparkles 图标组件
- ✅ 路由链接到 AI 分析页面

---

## 🎯 使用流程

### 用户操作流程

```
1. 访问错题详情页
   ↓
2. 点击「AI 分析」按钮
   ↓
3. 配置 AI API（首次使用）
   - 选择提供商（OpenAI/Claude/自定义）
   - 输入 API Key
   - 保存配置（存储在浏览器 localStorage）
   ↓
4. 上传错题 Markdown 文件
   ↓
5. 点击「开始 AI 分析」
   ↓
6. 查看分析报告
   ↓
7. 下载或保存报告
```

### 错题文件格式示例

```markdown
## 马原 - 第1题

**题干**: 下列关于实践的表述正确的是（  ）

A. 实践是人类能动地改造世界的客观物质性活动
B. 实践是社会历史性的活动
C. 实践是人类有目的的自觉活动
D. 实践是人类的存在方式

**我的答案**: A
**正确答案**: ABCD
**错误类型**: 漏选
```

---

## 🛠️ 技术实现

### 前端技术栈
- **框架**: Vue 3 + Nuxt 3
- **UI 组件**: lucide-vue-next（图标）
- **Markdown 渲染**: $md.render（Nuxt 内置）
- **样式**: CSS Scoped + CSS Variables
- **状态管理**: Vue Composition API（ref, reactive）
- **存储**: localStorage（配置持久化）

### API 调用实现

#### OpenAI API
```javascript
fetch('https://api.openai.com/v1/chat/completions', {
  headers: {
    'Authorization': `Bearer ${apiKey}`,
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    model: 'gpt-4',
    messages: [
      { role: 'system', content: ANALYSIS_SKILL },
      { role: 'user', content: prompt },
    ],
    temperature: 0.7,
    max_tokens: 4000,
  }),
})
```

#### Claude API
```javascript
fetch('https://api.anthropic.com/v1/messages', {
  headers: {
    'x-api-key': apiKey,
    'anthropic-version': '2023-06-01',
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    model: 'claude-opus-4-8',
    max_tokens: 4000,
    system: ANALYSIS_SKILL,
    messages: [
      { role: 'user', content: prompt },
    ],
  }),
})
```

#### 自定义 API
```javascript
fetch(endpoint, {
  headers: {
    'Authorization': `Bearer ${apiKey}`,
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    system: ANALYSIS_SKILL,
    prompt,
    student_code: code,
  }),
})
```

### 数据流

```
用户上传文件
  ↓
FileReader 读取内容 → markdown.value
  ↓
用户点击分析
  ↓
构建提示词（System Prompt + 用户提示词 + 错题内容）
  ↓
调用 AI API（根据配置选择提供商）
  ↓
解析响应 → analysisResult.value
  ↓
Markdown 渲染展示
```

---

## 📁 文件结构

```
apps/web/pages/mistakes/
├── index.vue                     # 错题列表页
├── [code].vue                    # 错题详情页（已添加 AI 分析按钮）
└── [code]/
    ├── [id].vue                  # 提分手册详情页
    ├── review.vue                # 复习概览页
    └── analyze.vue               # AI 分析页面（新增）✨
```

---

## 🎨 UI 设计

### 配色方案
- **主色**: `var(--primary, #3b82f6)` - 蓝色
- **背景**: `var(--card-bg, #fff)` - 白色卡片
- **边框**: `var(--border, #e5e7eb)` - 浅灰色
- **文本**: `var(--text-primary, #111827)` - 深灰色
- **次要文本**: `var(--text-secondary, #6b7280)` - 中灰色

### 响应式设计
- 移动端优先
- 弹窗宽度：`max-width: 500px`
- 卡片圆角：`12px`
- 按钮圆角：`8px`

### 交互反馈
- 按钮悬停：`transform: translateY(-1px)`
- 加载状态：禁用按钮 + 文案变化
- 错误提示：红色横幅
- 成功保存：系统提示

---

## 🔐 安全性考虑

### 1. API Key 存储
- ✅ 使用 `type="password"` 输入框
- ✅ 存储在浏览器 localStorage（仅限客户端）
- ✅ 不通过服务器传输
- ⚠️ 警告：localStorage 不加密，请勿在公共设备使用

### 2. 文件上传验证
- ✅ 仅允许 `.md` 文件
- ✅ 客户端读取，不上传到服务器
- ✅ FileReader API 安全读取

### 3. API 调用
- ✅ HTTPS 加密传输
- ✅ 错误处理和超时控制
- ✅ 敏感信息不记录到日志

---

## 📊 功能对比

| 功能 | 后端 API 分析 | 前端 AI 分析 ✨ |
|-----|-------------|----------------|
| **触发方式** | 管理员手动触发 | 用户自助触发 |
| **API 配置** | 服务器端配置 | 用户端配置 |
| **数据来源** | 数据库错题 | 上传 Markdown 文件 |
| **分析速度** | 取决于服务器队列 | 即时分析 |
| **结果存储** | 自动存入数据库 | 可选保存 |
| **适用场景** | 批量分析、定时分析 | 即时分析、个性化配置 |

---

## 🚀 部署步骤

### 1. 提交代码到 Git
```bash
git add apps/web/pages/mistakes/
git commit -m "feat: 实现 AI 错题分析前端功能

- 新增 AI 分析页面 (analyze.vue)
- 支持 OpenAI、Claude、自定义 API
- 内嵌专业错题分析 Skills
- Markdown 文件上传和分析
- 分析报告展示和下载
- 集成到错题详情页

```

### 2. 推送到远程仓库
```bash
git push origin v0.1-dev.7
```

### 3. 服务器部署
```bash
ssh root-189 "cd /www/wwwroot/guanlan && git pull origin v0.1-dev.7"
ssh root-189 "docker restart guanlan-web-1"
```

### 4. 验证部署
```bash
# 检查容器状态
ssh root-189 "docker ps --filter 'name=guanlan-web'"

# 测试页面访问
curl http://你的域名/mistakes/A/analyze
```

---

## 📝 使用文档

### 配置 OpenAI API

1. 获取 API Key：访问 [OpenAI Platform](https://platform.openai.com/api-keys)
2. 在 AI 分析页面点击「配置 AI」
3. 选择「OpenAI (GPT-4)」
4. 输入 API Key
5. （可选）自定义 Base URL（用于第三方代理）
6. 点击「保存配置」

### 配置 Claude API

1. 获取 API Key：访问 [Anthropic Console](https://console.anthropic.com/)
2. 在 AI 分析页面点击「配置 AI」
3. 选择「Claude (Anthropic)」
4. 输入 API Key（格式：`sk-ant-...`）
5. 点击「保存配置」

### 配置自定义 API

1. 准备你的 API 端点（支持 POST 请求）
2. 在 AI 分析页面点击「配置 AI」
3. 选择「自定义 API」
4. 输入 API Endpoint URL
5. （可选）输入 API Key
6. 点击「保存配置」

**自定义 API 请求格式**:
```json
POST /your-endpoint
Content-Type: application/json
Authorization: Bearer YOUR_API_KEY

{
  "system": "错题分析 System Prompt",
  "prompt": "用户提示词 + 错题内容",
  "student_code": "A"
}
```

**自定义 API 响应格式**:
```json
{
  "content": "Markdown 格式的分析报告",
  "usage": { "tokens": 1234 }
}
```

---

## 🐛 已知问题和限制

### 1. API Key 安全性
- ⚠️ localStorage 不加密，公共设备慎用
- 💡 建议：使用服务器端代理转发 API 请求

### 2. 文件大小限制
- ⚠️ 建议单文件不超过 100 题
- 💡 原因：AI 上下文长度限制

### 3. 分析时间
- ⚠️ 大文件可能需要 30-60 秒
- 💡 建议：添加进度指示器

### 4. 网络问题
- ⚠️ 国内访问 OpenAI 和 Anthropic 可能不稳定
- 💡 解决方案：配置自定义 Base URL 使用代理

---

## 🔮 未来优化方向

### 短期（1-2 周）
1. **批量分析** - 支持一次上传多个错题文件
2. **历史记录** - 保存和查看历史分析报告
3. **模板管理** - 自定义分析 System Prompt
4. **进度指示** - 实时显示分析进度

### 中期（1 个月）
1. **服务器代理** - 后端转发 API 请求，提高安全性
2. **分析对比** - 对比不同时间的分析结果
3. **协作分享** - 生成分享链接，导出 PDF
4. **智能推荐** - 根据分析结果推荐学习资源

### 长期（3 个月+）
1. **多模态分析** - 支持图片题目（OCR）
2. **语音输入** - 语音描述错题
3. **实时协作** - 师生共同查看和批注
4. **知识图谱** - 构建错题知识网络

---

## 📚 相关文档

1. [OpenAI API 文档](https://platform.openai.com/docs/api-reference)
2. [Anthropic API 文档](https://docs.anthropic.com/claude/reference)
3. [Vue 3 Composition API](https://vuejs.org/api/composition-api-setup.html)
4. [Nuxt 3 文档](https://nuxt.com/docs)

---

## 🎉 总结

✅ **功能完整**: AI 分析页面已完整实现  
✅ **三种 API**: 支持 OpenAI、Claude、自定义  
✅ **专业 Skills**: 内嵌考研政治错题分析专家提示词  
✅ **用户友好**: 直观的 UI，完善的错误处理  
✅ **已集成**: 添加到错题详情页入口  
✅ **待部署**: 代码已准备好，等待推送到服务器  

**AI 错题分析前端功能已完全实现，准备部署！** 🚀

---

**实现人员**: 管理员  
**文档版本**: v1.0 - 2026-09-28  
**文件路径**: [apps/web/pages/mistakes/[code]/analyze.vue](apps/web/pages/mistakes/[code]/analyze.vue)
