<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { Sparkles, Rss, Send, RefreshCw, KeyRound } from 'lucide-vue-next'

type AiConfig = {
  provider: string
  apiKey: string
  baseUrl: string
  model: string
  subjectId: number
  topN: number
  hasKey: boolean
}

type Candidate = {
  id: number
  title: string
  source: string
  channel: string
  status: string
  priority: string
  module: string
  type: string
  aiPriority: string
  aiModule: string
  aiReason: string
  hotspotId: number | null
}

const { request, restore } = useAuth()
const message = ref('')
const testing = ref(false)
const screening = ref(false)

const config = reactive<AiConfig>({ provider: 'openai', apiKey: '', baseUrl: 'https://api.openai.com/v1', model: 'gpt-4o-mini', subjectId: 6, topN: 10, hasKey: false })
const query = reactive({ date: new Date(Date.now() - 86400000).toISOString().slice(0, 10), top: 10 })
const items = ref<Candidate[]>([])
const lastResult = ref('')

const PROVIDER_PRESETS: Record<string, { baseUrl: string; model: string }> = {
  deepseek: { baseUrl: 'https://api.deepseek.com', model: 'deepseek-chat' },
  openai: { baseUrl: 'https://api.openai.com/v1', model: 'gpt-4o-mini' },
  claude: { baseUrl: 'https://api.anthropic.com/v1', model: 'claude-opus-4-8' },
  custom: { baseUrl: '', model: '' },
}

function onProviderChange() {
  const preset = PROVIDER_PRESETS[config.provider]
  if (!preset || !preset.baseUrl) return
  if (!config.baseUrl || Object.values(PROVIDER_PRESETS).some(p => p.baseUrl && p.baseUrl === config.baseUrl)) {
    config.baseUrl = preset.baseUrl
  }
  if (!config.model || Object.values(PROVIDER_PRESETS).some(p => p.model && p.model === config.model)) {
    config.model = preset.model
  }
}

async function loadConfig() {
  const data = await request<AiConfig>('/admin/shizheng/config')
  Object.assign(config, data)
  config.apiKey = '' // 掩码不回填，留空表示不修改
}

async function saveConfig() {
  message.value = ''
  try {
    const data = await request<AiConfig>('/admin/shizheng/config', { method: 'PUT', body: { ...config } })
    Object.assign(config, data)
    config.apiKey = ''
    message.value = '配置已保存'
  } catch (e) {
    message.value = (e as { data?: { message?: string } })?.data?.message || '保存失败'
  }
}

async function testConfig() {
  testing.value = true
  message.value = ''
  try {
    const data = await request<{ reply: string }>('/admin/shizheng/config/test', { method: 'POST' })
    message.value = `连接正常：${data.reply}`
  } catch (e) {
    message.value = (e as { data?: { message?: string } })?.data?.message || '连接失败'
  } finally {
    testing.value = false
  }
}

async function loadCandidates() {
  items.value = await request<Candidate[]>(`/admin/shizheng/candidates?date=${query.date}`)
}

async function screen(auto: boolean) {
  screening.value = true
  message.value = ''
  try {
    const data = await request<{ total: number; selected: unknown[]; fallback: boolean; published: { created: number; skipped: number } | null }>(
      '/admin/shizheng/screen',
      { method: 'POST', body: { date: query.date, top: query.top, auto } },
    )
    lastResult.value = `候选 ${data.total} 条，入选 ${data.selected.length} 条`
      + (data.fallback ? '（规则兜底，AI 不可用）' : '（AI 筛选）')
      + (data.published ? `；已发布 ${data.published.created} 条，跳过 ${data.published.skipped} 条` : '')
    await loadCandidates()
  } catch (e) {
    message.value = (e as { data?: { message?: string } })?.data?.message || '筛选失败'
  } finally {
    screening.value = false
  }
}

async function publishSelected() {
  const ids = items.value.filter(i => i.status === 'selected').map(i => i.id)
  if (!ids.length) {
    message.value = '没有已选中的候选'
    return
  }
  try {
    const data = await request<{ created: number; skipped: number }>('/admin/shizheng/publish', { method: 'POST', body: { ids } })
    message.value = `已发布 ${data.created} 条，跳过 ${data.skipped} 条（标题已存在）`
    await loadCandidates()
  } catch (e) {
    message.value = (e as { data?: { message?: string } })?.data?.message || '发布失败'
  }
}

onMounted(async () => {
  restore()
  await loadConfig()
})
</script>

<template>
  <section class="admin-section">
    <div class="section-heading">
      <div><span class="section-kicker">每日时政</span><h2>AI 筛选与发布</h2></div>
    </div>

    <form class="admin-form" @submit.prevent="saveConfig">
      <label><span>AI 提供商</span>
        <select v-model="config.provider" @change="onProviderChange">
          <option value="deepseek">DeepSeek (官方)</option>
          <option value="openai">OpenAI 兼容</option>
          <option value="claude">Claude</option>
          <option value="custom">自定义</option>
        </select>
      </label>
      <label><span>Base URL</span><input v-model="config.baseUrl" :placeholder="PROVIDER_PRESETS[config.provider]?.baseUrl || 'https://...'" /></label>
      <label><span>模型</span><input v-model="config.model" /></label>
      <label><span>API Key {{ config.hasKey ? '（已配置，留空不修改）' : '' }}</span>
        <input v-model="config.apiKey" type="password" autocomplete="new-password" placeholder="sk-..." />
      </label>
      <label><span>学科 ID</span><input v-model.number="config.subjectId" type="number" min="1" /></label>
      <label><span>每日条数</span><input v-model.number="config.topN" type="number" min="1" max="30" /></label>
      <button class="primary-button" type="submit"><KeyRound :size="15" />保存配置</button>
      <button class="ghost-button" type="button" :disabled="testing" @click="testConfig">
        <RefreshCw :size="15" />{{ testing ? '测试中…' : '测试连接' }}
      </button>
    </form>
    <p v-if="message" class="admin-meta">{{ message }}</p>

    <form class="admin-form" @submit.prevent="loadCandidates">
      <label><span>日期</span><input v-model="query.date" type="date" /></label>
      <label><span>入选条数</span><input v-model.number="query.top" type="number" min="1" max="30" /></label>
      <button class="ghost-button" type="submit"><Rss :size="15" />查看候选</button>
      <button class="primary-button" type="button" :disabled="screening" @click="screen(false)">
        <Sparkles :size="15" />{{ screening ? '筛选中…' : 'AI 筛选' }}
      </button>
      <button class="primary-button" type="button" :disabled="screening" @click="screen(true)">
        <Send :size="15" />筛选并发布
      </button>
      <button class="ghost-button" type="button" @click="publishSelected">发布选中项</button>
    </form>
    <p v-if="lastResult" class="admin-meta">{{ lastResult }}</p>

    <ul class="record-list">
      <li v-for="item in items" :key="item.id" :class="{ 'is-selected': item.status === 'selected', 'is-published': item.status === 'published' }">
        <span class="record-type">{{ item.aiPriority || item.priority || '—' }}</span>
        <span class="record-title">{{ item.title }}</span>
        <span class="admin-meta">{{ item.source }} {{ item.channel }} · {{ item.module }}</span>
        <span class="admin-meta">{{ item.status === 'published' ? '已发布' : item.status === 'selected' ? '已选中' : '待筛选' }}{{ item.aiReason ? ` · ${item.aiReason}` : '' }}</span>
      </li>
    </ul>
    <p v-if="!items.length" class="admin-meta">该日期暂无候选（爬虫每日凌晨自动推送）。</p>
  </section>
</template>

<style scoped>
.is-selected .record-title { font-weight: 600; }
.is-published { opacity: 0.6; }
</style>
