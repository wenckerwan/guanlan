<script setup lang="ts">
definePageMeta({ layout: 'admin' })
import type { ArticleSummary } from '~/types/api'
type AdminPrediction = ArticleSummary & { id: number; sortOrder: number }
const { items: predictions, total, filters, loading, loaded, loadError, visible, load, go } = useAdminList<AdminPrediction>('/admin/predictions')
const { request } = useAuth()
const sortDraft = reactive<Record<number, string>>({})
const sortOriginal: Record<number, string> = {}
const busy = ref(false), message = ref(''), actionError = ref(''), commentEpoch = ref(0)
let alive = true
watch(predictions, rows => {
  for (const item of rows) {
    const confirmed = String(item.sortOrder)
    if (sortDraft[item.id] === undefined || sortDraft[item.id] === sortOriginal[item.id]) sortDraft[item.id] = confirmed
    sortOriginal[item.id] = confirmed
  }
})
onBeforeUnmount(() => { alive = false })
async function act(item: AdminPrediction, operation: 'sort' | 'status' | 'comments', mode?: string) {
  if (busy.value || loading.value) return
  let sortOrder = 0
  if (operation === 'sort') {
    const raw = sortDraft[item.id]?.trim() ?? ''
    sortOrder = Number(raw)
    if (!/^-?\d+$/.test(raw) || !Number.isInteger(sortOrder) || sortOrder < -2147483648 || sortOrder > 2147483647) { actionError.value = '排序必须是 -2147483648 至 2147483647 的整数。'; message.value = ''; return }
  }
  busy.value = true; message.value = ''; actionError.value = ''
  try {
    if (operation === 'comments') await request('/admin/comments/mode', { method: 'PUT', body: { articleType: 'prediction', slug: item.slug, mode } })
    else {
      const confirmed = await request<AdminPrediction>(`/admin/predictions/${item.id}`, { method: 'PATCH', body: operation === 'sort' ? { sortOrder } : { status: item.status === 'hidden' ? 'published' : 'hidden' } })
      if (alive && operation === 'sort') {
        sortDraft[item.id] = String(confirmed.sortOrder)
        sortOriginal[item.id] = String(confirmed.sortOrder)
      }
    }
    if (alive) { await load(); message.value = operation === 'sort' ? '排序已保存' : operation === 'comments' ? '评论设置已更新' : item.status === 'hidden' ? '已发布' : '已隐藏' }
  } catch (exception) {
    if (alive) {
      actionError.value = (exception as { data?: { message?: string } }).data?.message || '操作失败，请重试。'
      // Restore a failed comment select to the confirmed value without discarding sort drafts.
      if (operation === 'comments') commentEpoch.value++
    }
  } finally { if (alive) busy.value = false }
}
</script>

<template>
  <section class="admin-section" :aria-busy="loading || busy">
    <div class="section-heading"><div><span class="section-kicker">时政预测</span><h2>{{ visible ? `共 ${total} 篇` : '预测文章' }}</h2></div><button class="ghost-button" :disabled="busy || loading" @click="load">刷新列表</button></div>
    <p class="admin-meta">预测文章由离线数据集导入；支持发布 / 隐藏、评论设置与排序。排序数值越小越靠前，不改变文章标识、发布状态或评论模式。</p>
    <p v-if="busy" role="status">正在保存并刷新列表…</p><p v-if="message" role="status">{{ message }}</p><div v-if="actionError" role="alert">{{ actionError }}</div>
    <p v-if="loading" role="status">正在加载预测文章…</p><div v-else-if="loadError" role="alert"><p>{{ loadError }}</p><button class="ghost-button" :disabled="busy" @click="load">重试列表</button></div>
    <template v-if="visible">
      <div v-if="predictions.length" class="table-scroll" tabindex="0" role="region" aria-label="预测文章列表，可横向滚动">
        <table class="admin-table"><thead><tr><th scope="col">标题</th><th scope="col">层级</th><th scope="col">字数</th><th scope="col">来源文件</th><th scope="col">排序</th><th scope="col">状态</th><th scope="col">评论</th></tr></thead><tbody><tr v-for="item in predictions" :key="item.id"><td>{{ item.title }}</td><td>{{ item.layer }}</td><td>{{ item.wordCount }}</td><td>{{ item.sourceFile }}</td><td><div class="sort-control"><input v-model="sortDraft[item.id]" type="text" inputmode="numeric" :aria-label="`预测排序 #${item.id}`" :disabled="busy" /><button class="ghost-button small" :disabled="busy" @click="act(item, 'sort')">保存排序</button></div></td><td><button class="ghost-button small" :class="{ hidden: item.status === 'hidden' }" :disabled="busy" @click="act(item, 'status')">{{ item.status === 'hidden' ? '已隐藏' : '已发布' }}</button></td><td><select :key="`${item.id}-${commentEpoch}`" class="comment-mode-select" :aria-label="`评论模式 #${item.id}`" :value="item.commentMode ?? 'open'" :disabled="busy" @change="act(item, 'comments', ($event.target as HTMLSelectElement).value)"><option value="open">自动发布</option><option value="review">审核后发布</option><option value="closed">禁止评论</option></select></td></tr></tbody></table>
      </div>
      <div v-else class="empty-state">暂无预测内容。</div>
      <AdminPagination :page="filters.page" :per-page="filters.perPage" :total="total" :busy="busy || loading" @change="go" />
    </template>
  </section>
</template>

<style scoped>
.table-scroll { overflow-x: auto; max-width: 100%; }
.admin-table { min-width: 960px; }
.admin-table td { vertical-align: top; }
.hidden { opacity: .6; }
.sort-control { display: flex; gap: 8px; min-width: 215px; }
.sort-control input { width: 110px; }
.sort-control input, .comment-mode-select { padding: 6px 8px; min-height: 36px; border: 1px solid var(--border, #ddd); border-radius: 6px; background: var(--card-bg); color: var(--text-primary); }
</style>
