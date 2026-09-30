<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { marked } from 'marked'
import { ArrowLeft, Upload, Sparkles, Settings, FileText, Download } from 'lucide-vue-next'
import type { MistakeStudent } from '~/types/api'

const route = useRoute()
const code = String(route.params.code)
const { request, token, isLoggedIn, restore } = useAuth()
if (import.meta.client) restore()

// 🔧 本地开发模式：绕过认证
const isDev = process.env.NODE_ENV === 'development'
const mockLoggedIn = ref(isDev) // 开发环境模拟已登录

// 获取考生信息
const student = ref<MistakeStudent | null>(null)

// 在客户端加载考生信息
onMounted(async () => {
  if (isDev && !isLoggedIn.value) {
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
  provider: 'deepseek',
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
const importToMistakes = ref(true)
const importNote = ref('')

// 连接测试与模型列表
type TestResult = { ok: boolean; latencyMs: number; models: string[]; error?: string }
const testing = ref(false)
const testResult = ref<TestResult | null>(null)
const modelOptions = ref<string[]>([])

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
// 连接测试 + 模型列表获取（同一后端端点）
async function testConnection() {
  testing.value = true
  testResult.value = null
  analysisError.value = ''
  try {
    const data = await request<TestResult>('/mistakes/ai/test', { method: 'POST', body: { ...aiConfig } })
    testResult.value = data
    if (data.ok && data.models.length) {
      modelOptions.value = data.models
    }
  } catch (err: any) {
    testResult.value = { ok: false, latencyMs: 0, models: [], error: err?.data?.message || err?.message || '请求失败' }
  } finally {
    testing.value = false
  }
}

// 真实对话测试：给 AI 发固定短消息，验证模型实际可用，成功失败都弹提示
type ChatTestResult = { ok: boolean; latencyMs: number; reply: string; error?: string; followedInstruction?: boolean }
const chatTesting = ref(false)

async function chatTest() {
  chatTesting.value = true
  analysisError.value = ''
  let result: ChatTestResult
  try {
    result = await request<ChatTestResult>('/mistakes/ai/chat-test', { method: 'POST', body: { ...aiConfig } })
  } catch (err: any) {
    result = { ok: false, latencyMs: 0, reply: '', error: err?.data?.message || err?.message || '请求失败' }
  } finally {
    chatTesting.value = false
  }

  if (result.ok) {
    const follow = result.followedInstruction ? '' : '（未按指令回复，但模型有响应）'
    window.alert(`✅ AI 可用！
模型回复：${result.reply || '（空）'}
耗时 ${result.latencyMs}ms${follow}`)
    testResult.value = { ok: true, latencyMs: result.latencyMs, models: [], error: undefined }
  } else {
    window.alert(`❌ AI 不可用
${result.error || '未知错误'}
耗时 ${result.latencyMs}ms`)
  }
}

// 分析报告：marked 渲染 + 保存/历史
const reportHtml = computed(() => {
  if (!analysisResult.value) return ''
  try {
    return marked.parse(analysisResult.value, { async: false }) as string
  } catch {
    return '<pre>' + analysisResult.value.replace(/</g, '&lt;') + '</pre>'
  }
})

type SavedReport = { id: number; title: string; createdAt: string }
const savedReports = ref<SavedReport[]>([])
const savedNote = ref('')

async function loadReports() {
  if (import.meta.client && !isLoggedIn.value) return
  try {
    savedReports.value = await request<SavedReport[]>(`/mistakes/students/${code}/analysis-reports`)
    if (savedReports.value.length && !analysisResult.value) {
      await openReport(savedReports.value[0].id)
    }
  } catch {
    savedReports.value = []
  }
}

async function saveReport() {
  if (!analysisResult.value) return
  try {
    await request(`/mistakes/students/${code}/analysis-reports`, {
      method: 'POST',
      body: { markdown: analysisResult.value, title: 'AI 分析 ' + new Date().toLocaleString('zh-CN', { hour12: false }) },
    })
    savedNote.value = '已自动保存到你的分析报告'
    await loadReports()
  } catch {
    savedNote.value = '保存失败（报告仍可在当前页面查看/下载）'
  }
}

async function openReport(id: number) {
  try {
    const data = await request<{ markdown: string; title: string }>(`/mistakes/analysis-reports/${id}`)
    analysisResult.value = data.markdown
    savedNote.value = `正在查看历史报告：${data.title}`
    window.scrollTo({ top: 0, behavior: 'smooth' })
  } catch (err: any) {
    analysisError.value = err?.data?.message || '读取报告失败'
  }
}

async function deleteReport(id: number) {
  if (!window.confirm('确定删除这份分析报告？删除后不可恢复。')) return
  try {
    await request(`/mistakes/analysis-reports/${id}`, { method: 'DELETE' })
    savedReports.value = savedReports.value.filter((report) => report.id !== id)
    savedNote.value = '报告已删除'
  } catch (err: any) {
    savedNote.value = ''
    analysisError.value = err?.data?.message || '删除失败'
  }
}

onMounted(() => {
  loadReports()
})

// SSE 流式分析：增量文本实时追加到 analysisResult，服务端 done 事件以全量内容兜底
type ImportStats = { imported: number; updated: number; skipped: number }

function formatImportNote(stats: ImportStats): string {
  if (stats.imported + stats.updated + stats.skipped === 0) {
    return '未能识别出错题，没有加入错题本（不影响分析）'
  }
  const parts = [stats.imported ? `新导入 ${stats.imported} 道` : '', stats.updated ? `更新 ${stats.updated} 道` : ''].filter(Boolean)
  const note = `已加入错题本：${parts.join('、')}`
  return stats.skipped ? `${note}；跳过 ${stats.skipped} 道（无法识别）` : note
}

async function streamAnalysis(): Promise<void> {
  const apiBase = useRuntimeConfig().public.apiBase as string
  const response = await fetch(`${apiBase}/mistakes/students/${code}/ai-analysis-stream`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      ...(token.value ? { Authorization: `Bearer ${token.value}` } : {}),
    },
    body: JSON.stringify({
      ...aiConfig,
      markdown: markdown.value,
      importToMistakes: importToMistakes.value && isLoggedIn.value,
    }),
  })

  const contentType = response.headers.get('content-type') || ''
  if (!response.ok || !contentType.includes('text/event-stream')) {
    let message = `HTTP ${response.status}`
    try {
      const body = await response.json()
      if (body?.message) message = body.message
    } catch {
      // 错误体不是 JSON，保留 HTTP 状态码提示
    }
    throw new Error(message)
  }

  const reader = response.body?.getReader()
  if (!reader) throw new Error('当前浏览器不支持流式读取')
  const decoder = new TextDecoder()
  let buffer = ''
  let finished = false

  while (!finished) {
    const { value, done } = await reader.read()
    if (done) break
    buffer += decoder.decode(value, { stream: true })
    let sep: number
    while ((sep = buffer.indexOf('\n\n')) >= 0) {
      const event = buffer.slice(0, sep)
      buffer = buffer.slice(sep + 2)
      const dataLine = event.split('\n').find((line) => line.startsWith('data: '))
      if (!dataLine) continue
      const payload = JSON.parse(dataLine.slice(6))
      if (payload.importing) importNote.value = '正在解析错题并加入错题本…'
      if (payload.import) importNote.value = formatImportNote(payload.import)
      if (payload.delta) analysisResult.value += payload.delta
      if (payload.error) throw new Error(payload.error)
      if (payload.done) {
        finished = true
        if (payload.content) analysisResult.value = payload.content
      }
    }
  }

  if (!finished && !analysisResult.value) {
    throw new Error('连接中断，未收到分析内容')
  }
}

// 开始分析：优先走 SSE 流式（边生成边渲染），流式不可用时回退非流式接口
async function analyzeWithAI() {
  if (!markdown.value) {
    analysisError.value = '请先上传错题文件'
    return
  }

  if (import.meta.client && !isLoggedIn.value && !isDev) {
    analysisError.value = '请先登录后再使用 AI 分析（分析请求由服务器代理转发）'
    return
  }

  if (!aiConfig.apiKey && aiConfig.provider !== 'custom') {
    analysisError.value = '请先配置 AI API'
    showConfig.value = true
    return
  }
  if (aiConfig.provider === 'custom' && !aiConfig.endpoint) {
    analysisError.value = '请先填写自定义 API 端点'
    showConfig.value = true
    return
  }

  analyzing.value = true
  analysisError.value = ''
  analysisResult.value = ''
  importNote.value = ''

  try {
    await streamAnalysis()
  } catch (error: any) {
    if (!analysisResult.value) {
      try {
        const data = await request<{ content: string; usage: Record<string, unknown>; import?: ImportStats }>(
          `/mistakes/students/${code}/ai-analysis`,
          {
            method: 'POST',
            body: {
              ...aiConfig,
              markdown: markdown.value,
              importToMistakes: importToMistakes.value && isLoggedIn.value,
            },
          },
        )
        analysisResult.value = data.content
        if (data.import) importNote.value = formatImportNote(data.import)
      } catch (fallbackError: any) {
        analysisError.value = fallbackError?.data?.message || fallbackError?.message || 'AI 分析失败，请检查配置或使用「测试连接」排查'
      }
    } else {
      analysisError.value = `分析中断（已保留已生成部分）：${error?.message || '未知错误'}`
    }
  } finally {
    if (analysisResult.value && isLoggedIn.value) {
      await saveReport()
    }
    analyzing.value = false
  }
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
              <option value="deepseek">DeepSeek (官方)</option>
              <option value="openai">OpenAI (GPT-4)</option>
              <option value="claude">Claude (Anthropic)</option>
              <option value="custom">自定义 API</option>
            </select>
          </div>

          <div class="form-group connection-test-row">
            <button type="button" class="ghost-button" :disabled="testing" @click="testConnection">
              {{ testing ? '测试中…' : '测试连接 / 获取模型' }}
            </button>
            <button type="button" class="ghost-button" :disabled="chatTesting" @click="chatTest">
              {{ chatTesting ? '对话测试中…' : '发送测试消息' }}
            </button>
          </div>
          <datalist id="model-options">
            <option v-for="model in modelOptions" :key="model" :value="model" />
          </datalist>

          <p v-if="testResult" class="test-result" :class="testResult.ok ? 'ok' : 'fail'">
            <template v-if="testResult.ok">
              ✅ 连接成功，耗时 {{ testResult.latencyMs }}ms
              <template v-if="testResult.models.length">，获取到 {{ testResult.models.length }} 个模型</template>
              <template v-else>未能自动获取模型列表，请手动填写</template>
            </template>
            <template v-else>❌ {{ testResult.error || '连接失败' }}</template>
          </p>

          <div v-if="aiConfig.provider === 'openai' || aiConfig.provider === 'deepseek'" class="config-section">
            <div class="form-group">
              <label>API Key *</label>
              <input v-model="aiConfig.apiKey" type="password" class="form-input" placeholder="sk-..." />
            </div>
            <div class="form-group">
              <label>Base URL（可选）</label>
              <input
                v-model="aiConfig.baseUrl"
                type="text"
                class="form-input"
                :placeholder="aiConfig.provider === 'deepseek' ? 'https://api.deepseek.com（默认，无需修改）' : 'https://api.openai.com/v1'"
              />
            </div>
            <div class="form-group">
              <label>模型</label>
              <input
                v-model="aiConfig.model"
                type="text"
                class="form-input"
                list="model-options"
                :placeholder="aiConfig.provider === 'deepseek' ? 'deepseek-chat（可点上方按钮获取列表）' : 'gpt-4，可点上方按钮获取列表'"
              />
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
              <input v-model="aiConfig.model" type="text" class="form-input" list="model-options" placeholder="claude-opus-4-8，可点上方按钮获取列表" />
            </div>
          </div>

          <div v-else class="config-section">
            <div class="form-group">
              <label>API Endpoint *</label>
              <input v-model="aiConfig.endpoint" type="text" class="form-input" placeholder="https://your-api.com/v1/chat/completions" />
            </div>
            <div class="form-group">
              <label>API Key（可选）</label>
              <input v-model="aiConfig.apiKey" type="password" class="form-input" placeholder="留空表示不需要认证" />
            </div>
            <div class="form-group">
              <label>模型</label>
              <input v-model="aiConfig.model" type="text" class="form-input" list="model-options" placeholder="端点以 /chat/completions 结尾时可自动获取" />
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

          <label v-if="uploadedFile" class="import-toggle">
            <input v-model="importToMistakes" type="checkbox" />
            同时把识别出的错题加入错题本（重复上传自动去重）
          </label>
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
        <p v-if="importNote" class="import-note">{{ importNote }}</p>
      </section>

      <!-- 分析结果 -->
      <section v-if="analysisResult" class="result-section">
        <div class="result-header">
          <h2>分析报告</h2>
          <div class="result-actions">
            <button type="button" class="ghost-button small" @click="downloadResult">
              <Download :size="16" />下载
            </button>
            <span v-if="savedNote" class="saved-note">{{ savedNote }}</span>
          </div>
        </div>

        <div class="markdown-content" v-html="reportHtml" />
      </section>

      <!-- 历史分析报告 -->
      <section v-if="savedReports.length" class="reports-section">
        <h3>我的 AI 分析报告（{{ savedReports.length }}）</h3>
        <ul class="reports-list">
          <li v-for="report in savedReports" :key="report.id">
            <span class="report-row">
              <button type="button" class="ghost-button small" @click="openReport(report.id)">
                <FileText :size="14" />{{ report.title }}
              </button>
              <button type="button" class="report-delete" title="删除报告" @click="deleteReport(report.id)">×</button>
            </span>
            <time>{{ report.createdAt.slice(0, 16) }}</time>
          </li>
        </ul>
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

.import-toggle {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  margin-top: 1rem;
  font-size: 0.875rem;
  color: var(--text-secondary, #6b7280);
  cursor: pointer;
}

.import-note {
  margin: 1rem 0 0;
  font-size: 0.875rem;
  color: var(--success, #16a34a);
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

<style scoped>
.connection-test-row {
  display: flex;
  gap: 0.75rem;
  align-items: center;
}

.test-result {
  font-size: 0.8125rem;
  margin: 0 0 0.75rem;
}

.test-result.ok {
  color: var(--success, #16a34a);
}

.test-result.fail {
  color: var(--error, #ef4444);
}
</style>

<style scoped>
.saved-note {
  font-size: 0.8125rem;
  color: var(--success, #16a34a);
}

.reports-section {
  margin: 2rem 0;
}

.reports-section h3 {
  font-size: 1.0625rem;
  margin-bottom: 0.75rem;
}

.reports-list {
  list-style: none;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.reports-list li {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
  padding: 0.5rem 0.75rem;
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-radius: 6px;
}

.report-row {
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
  min-width: 0;
}

.report-delete {
  background: none;
  border: none;
  color: var(--text-muted, #9ca3af);
  font-size: 1.125rem;
  line-height: 1;
  cursor: pointer;
  padding: 0.125rem 0.375rem;
  border-radius: 4px;
}

.report-delete:hover {
  color: var(--error, #ef4444);
  background: var(--bg-secondary, #f9fafb);
}

.reports-list time {
  font-size: 0.75rem;
  color: var(--text-muted);
  white-space: nowrap;
}
</style>
