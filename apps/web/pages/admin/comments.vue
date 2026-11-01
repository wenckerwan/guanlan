<script setup lang="ts">
import UserGroupBadge from '~/components/UserGroupBadge.vue'
definePageMeta({ layout: 'admin' })
type AdminComment = { id: number; articleType: string; articleSlug: string; floor: number; content: string; status: string; pinned: boolean; parentId: number | null; createdAt: string; user: { id: number; name: string; role: string; userGroup: string } }
const route = useRoute(), router = useRouter()
const { request, restore } = useAuth()
const keys = ['q', 'status', 'articleType', 'articleSlug', 'userId', 'userQ'] as const
const scalar = (value: unknown) => typeof value === 'string' ? value : ''
const positive = (value: unknown, fallback: number) => /^\d+$/.test(scalar(value)) && Number(value) > 0 ? Number(value) : fallback
const selected = computed(() => ({ q: scalar(route.query.q), status: scalar(route.query.status), articleType: scalar(route.query.articleType), articleSlug: scalar(route.query.articleSlug), userId: scalar(route.query.userId), userQ: scalar(route.query.userQ), page: positive(route.query.page, 1), perPage: positive(route.query.perPage, 20) }))
const draft = reactive({ ...selected.value })
const sizes = computed(() => [...new Set([20, 50, 100, draft.perPage])].sort((a, b) => a - b))
const items = ref<AdminComment[]>([]), total = ref<number | null>(null), loading = ref(false), loadError = ref(''), actionError = ref(''), busy = ref(false)
const totalPages = computed(() => Math.max(1, Math.ceil((total.value ?? 0) / selected.value.perPage)))
const visible = computed(() => !loading.value && !loadError.value && total.value !== null)
let sequence = 0, started = false, alive = true
let retryAction: (() => Promise<void>) | null = null
const TYPE_LABELS: Record<string, string> = { analysis: '真题分析', hotspot: '时政热点', prediction: '时政预测' }
const ARTICLE_PATHS: Record<string, string> = { analysis: '/analysis/', hotspot: '/hotspots/', prediction: '/predictions/' }
function query(filters: typeof selected.value) {
  const result: Record<string, string> = {}
  for (const key of keys) if (filters[key]) result[key] = filters[key]
  if (filters.page !== 1) result.page = String(filters.page)
  if (filters.perPage !== 20) result.perPage = String(filters.perPage)
  return result
}
async function load() {
  const version = ++sequence, filters = { ...selected.value }
  loading.value = true; loadError.value = ''; items.value = []; total.value = null
  try {
    const params = new URLSearchParams({ ...query(filters), page: String(filters.page), perPage: String(filters.perPage) })
    const data = await request<{ items: AdminComment[]; total: number; page: number; perPage: number }>(`/admin/comments?${params}`)
    if (version !== sequence || !alive) return
    if (!Array.isArray(data.items) || !Number.isInteger(data.total) || data.total < 0 || !Number.isInteger(data.page) || data.page < 1 || !Number.isInteger(data.perPage) || data.perPage < 1) throw new Error('Invalid comments response')
    items.value = data.items; total.value = data.total
    if (data.page !== filters.page || data.perPage !== filters.perPage) {
      const next = { ...route.query }; delete next.page; delete next.perPage
      await router.replace({ query: { ...next, ...query({ ...filters, page: data.page, perPage: data.perPage }) } })
    }
  } catch (exception) {
    if (version === sequence && alive) loadError.value = (exception as { data?: { message?: string } }).data?.message || '评论列表加载失败，请重试。'
  } finally { if (version === sequence && alive) loading.value = false }
}
async function select(filters: typeof selected.value) {
  if (busy.value) return
  actionError.value = ''; retryAction = null
  const next = { ...route.query }
  for (const key of [...keys, 'page', 'perPage']) delete next[key]
  const nextQuery = { ...next, ...query(filters) }
  if (JSON.stringify(query(filters)) === JSON.stringify(query(selected.value))) return load()
  await router.push({ query: nextQuery })
}
const search = () => select({ ...draft, q: draft.q.trim(), userQ: draft.userQ.trim(), articleSlug: draft.articleSlug.trim(), userId: draft.userId.trim(), page: 1 })
const reset = () => select({ q: '', status: '', articleType: '', articleSlug: '', userId: '', userQ: '', page: 1, perPage: 20 })
const locateArticle = (row: AdminComment) => select({ ...selected.value, articleType: row.articleType, articleSlug: row.articleSlug, page: 1 })
const locateUser = (row: AdminComment) => row.user.id > 0 ? select({ ...selected.value, userId: String(row.user.id), userQ: '', page: 1 }) : undefined
async function mutate(row: AdminComment, action: string) {
  if (busy.value || loading.value) return
  busy.value = true; actionError.value = ''; retryAction = null
  try {
    await request(`/admin/comments/${row.id}`, action === 'delete' ? { method: 'DELETE' } : { method: 'PATCH', body: { action } })
    if (alive) await load()
  } catch (exception) {
    if (alive) { actionError.value = (exception as { data?: { message?: string } }).data?.message || '评论操作失败，请重试。'; retryAction = action === 'delete' ? () => remove(row) : () => mutate(row, action) }
  } finally { if (alive) busy.value = false }
}
async function remove(row: AdminComment) {
  if (busy.value || loading.value) return
  if (!window.confirm(`确定删除评论 #${row.id}？该评论及其全部子回复会一并删除，无法恢复。`)) return
  await mutate(row, 'delete')
}
watch(selected, () => { sequence++; actionError.value = ''; retryAction = null; Object.assign(draft, selected.value); if (started) void load() }, { flush: 'sync' })
onMounted(async () => { await restore(); if (alive) { started = true; await load() } })
onBeforeUnmount(() => { alive = false; started = false; sequence++ })
</script>

<template>
  <section class="admin-section" :aria-busy="loading || busy">
    <div class="section-heading"><div><span class="section-kicker">评论管理</span><h2>{{ total === null ? '评论记录' : `共 ${total} 条评论` }}</h2></div></div>
    <form class="comment-filters" role="search" @submit.prevent="search">
      <label>评论正文关键词<input v-model="draft.q" type="search" aria-label="评论正文关键词" :disabled="busy" /></label>
      <label>评论状态<select v-model="draft.status" aria-label="评论状态" :disabled="busy"><option value="">全部</option><option value="pending">待审核</option><option value="approved">已通过</option></select></label>
      <label>文章栏目<select v-model="draft.articleType" aria-label="文章栏目" :disabled="busy"><option value="">全部栏目</option><option value="analysis">真题分析</option><option value="hotspot">时政热点</option><option value="prediction">时政预测</option></select></label>
      <label>文章标识<input v-model="draft.articleSlug" aria-label="文章标识" :disabled="busy" /></label>
      <label>用户 ID<input v-model="draft.userId" type="text" inputmode="numeric" aria-label="用户 ID" :disabled="busy" /></label>
      <label>用户姓名或邮箱<input v-model="draft.userQ" type="search" aria-label="用户姓名或邮箱" :disabled="busy" /></label>
      <label>每页条数<select v-model.number="draft.perPage" aria-label="每页条数" :disabled="busy"><option v-for="size in sizes" :key="size" :value="size">{{ size }}</option></select></label>
      <button class="ghost-button" type="submit" :disabled="busy">筛选</button><button class="ghost-button" type="button" :disabled="busy" @click="reset">重置筛选</button>
    </form>
    <p v-if="busy" role="status">正在保存并刷新评论列表…</p>
    <div v-if="actionError" role="alert"><p>{{ actionError }}</p><button class="ghost-button" :disabled="busy || loading" @click="retryAction?.()">重试操作</button></div>
    <p v-if="loading" role="status">正在加载评论…</p>
    <div v-else-if="loadError" role="alert"><p>{{ loadError }}</p><button class="ghost-button" :disabled="busy" @click="load">重试列表</button></div>
    <template v-if="visible">
      <div class="table-scroll" tabindex="0" role="region" aria-label="评论记录，可横向滚动">
        <table class="admin-table"><thead><tr><th scope="col">时间</th><th scope="col">用户</th><th scope="col">栏目/文章</th><th scope="col">完整内容</th><th scope="col">状态</th><th scope="col">操作</th></tr></thead>
          <tbody><tr v-for="row in items" :key="row.id">
            <td class="admin-meta"><time :datetime="row.createdAt">{{ row.createdAt.replace('T', ' ').slice(0, 16) }}</time></td>
            <td class="comment-user"><strong>{{ row.user.name }}</strong><UserGroupBadge :group="row.user.userGroup" :role="row.user.role" /><button class="ghost-button small" :disabled="busy || row.user.id <= 0" :title="row.user.id > 0 ? undefined : '已注销用户无法定位账号'" @click="locateUser(row)">同用户</button></td>
            <td class="admin-meta">{{ TYPE_LABELS[row.articleType] ?? row.articleType }}<br /><NuxtLink v-if="ARTICLE_PATHS[row.articleType]" :to="ARTICLE_PATHS[row.articleType] + encodeURIComponent(row.articleSlug)">{{ row.articleSlug }}</NuxtLink><span v-else>{{ row.articleSlug }}</span><small>{{ row.parentId ? ` · 回复#${row.parentId}` : ` · #${row.floor}` }}</small><button class="ghost-button small" :disabled="busy" @click="locateArticle(row)">同文章</button></td>
            <td class="comment-content-cell">{{ row.content }}</td>
            <td><span class="status-chip" :class="row.status === 'pending' ? 'warn' : 'ok'">{{ row.status === 'pending' ? '待审核' : '已通过' }}</span><span v-if="row.pinned" class="status-chip ok">置顶</span></td>
            <td><div class="comment-actions"><button v-if="row.status === 'pending'" class="ghost-button small" :disabled="busy" @click="mutate(row, 'approve')">通过</button><button v-if="!row.parentId" class="ghost-button small" :disabled="busy" @click="mutate(row, row.pinned ? 'unpin' : 'pin')">{{ row.pinned ? '取消置顶' : '置顶' }}</button><button class="ghost-button small danger" :disabled="busy" @click="remove(row)">删除</button></div></td>
          </tr></tbody>
        </table>
      </div>
      <div v-if="!items.length" class="empty-state">没有匹配的评论。</div>
      <nav class="pager" aria-label="评论分页"><button class="ghost-button small" :disabled="busy || selected.page <= 1" @click="select({ ...selected, page: selected.page - 1 })">上一页</button><span class="admin-meta" role="status">第 {{ selected.page }} / {{ totalPages }} 页 · 共 {{ total }} 条</span><button class="ghost-button small" :disabled="busy || selected.page >= totalPages" @click="select({ ...selected, page: selected.page + 1 })">下一页</button></nav>
    </template>
  </section>
</template>

<style scoped>
.comment-filters { display: flex; flex-wrap: wrap; align-items: end; gap: 12px; margin-bottom: 16px; }
.comment-filters label { display: grid; gap: 6px; min-width: 130px; flex: 1 1 160px; }
.comment-filters input, .comment-filters select { width: 100%; min-height: 40px; padding: 6px 10px; border: 1px solid var(--border); border-radius: 8px; background: var(--card-bg); color: var(--text-primary); }
.table-scroll { overflow-x: auto; max-width: 100%; }
.admin-table { min-width: 900px; }
.admin-table td { vertical-align: top; }
.comment-user strong { display: block; font-size: .875rem; }
.comment-content-cell { min-width: 240px; max-width: 420px; white-space: pre-wrap; overflow-wrap: anywhere; font-size: .875rem; }
.comment-actions { display: flex; gap: 6px; flex-wrap: wrap; min-width: 150px; }
.status-chip { display: inline-block; margin: 0 4px 4px 0; padding: 1px 8px; border-radius: 999px; font-size: .75rem; white-space: nowrap; }
.status-chip.ok { background: #ecfdf5; color: #059669; }
.status-chip.warn { background: #fffbeb; color: #b45309; }
.ghost-button.danger { color: var(--error, #dc2626); }
.pager { display: flex; align-items: center; gap: 12px; justify-content: center; flex-wrap: wrap; margin-top: 12px; }
</style>
