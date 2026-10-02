<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
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

type ExamMatch = {
  question_id: number
  year: number
  super_name: string
  score: number
  stem_excerpt: string
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
  examSim: number
  examAffinity: number
  examMatches: ExamMatch[]
  hotspotId: number | null
}

/**
 * 真题相关度分层按「本日候选池的分位」判，不写死绝对阈值：
 * 分位阈值是某天数据上量出来的，跨天不可比（同一条素材在不同算法口径下能差 1.15 倍）；
 * 而「在本日池里排前列」这个含义天天成立。绝对分数照常显示，供跨天比较。
 */
/** 分数一律按 3 位小数取整后再分档：列里显示 0.076 却一个中相关一个弱相关，
 *  是因为 exam_sim 有 4 位小数（0.0762 / 0.0759）而分位阈值正好卡在中间。 */
const r3 = (v: number) => Math.round((v || 0) * 1000) / 1000

const simSorted = computed(() => items.value.map((i) => r3(i.examSim)).sort((a, b) => a - b))

function quantile(p: number) {
  const arr = simSorted.value
  if (arr.length === 0) return 0
  return arr[Math.min(arr.length - 1, Math.floor(arr.length * p))]
}

const TIERS = [
  { label: '强相关', cls: 'sim-strong' },
  { label: '中相关', cls: 'sim-mid' },
  { label: '弱相关', cls: 'sim-weak' },
]

function simTier(v: number) {
  const x = r3(v)
  if (x >= quantile(0.75)) return TIERS[0]
  if (x >= quantile(0.25)) return TIERS[1]
  return TIERS[2]
}

/**
 * 题干摘要按句读边界截，不硬切。
 * 直接 slice(0,16) 会切出「中国人民解放军战区成立大会于20」这种半截词，
 * 后台看着像坏行；退到最近的一个标点处收尾更好读。
 */
function clipExcerpt(text: string, max = 26) {
  const t = (text || '').replace(/\s+/g, '').trim()
  if (t.length <= max) return t
  const window = t.slice(0, max)
  let cut = -1
  for (const p of ['。', '；', '，', '、', '：']) {
    const i = window.lastIndexOf(p)
    if (i > 10 && i > cut) cut = i
  }
  return cut > 0 ? window.slice(0, cut + 1) : window + '…'
}

/** 命中真题：每条一行，空题干的丢掉；题池里有同干异题（实测 1999 欧盟那条出现两次），按摘要去重 */
function hitLines(item: Candidate) {
  const lines = (item.examMatches || [])
    .map((m) => ({ text: clipExcerpt(m.stem_excerpt), year: m.year, score: m.score }))
    .filter((h) => h.text !== '')
  const uniq = lines.filter((h, i, arr) => arr.findIndex((x) => x.text === h.text) === i)
  return uniq.slice(0, 3)
}

const { request, restore } = useAuth()
const message = ref('')
const testing = ref(false)
const screening = ref(false)

const config = reactive<AiConfig>({ provider: 'openai', apiKey: '', baseUrl: 'https://api.openai.com/v1', model: 'gpt-4o-mini', subjectId: 6, topN: 10, hasKey: false })
const query = reactive({ date: new Date(Date.now() - 86400000).toISOString().slice(0, 10), top: 10, sort: 'id' })
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
  items.value = await request<Candidate[]>(`/admin/shizheng/candidates?date=${query.date}&sort=${query.sort}`)
}

/** 降级原因要写明，否则又变成「看不出区别的静默兜底」 */
const FALLBACK_LABELS: Record<string, string> = {
  no_key: '未配置 API Key',
  ai_request_failed: 'AI 请求失败',
  ai_unparseable: 'AI 返回无法解析',
}

async function screen(auto: boolean) {
  screening.value = true
  message.value = ''
  try {
    const data = await request<{
      total: number
      selected: unknown[]
      fallback: boolean
      fallbackReason: string | null
      strategy: string
      published: { created: number; skipped: number } | null
    }>(
      '/admin/shizheng/screen',
      { method: 'POST', body: { date: query.date, top: query.top, auto } },
    )
    const how = data.fallback
      ? `（规则兜底：${FALLBACK_LABELS[data.fallbackReason || ''] || data.fallbackReason || 'AI 不可用'}）`
      : data.strategy === 'ai' ? '（AI 筛选）' : `（${data.strategy} 策略）`
    lastResult.value = `候选 ${data.total} 条，入选 ${data.selected.length} 条`
      + how
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
      <label>
        <span>列表排序</span>
        <select v-model="query.sort" @change="loadCandidates">
          <option value="id">默认（入库顺序）</option>
          <option value="sim">按真题相关度</option>
        </select>
      </label>
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
        <span class="admin-meta">
          真题相关度 <span :class="simTier(item.examSim).cls">{{ simTier(item.examSim).label }}</span>
          {{ ' ' }}{{ (item.examSim || 0).toFixed(3) }}
        </span>
        <span class="admin-meta">{{ item.status === 'published' ? '已发布' : item.status === 'selected' ? '已选中' : '待筛选' }}{{ item.aiReason ? ` · ${item.aiReason}` : '' }}</span>
        <span v-if="hitLines(item).length" class="admin-meta exam-hits">
          相近真题：<i v-for="(h, i) in hitLines(item)" :key="i">{{ h.year }}·{{ h.text }}（{{ h.score.toFixed(3) }}）</i>
        </span>
      </li>
    </ul>
    <p v-if="!items.length" class="admin-meta">该日期暂无候选（爬虫每日凌晨自动推送）。</p>
  </section>
</template>

<style scoped>
.is-selected .record-title { font-weight: 600; }
.is-published { opacity: 0.6; }
.sim-strong, .sim-mid, .sim-weak { padding:1px 6px; border-radius:3px; font-size:11px; }
.sim-strong { background:var(--red-soft); color:var(--red); }
.sim-mid { background:var(--gold-soft); color:var(--gold); }
.sim-weak { background:var(--line); color:var(--muted); }
/* .record-list li 是不换行的 flex：长文本会被塞进同一个 flex 项里自己折行，
   断成「｜ 命 / 于20」那种坏行。相近真题改成独占一行。 */
.record-list li { flex-wrap: wrap; }
.exam-hits { flex: 1 0 100%; font-size: 11px; }
.exam-hits i { font-style: normal; margin-right: 10px; }
</style>
