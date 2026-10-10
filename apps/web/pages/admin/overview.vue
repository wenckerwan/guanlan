<script setup lang="ts">
import type { AdminOverview } from '~/types/api'

definePageMeta({ name: 'admin-overview', layout: 'admin' })
type Statistics = {
  from: string; to: string; timezone: string; timestampTimezone: string; studyDateTimezone: string; updatedAt: string; dates: string[]
  totals: { registrations: number; attempts: number; correctAttempts: number; visits: number; studySeconds: number; activeStudyAccounts: number }
  registrationTrend: Record<string, number>; attemptsTrend: Record<string, number>; visitTrend: Record<string, number>; studyTrend: Record<string, number>
}
type Overview = AdminOverview & { statistics: Statistics; statusCounts?: Record<string, Record<string, number>>; recentAudit?: { action: string; targetType: string; targetId: string; adminEmail: string; createdAt: string }[] }
const route = useRoute(), router = useRouter()
const { request, restore } = useAuth()
const data = ref<Overview | null>(null), loading = ref(false), error = ref('')
const from = ref(''), to = ref('')
let sequence = 0
function dateAtBeijing(date: Date) { return new Intl.DateTimeFormat('sv-SE', { timeZone: 'Asia/Shanghai', year: 'numeric', month: '2-digit', day: '2-digit' }).format(date) }
const today = dateAtBeijing(new Date())
const first = dateAtBeijing(new Date(Date.now() - 13 * 86400000))
function syncInputs() { from.value = String(route.query.from ?? first); to.value = String(route.query.to ?? today) }
async function load() {
  const id = ++sequence
  loading.value = true; error.value = ''; data.value = null
  try {
    const params = new URLSearchParams({ from: String(route.query.from ?? first), to: String(route.query.to ?? today) })
    const result = await request<Overview>(`/admin/overview?${params}`)
    if (!result?.statistics || !Array.isArray(result.statistics.dates)) throw new Error('统计数据不可用')
    if (id === sequence) data.value = result
  } catch (exception) {
    if (id === sequence) error.value = (exception as { data?: { message?: string }; message?: string }).data?.message || (exception as Error).message || '统计加载失败'
  } finally { if (id === sequence) loading.value = false }
}
async function apply() {
  const query = { ...route.query, from: from.value, to: to.value }
  if (String(route.query.from ?? '') === from.value && String(route.query.to ?? '') === to.value) await load()
  else await router.push({ query })
}
onMounted(async () => { await restore(); syncInputs(); await load() })
watch(() => [route.query.from, route.query.to], () => { syncInputs(); load() })
onBeforeUnmount(() => { sequence++ })
const statistics = computed(() => data.value?.statistics)
const labels: Record<string, string> = { users: '用户', papers: '试卷', questions: '题目', analysis_articles: '真题分析', hotspots: '时政热点', predictions: '时政预测', mistake_items: '错题', mistake_reviews: '复习记录', attempts: '作答记录', favorites: '收藏', notes: '笔记' }
const links: Record<string, string> = { users: '/admin/users', papers: '/admin/papers', questions: '/admin/papers', analysis_articles: '/admin/analysis', hotspots: '/admin/hotspots', predictions: '/admin/predictions', mistake_items: '/admin/mistakes' }
const statusNames: Record<string, string> = { hotspots: '时政热点', analysis_articles: '真题分析', predictions: '时政预测' }
function beijing(value: string) { const date = new Date(value); return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat('sv-SE', { timeZone: 'Asia/Shanghai', dateStyle: 'short', timeStyle: 'medium' }).format(date) }
function duration(seconds: number) { return `${Math.floor(seconds / 3600)} 小时 ${Math.floor(seconds % 3600 / 60)} 分 ${seconds % 60} 秒` }
</script>

<template>
  <section class="admin-section" :aria-busy="loading">
    <div class="section-heading"><div><span class="section-kicker">数据总览</span><h2>内容与用户</h2></div></div>
    <form class="range-filter" @submit.prevent="apply">
      <label>开始日期<input v-model="from" type="date" required aria-label="开始日期" /></label>
      <label>结束日期<input v-model="to" type="date" required aria-label="结束日期" /></label>
      <button type="submit" class="ghost-button">查询统计</button>
    </form>
    <p class="admin-meta">日期含首尾，最多 366 天。注册、作答时间及访问日期按北京时间（Asia/Shanghai）统计。</p>
    <p v-if="loading" role="status">正在加载统计…</p>
    <div v-else-if="error" role="alert"><p>{{ error }}</p><button class="ghost-button" @click="load">重试</button></div>
    <template v-else-if="data && statistics">
      <h3>全站存量</h3>
      <p class="admin-meta">以下内容与用户总数不受日期范围影响。</p>
      <div class="stat-row"><div v-for="(value, key) in data.counts" :key="key" class="stat-cell"><NuxtLink v-if="links[key]" :to="links[key]"><strong>{{ value }}</strong><small>{{ labels[key] ?? key }}</small></NuxtLink><template v-else><strong>{{ value }}</strong><small>{{ labels[key] ?? key }}</small></template></div></div>
      <h3>区间统计：{{ statistics.from }} — {{ statistics.to }}</h3>
      <p class="admin-meta">更新于 {{ beijing(statistics.updatedAt) }}（北京时间）</p>
      <div class="stat-row range-totals">
        <div class="stat-cell"><NuxtLink to="/admin/users"><strong>{{ statistics.totals.registrations }}</strong><small>新增账号（注册/代建）</small></NuxtLink></div>
        <div class="stat-cell"><strong>{{ statistics.totals.attempts }}</strong><small>作答次数</small></div>
        <div class="stat-cell"><strong>{{ statistics.totals.correctAttempts }}</strong><small>系统判对记录</small></div>
        <div class="stat-cell"><strong>{{ statistics.totals.visits }}</strong><small>访问次数（非人数）</small></div>
        <div class="stat-cell"><strong>{{ duration(statistics.totals.studySeconds) }}</strong><small>累计学习心跳时长</small></div>
        <div class="stat-cell"><strong>{{ statistics.totals.activeStudyAccounts }}</strong><small>有学习记录的账号</small></div>
      </div>
      <p class="admin-meta">学习口径：原始 UTC 自然日记录（studyDateTimezone={{ statistics.studyDateTimezone }}），仅按原记录日期汇总，不能精确转换为北京时间跨日时长。累计心跳记录不保证实际学习时长。</p>
      <p v-if="Object.values(statistics.totals).every(value => value === 0)" class="empty-state">此区间没有统计记录。</p>
      <div class="overview-grid">
        <AdminMetricTrend title="账号创建趋势" unit="账号" :dates="statistics.dates" :values="statistics.registrationTrend" />
        <AdminMetricTrend title="作答趋势" unit="次" :dates="statistics.dates" :values="statistics.attemptsTrend" />
        <AdminMetricTrend title="访问趋势" unit="次" :dates="statistics.dates" :values="statistics.visitTrend" />
        <AdminMetricTrend title="学习心跳趋势（UTC 记录日）" unit="秒" :dates="statistics.dates" :values="statistics.studyTrend" />
      </div>
      <h3>全站内容状态</h3>
      <table class="admin-table"><thead><tr><th>内容</th><th>已发布</th><th>已隐藏</th></tr></thead><tbody><tr v-for="(dist, key) in data.statusCounts ?? {}" :key="key"><td>{{ statusNames[key] ?? key }}</td><td>{{ dist.published ?? 0 }}</td><td>{{ dist.hidden ?? 0 }}</td></tr></tbody></table>
      <h3>全站最近创建账号</h3>
      <ul class="record-list"><li v-for="item in data.recentUsers" :key="item.id"><span class="record-type">{{ item.role }}</span><NuxtLink class="record-title" to="/admin/users">{{ item.displayName || item.email }}</NuxtLink><time>{{ beijing(item.createdAt) }}</time></li></ul>
      <h3><NuxtLink to="/admin/audit-logs">最近操作审计</NuxtLink></h3>
      <ul class="record-list"><li v-for="(item, index) in data.recentAudit ?? []" :key="index"><span class="record-type">{{ item.action }}</span><span class="record-title">{{ item.targetType }}#{{ item.targetId }}</span><span class="admin-meta">{{ item.adminEmail }}</span><time>{{ beijing(item.createdAt) }}</time></li></ul>
      <p v-if="!data.recentAudit?.length" class="admin-meta">暂无操作记录。</p>
    </template>
  </section>
</template>

<style scoped>
.overview-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 24px; }
.range-filter { display: flex; align-items: end; flex-wrap: wrap; gap: 12px; }
.range-filter label { display: grid; gap: 6px; }
.range-filter input { min-height: 40px; border: 1px solid var(--border, #ddd); border-radius: 8px; padding: 6px 10px; background: transparent; color: inherit; }
.stat-cell a { display: grid; color: inherit; text-decoration: none; }
.range-totals strong { font-size: 1.3rem; }
@media (max-width: 700px) { .overview-grid { grid-template-columns: minmax(0, 1fr); } .range-filter label { flex: 1; min-width: 130px; } .range-filter input { width: 100%; } }
</style>
