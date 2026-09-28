<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { ArrowLeft, Upload, Sparkles, Settings, FileText, Download } from 'lucide-vue-next'
import type { MistakeStudent } from '~/types/api'

const route = useRoute()
const code = String(route.params.code)

// 🔧 本地开发模式：绕过认证
const isDev = process.env.NODE_ENV === 'development'
const mockLoggedIn = ref(isDev) // 开发环境模拟已登录

// 获取考生信息
const student = ref<MistakeStudent | null>(null)

// 在客户端加载考生信息
onMounted(async () => {
  if (isDev) {
    // 开发环境使用模拟数据
    student.value = {
      code: code,
      name: '测试考生',
      relation: '本人',
      itemCount: 6,
    } as MistakeStudent
  } else {
    // 生产环境从 API 获取
    const { data } = await useApiFetch<MistakeStudent>(`/mistakes/students/${code}`, null)
    student.value = data.value
  }
})

// AI 配置
const aiConfig = reactive({
  provider: 'custom',
  apiKey: '',
  baseUrl: '',
  model: '',
  endpoint: '',
})

// 加载保存的配置
if (import.meta.client) {
  const saved = localStorage.getItem('ai_analysis_config')
  if (saved) {
    Object.assign(aiConfig, JSON.parse(saved))
  }
}

// 分析状态
const uploadedFile = ref<File | null>(null)
const markdown = ref('')
const analyzing = ref(false)
const analysisResult = ref('')
const analysisError = ref('')
const showConfig = ref(false)

// 内嵌的错题分析 System Prompt
const ANALYSIS_SKILL = `你是一个专业的考研政治错题分析专家。你的任务是：

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
- 下次怎么做（最重要的具体动作）`

// 文件上传处理
function handleFileUpload(event: Event) {
  const target = event.target as HTMLInputElement
  const file = target.files?.[0]
  if (!file) return

  if (!file.name.endsWith('.md')) {
    analysisError.value = '请上传 Markdown (.md) 文件'
    return
  }

  uploadedFile.value = file
  const reader = new FileReader()
  reader.onload = (e) => {
    markdown.value = e.target?.result as string
    analysisError.value = ''
  }
  reader.readAsText(file)
}

// 保存配置
function saveConfig() {
  localStorage.setItem('ai_analysis_config', JSON.stringify(aiConfig))
  showConfig.value = false
}

// 调用 AI 分析
async function analyzeWithAI() {
  if (!markdown.value) {
    analysisError.value = '请先上传错题文件'
    return
  }

  if (!aiConfig.apiKey && !aiConfig.endpoint) {
    analysisError.value = '请先配置 AI API'
    showConfig.value = true
    return
  }

  analyzing.value = true
  analysisError.value = ''
  analysisResult.value = ''

  try {
    // 构建提示词
    const prompt = `请使用考研政治错题分析 Skill。

考生：${student.value?.code || code}
考生名称：${student.value?.name || '未知'}

# 错题清单（来自上传的 Markdown 文件）

${markdown.value}

请分析以上错题，给出：
1. 主要的知识漏洞和错因归纳
2. 相关考点的结论式总结
3. 具体的复习建议和验收方式

注意：
- 这是 ${student.value?.name || '考生'} 的个人错题分析
- 请按模块和章节组织内容
- 给出的建议要具体可执行
- 不要编造真题内容`

    // 调用 AI API
    let result
    if (aiConfig.provider === 'openai') {
      result = await callOpenAI(prompt)
    } else if (aiConfig.provider === 'claude') {
      result = await callClaude(prompt)
    } else {
      result = await callCustomAPI(prompt)
    }

    analysisResult.value = result
  } catch (error: any) {
    analysisError.value = error.message || 'AI 分析失败，请检查配置'
  } finally {
    analyzing.value = false
  }
}

// OpenAI API
async function callOpenAI(prompt: string) {
  const response = await fetch(`${aiConfig.baseUrl || 'https://api.openai.com/v1'}/chat/completions`, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${aiConfig.apiKey}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      model: aiConfig.model || 'gpt-4',
      messages: [
        { role: 'system', content: ANALYSIS_SKILL },
        { role: 'user', content: prompt },
      ],
      temperature: 0.7,
      max_tokens: 4000,
    }),
  })

  if (!response.ok) {
    throw new Error(`OpenAI API 错误: ${response.statusText}`)
  }

  const data = await response.json()
  return data.choices[0].message.content
}

// Claude API
async function callClaude(prompt: string) {
  const response = await fetch(`${aiConfig.baseUrl || 'https://api.anthropic.com/v1'}/messages`, {
    method: 'POST',
    headers: {
      'x-api-key': aiConfig.apiKey,
      'anthropic-version': '2023-06-01',
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      model: aiConfig.model || 'claude-opus-4-8',
      max_tokens: 4000,
      system: ANALYSIS_SKILL,
      messages: [
        { role: 'user', content: prompt },
      ],
    }),
  })

  if (!response.ok) {
    throw new Error(`Claude API 错误: ${response.statusText}`)
  }

  const data = await response.json()
  return data.content[0].text
}

// 自定义 API
async function callCustomAPI(prompt: string) {
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
  }

  if (aiConfig.apiKey) {
    headers['Authorization'] = `Bearer ${aiConfig.apiKey}`
  }

  const response = await fetch(aiConfig.endpoint, {
    method: 'POST',
    headers,
    body: JSON.stringify({
      system: ANALYSIS_SKILL,
      prompt,
      student_code: code,
    }),
  })

  if (!response.ok) {
    throw new Error(`自定义 API 错误: ${response.statusText}`)
  }

  const data = await response.json()
  return data.content || data.response || data.result || JSON.stringify(data)
}

// 下载分析结果
function downloadResult() {
  if (!analysisResult.value) return

  const blob = new Blob([analysisResult.value], { type: 'text/markdown' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `错题分析_${student.value?.code}_${new Date().toISOString().slice(0, 10)}.md`
  a.click()
  URL.revokeObjectURL(url)
}

// 保存分析到服务器（开发环境跳过）
async function saveAnalysis() {
  if (!analysisResult.value) return

  if (isDev) {
    alert('✅ 开发模式：分析结果已在本地保存')
    return
  }

  if (!mockLoggedIn.value) {
    alert('请先登录')
    return
  }

  alert('功能开发中...')
}

useHead(() => ({ title: `AI 错题分析 - ${student.value?.name || code} ｜观澜` }))
</script>

<template>
  <div class="site-shell">
    <SiteHeader />
    <main class="page-wrap inner-page">
      <Breadcrumbs />

      <!-- 开发模式提示 -->
      <div v-if="isDev" class="dev-banner">
        🔧 本地开发模式：已绕过登录验证，可直接测试 AI 分析功能
      </div>

      <section class="quiz-head">
        <div>
          <span class="section-kicker">AI 错题分析</span>
          <h1>{{ student?.name || code }} 的智能错题分析</h1>
          <p>上传错题 Markdown 文件，使用 AI 生成专业的错题分析报告</p>
        </div>
        <div class="quiz-head-actions">
          <NuxtLink class="ghost-button" :to="`/mistakes/${code}`">
            <ArrowLeft :size="15" />返回错题本
          </NuxtLink>
          <button type="button" class="ghost-button" @click="showConfig = true">
            <Settings :size="15" />配置 AI
          </button>
        </div>
      </section>

      <!-- AI 配置弹窗 -->
      <div v-if="showConfig" class="modal-overlay" @click.self="showConfig = false">
        <div class="modal-content ai-config-modal">
          <header class="modal-header">
            <h2>AI API 配置</h2>
            <button type="button" class="close-button" @click="showConfig = false">×</button>
          </header>

          <div class="form-group">
            <label>AI 提供商</label>
            <select v-model="aiConfig.provider" class="form-select">
              <option value="openai">OpenAI (GPT-4)</option>
              <option value="claude">Claude (Anthropic)</option>
              <option value="custom">自定义 API</option>
            </select>
          </div>

          <div v-if="aiConfig.provider === 'openai'" class="config-section">
            <div class="form-group">
              <label>API Key *</label>
              <input v-model="aiConfig.apiKey" type="password" class="form-input" placeholder="sk-..." />
            </div>
            <div class="form-group">
              <label>Base URL（可选）</label>
              <input v-model="aiConfig.baseUrl" type="text" class="form-input" placeholder="https://api.openai.com/v1" />
            </div>
            <div class="form-group">
              <label>模型</label>
              <input v-model="aiConfig.model" type="text" class="form-input" placeholder="gpt-4" />
            </div>
          </div>

          <div v-else-if="aiConfig.provider === 'claude'" class="config-section">
            <div class="form-group">
              <label>API Key *</label>
              <input v-model="aiConfig.apiKey" type="password" class="form-input" placeholder="sk-ant-..." />
            </div>
            <div class="form-group">
              <label>Base URL（可选）</label>
              <input v-model="aiConfig.baseUrl" type="text" class="form-input" placeholder="https://api.anthropic.com/v1" />
            </div>
            <div class="form-group">
              <label>模型</label>
              <input v-model="aiConfig.model" type="text" class="form-input" placeholder="claude-opus-4-8" />
            </div>
          </div>

          <div v-else class="config-section">
            <div class="form-group">
              <label>API Endpoint *</label>
              <input v-model="aiConfig.endpoint" type="text" class="form-input" placeholder="https://your-api.com/analyze" />
            </div>
            <div class="form-group">
              <label>API Key（可选）</label>
              <input v-model="aiConfig.apiKey" type="password" class="form-input" placeholder="留空表示不需要认证" />
            </div>
          </div>

          <div class="modal-actions">
            <button type="button" class="ghost-button" @click="showConfig = false">取消</button>
            <button type="button" class="primary-button" @click="saveConfig">保存配置</button>
          </div>
        </div>
      </div>

      <!-- 上传区域 -->
      <section class="upload-section">
        <div class="upload-card">
          <div class="upload-icon">
            <Upload :size="48" />
          </div>
          <h3>上传错题 Markdown 文件</h3>
          <p>支持标准的错题格式，包含题干、选项、答案和错因</p>

          <label class="upload-button">
            <input type="file" accept=".md" @change="handleFileUpload" style="display: none" />
            <FileText :size="18" />选择文件
          </label>

          <div v-if="uploadedFile" class="file-info">
            <span class="file-name">{{ uploadedFile.name }}</span>
            <span class="file-size">{{ (uploadedFile.size / 1024).toFixed(1) }} KB</span>
          </div>
        </div>
      </section>

      <!-- 错误提示 -->
      <div v-if="analysisError" class="error-banner">
        {{ analysisError }}
      </div>

      <!-- 分析按钮 -->
      <section v-if="markdown" class="action-section">
        <button
          type="button"
          class="primary-button large analyze-button"
          :disabled="analyzing"
          @click="analyzeWithAI"
        >
          <Sparkles :size="20" />
          {{ analyzing ? '分析中...' : '开始 AI 分析' }}
        </button>
      </section>

      <!-- 分析结果 -->
      <section v-if="analysisResult" class="result-section">
        <div class="result-header">
          <h2>分析报告</h2>
          <div class="result-actions">
            <button type="button" class="ghost-button small" @click="downloadResult">
              <Download :size="16" />下载
            </button>
            <button type="button" class="ghost-button small" @click="saveAnalysis">
              保存{{ isDev ? '（测试）' : '' }}
            </button>
          </div>
        </div>

        <div class="markdown-content" v-html="$md.render(analysisResult)" />
      </section>

      <!-- 使用说明 -->
      <section v-if="!analysisResult" class="guide-section">
        <h3>📝 错题文件格式示例</h3>
        <pre class="code-block">## 马原 - 第1题

**题干**: 下列关于实践的表述正确的是（  ）

A. 实践是人类能动地改造世界的客观物质性活动
B. 实践是社会历史性的活动
C. 实践是人类有目的的自觉活动
D. 实践是人类的存在方式

**我的答案**: A
**正确答案**: ABCD
**错误类型**: 漏选</pre>

        <div class="guide-tips">
          <h4>💡 分析内容包括：</h4>
          <ul>
            <li><strong>考点结论</strong> - 结论式短句、关键词加粗、表格对照</li>
            <li><strong>错因分析</strong> - 归纳 3-5 类主要错因，不写成流水账</li>
            <li><strong>真题怎么考</strong> - 粘贴可核验的真题原文</li>
            <li><strong>下次怎么做</strong> - 最重要的具体复习动作</li>
          </ul>
        </div>
      </section>
    </main>
  </div>
</template>

<style scoped>
.dev-banner {
  padding: 1rem 1.5rem;
  background: #fef3c7;
  border-left: 4px solid #f59e0b;
  color: #92400e;
  border-radius: 8px;
  margin-bottom: 1.5rem;
  font-weight: 500;
}

.upload-section {
  margin: 2rem 0;
}

.upload-card {
  background: var(--card-bg, #fff);
  border: 2px dashed var(--border, #e5e7eb);
  border-radius: 12px;
  padding: 3rem 2rem;
  text-align: center;
}

.upload-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 80px;
  height: 80px;
  background: var(--primary-light, #eff6ff);
  border-radius: 50%;
  color: var(--primary, #3b82f6);
  margin-bottom: 1.5rem;
}

.upload-card h3 {
  margin: 0 0 0.5rem;
  font-size: 1.25rem;
}

.upload-card p {
  color: var(--text-secondary, #6b7280);
  margin-bottom: 1.5rem;
}

.upload-button {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.75rem 1.5rem;
  background: var(--primary, #3b82f6);
  color: white;
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.2s;
}

.upload-button:hover {
  background: var(--primary-dark, #2563eb);
  transform: translateY(-1px);
}

.file-info {
  margin-top: 1rem;
  padding: 0.75rem 1rem;
  background: var(--bg-secondary, #f9fafb);
  border-radius: 8px;
  display: inline-flex;
  align-items: center;
  gap: 1rem;
}

.file-name {
  font-weight: 500;
}

.file-size {
  color: var(--text-secondary, #6b7280);
  font-size: 0.875rem;
}

.error-banner {
  padding: 1rem 1.5rem;
  background: #fef2f2;
  border-left: 4px solid #ef4444;
  color: #991b1b;
  border-radius: 8px;
  margin: 1rem 0;
}

.action-section {
  margin: 2rem 0;
  text-align: center;
}

.analyze-button {
  font-size: 1.125rem;
  padding: 1rem 3rem;
  gap: 0.75rem;
}

.analyze-button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.result-section {
  margin: 2rem 0;
}

.result-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.5rem;
  padding-bottom: 1rem;
  border-bottom: 2px solid var(--border, #e5e7eb);
}

.result-header h2 {
  margin: 0;
  font-size: 1.5rem;
}

.result-actions {
  display: flex;
  gap: 0.5rem;
}

.markdown-content {
  background: var(--card-bg, #fff);
  padding: 2rem;
  border-radius: 12px;
  border: 1px solid var(--border, #e5e7eb);
}

.markdown-content :deep(h2) {
  margin-top: 2rem;
  margin-bottom: 1rem;
  font-size: 1.5rem;
  border-bottom: 2px solid var(--border, #e5e7eb);
  padding-bottom: 0.5rem;
}

.markdown-content :deep(h3) {
  margin-top: 1.5rem;
  margin-bottom: 0.75rem;
  font-size: 1.25rem;
  color: var(--primary, #3b82f6);
}

.markdown-content :deep(strong) {
  color: var(--primary, #3b82f6);
}

.markdown-content :deep(table) {
  width: 100%;
  border-collapse: collapse;
  margin: 1rem 0;
}

.markdown-content :deep(th),
.markdown-content :deep(td) {
  padding: 0.75rem;
  border: 1px solid var(--border, #e5e7eb);
  text-align: left;
}

.markdown-content :deep(th) {
  background: var(--bg-secondary, #f9fafb);
  font-weight: 600;
}

.guide-section {
  margin: 3rem 0;
  padding: 2rem;
  background: var(--bg-secondary, #f9fafb);
  border-radius: 12px;
}

.guide-section h3 {
  margin-top: 0;
  margin-bottom: 1rem;
  font-size: 1.25rem;
}

.code-block {
  background: var(--card-bg, #fff);
  padding: 1.5rem;
  border-radius: 8px;
  border: 1px solid var(--border, #e5e7eb);
  overflow-x: auto;
  font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
  font-size: 0.875rem;
  line-height: 1.6;
}

.guide-tips {
  margin-top: 2rem;
}

.guide-tips h4 {
  margin-bottom: 1rem;
  font-size: 1.125rem;
}

.guide-tips ul {
  list-style: none;
  padding: 0;
}

.guide-tips li {
  padding: 0.75rem 0;
  padding-left: 1.5rem;
  position: relative;
}

.guide-tips li::before {
  content: '•';
  position: absolute;
  left: 0;
  color: var(--primary, #3b82f6);
  font-weight: bold;
}

.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.modal-content {
  background: var(--card-bg, #fff);
  border-radius: 16px;
  max-width: 500px;
  width: 90%;
  max-height: 90vh;
  overflow-y: auto;
}

.ai-config-modal {
  padding: 2rem;
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.5rem;
}

.modal-header h2 {
  margin: 0;
  font-size: 1.5rem;
}

.close-button {
  background: none;
  border: none;
  font-size: 2rem;
  line-height: 1;
  cursor: pointer;
  color: var(--text-secondary, #6b7280);
  padding: 0;
  width: 32px;
  height: 32px;
}

.close-button:hover {
  color: var(--text-primary, #111827);
}

.form-group {
  margin-bottom: 1.5rem;
}

.form-group label {
  display: block;
  margin-bottom: 0.5rem;
  font-weight: 500;
  color: var(--text-primary, #111827);
}

.form-input,
.form-select {
  width: 100%;
  padding: 0.75rem 1rem;
  border: 1px solid var(--border, #e5e7eb);
  border-radius: 8px;
  font-size: 1rem;
  transition: border-color 0.2s;
}

.form-input:focus,
.form-select:focus {
  outline: none;
  border-color: var(--primary, #3b82f6);
}

.config-section {
  margin-top: 1.5rem;
}

.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.75rem;
  margin-top: 2rem;
  padding-top: 1.5rem;
  border-top: 1px solid var(--border, #e5e7eb);
}
</style>
