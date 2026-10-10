<script setup lang="ts">
definePageMeta({ layout: 'admin' })
type AuditLog = { id: number; adminId: number; adminEmail: string; action: string; targetType: string; targetId: string; detail: unknown; createdAt: string }
const route = useRoute(), router = useRouter()
const { request, restore } = useAuth()
const keys = ['action', 'adminId', 'from', 'to', 'targetType', 'targetId'] as const
const filters = reactive<Record<typeof keys[number], string>>({ action: '', adminId: '', from: '', to: '', targetType: '', targetId: '' })
const items = ref<AuditLog[]>([]), total = ref<number | null>(null), page = ref(1), perPage = ref(20), loading = ref(false), error = ref('')
let sequence = 0
function syncInputs() { for (const key of keys) filters[key] = String(route.query[key] ?? '') }
async function load() {
  const id = ++sequence
  loading.value = true; error.value = ''; items.value = []; total.value = null
  const params = new URLSearchParams({ page: String(route.query.page ?? 1), perPage: String(route.query.perPage ?? 20) })
  for (const key of keys) if (route.query[key]) params.set(key, String(route.query[key]))
  try {
    const result = await request<{ items: AuditLog[]; total: number; page: number; perPage: number }>(`/admin/audit-logs?${params}`)
    if (id === sequence) { items.value = result.items; total.value = result.total; page.value = result.page; perPage.value = result.perPage }
  } catch (exception) { if (id === sequence) error.value = (exception as { data?: { message?: string } }).data?.message || '审计日志加载失败' }
  finally { if (id === sequence) loading.value = false }
}
async function apply(reset = false) {
  const query = { ...route.query, page: '1', perPage: String(perPage.value) }
  for (const key of keys) { if (reset) filters[key] = ''; if (filters[key]) query[key] = filters[key]; else delete query[key] }
  if (keys.every(key => String(route.query[key] ?? '') === filters[key]) && String(route.query.page ?? '1') === '1' && String(route.query.perPage ?? '20') === String(perPage.value)) await load()
  else await router.push({ query })
}
async function turnPage(next: number) { await router.push({ query: { ...route.query, page: String(next), perPage: String(perPage.value) } }) }
onMounted(async () => { await restore(); syncInputs(); await load() })
watch(() => route.fullPath, () => { syncInputs(); load() })
onBeforeUnmount(() => { sequence++ })
const totalPages = computed(() => Math.max(1, Math.ceil((total.value ?? 0) / perPage.value)))
function beijing(value: string) { const date = new Date(value); return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat('sv-SE', { timeZone: 'Asia/Shanghai', dateStyle: 'short', timeStyle: 'medium' }).format(date) }
function detailText(log: AuditLog) { return JSON.stringify(log.detail, null, 2) ?? 'null' }
const actionLabels: Record<string, string> = {
  'article.status.batch': '批量修改文章状态',
  'user.create': '创建用户', 'user.update': '更新用户', 'user.reset_password': '重置密码',
  'hotspot.create': '新建热点', 'hotspot.update': '更新热点', 'hotspot.delete': '删除热点',
  'analysis.create': '新建分析', 'analysis.update': '更新分析', 'analysis.delete': '删除分析',
  'paper.create': '新建试卷', 'paper.update': '更新试卷', 'paper.delete': '删除试卷', 'question.update': '编辑题目',
  'prediction.create': '新建预测', 'prediction.update': '更新预测', 'prediction.delete': '删除预测',
  'article.restore': '恢复正文修订', 'comment.pin': '置顶评论', 'comment.unpin': '取消置顶评论', 'comment.approve': '审核通过评论', 'comment.mode': '修改评论模式', 'comment.update': '更新评论', 'comment.delete': '删除评论', 'comment.status': '修改评论状态',
  'hotspot.revision.restore': '恢复热点修订', 'analysis.revision.restore': '恢复分析修订', 'prediction.revision.restore': '恢复预测修订',
  'mistake.profile.replace': '替换错题画像', 'mistake.item.update': '更新错题',
}
</script>

<template>
  <section class="admin-section" :aria-busy="loading">
    <div class="section-heading"><div><span class="section-kicker">审计日志</span><h2>{{ total === null ? '操作记录' : `共 ${total} 条操作记录` }}</h2></div></div>
    <form class="audit-filters" role="search" @submit.prevent="apply()">
      <label>操作前缀<input v-model="filters.action" type="search" placeholder="如 user. 或 hotspot." aria-label="操作前缀" /></label>
      <label>管理员 ID<input v-model="filters.adminId" type="text" inputmode="numeric" aria-label="管理员 ID" /></label>
      <label>对象类型<input v-model="filters.targetType" type="text" aria-label="对象类型" /></label>
      <label>对象 ID<input v-model="filters.targetId" type="text" aria-label="对象 ID" /></label>
      <label>开始日期<input v-model="filters.from" type="date" aria-label="开始日期" /></label>
      <label>结束日期<input v-model="filters.to" type="date" aria-label="结束日期" /></label>
      <label>每页条数<select v-model.number="perPage" aria-label="每页条数"><option :value="20">20</option><option :value="50">50</option><option :value="100">100</option></select></label>
      <button class="ghost-button" type="submit">筛选</button><button class="ghost-button" type="button" @click="apply(true)">重置筛选</button>
    </form>
    <p class="admin-meta">日期含首尾，时间显示及日期筛选使用北京时间（Asia/Shanghai）。操作前缀按字面匹配；详情保留完整 JSON。</p>
    <p v-if="loading" role="status">正在加载审计日志…</p>
    <div v-else-if="error" role="alert"><p>{{ error }}</p><button class="ghost-button" @click="load">重试</button></div>
    <template v-else-if="total !== null">
      <div class="table-scroll" tabindex="0" role="region" aria-label="审计记录表，可横向滚动">
        <table class="admin-table"><thead><tr><th scope="col">时间（北京时间）</th><th scope="col">操作人</th><th scope="col">操作</th><th scope="col">对象</th><th scope="col">详情</th></tr></thead>
          <tbody><tr v-for="log in items" :key="log.id"><td><time :datetime="log.createdAt">{{ beijing(log.createdAt) }}</time></td><td>{{ log.adminEmail || `#${log.adminId}` }}</td><td>{{ actionLabels[log.action] ?? log.action }}<small v-if="actionLabels[log.action]" class="action-code">{{ log.action }}</small></td><td>{{ log.targetType }}#{{ log.targetId }}</td><td><details><summary>查看完整详情 #{{ log.id }}</summary><pre>{{ detailText(log) }}</pre></details></td></tr></tbody>
        </table>
      </div>
      <div v-if="!items.length" class="empty-state">没有匹配的记录。</div>
      <nav class="pager" aria-label="审计日志分页"><button class="ghost-button small" :disabled="page <= 1" @click="turnPage(page - 1)">上一页</button><span class="admin-meta" role="status">第 {{ page }} / {{ totalPages }} 页 · 共 {{ total }} 条</span><button class="ghost-button small" :disabled="page >= totalPages" @click="turnPage(page + 1)">下一页</button></nav>
    </template>
  </section>
</template>

<style scoped>
.audit-filters { display: flex; flex-wrap: wrap; align-items: end; gap: 12px; }
.audit-filters label { display: grid; gap: 6px; min-width: 130px; flex: 1 1 150px; }
.audit-filters input, .audit-filters select { width: 100%; min-height: 40px; border: 1px solid var(--border, #ddd); border-radius: 8px; padding: 6px 10px; background: transparent; color: inherit; }
.table-scroll { overflow-x: auto; }
.admin-table { min-width: 720px; }
.admin-table td { vertical-align: top; }
pre { white-space: pre-wrap; overflow-wrap: anywhere; max-width: 600px; min-width: 200px; font-size: .8rem; }
summary { cursor: pointer; }
.action-code { display: block; color: var(--text-muted); }
.pager { display: flex; align-items: center; gap: 12px; justify-content: center; margin-top: 12px; flex-wrap: wrap; }
</style>
