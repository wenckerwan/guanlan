<script setup lang="ts">
import { reactive, ref } from 'vue'
import { Plus, Trash2 } from 'lucide-vue-next'
import type { ArticleSummary } from '~/types/api'

type AdminArticle = ArticleSummary & { id: number }

const { request } = useAuth()
const { items, total, filters, draft, sizes, options, loading, loaded, loadError, visible, load, search, resetFilters, go } = useAdminList<AdminArticle>('/admin/hotspots', ['q', 'status', 'period'])
const message = ref('')
const deleting = ref<number | null>(null)
const form = reactive({ title: '', summary: '', period: '', priority: 'A', level: 'S', type: '形势与政策', tag: '', html: '' })

async function create() {
  message.value = ''
  try {
    await request('/admin/hotspots', { method: 'POST', body: { ...form } })
    form.title = ''
    form.summary = ''
    form.html = ''
    message.value = '已新增'
    await load()
  } catch (exception) {
    message.value = (exception as { data?: { message?: string } })?.data?.message || '新增失败'
  }
}

async function remove(id: number) {
  if (deleting.value !== null || !window.confirm('确定删除这篇内容？删除后无法撤销。')) return
  deleting.value = id
  message.value = ''
  try {
    await request(`/admin/hotspots/${id}`, { method: 'DELETE' })
    await load()
    message.value = '已删除'
  } catch (exception) {
    message.value = (exception as { data?: { message?: string } })?.data?.message || '删除失败'
  } finally { deleting.value = null }
}

async function toggleStatus(item: AdminArticle) {
  const status = item.status === 'hidden' ? 'published' : 'hidden'
  try {
    await request(`/admin/hotspots/${item.id}`, { method: 'PATCH', body: { status } })
    await load()
    message.value = status === 'hidden' ? '已隐藏' : '已发布'
  } catch (exception) {
    message.value = (exception as { data?: { message?: string } })?.data?.message || '操作失败'
  }
}

async function setCommentMode(item: AdminArticle, event: Event) {
  const mode = (event.target as HTMLSelectElement).value
  message.value = ''
  try {
    await request('/admin/comments/mode', { method: 'PUT', body: { articleType: 'hotspot', slug: item.slug, mode } })
    item.commentMode = mode
    message.value = '评论设置已更新'
  } catch (exception) {
    message.value = (exception as { data?: { message?: string } })?.data?.message || '评论设置失败'
    await load()
  }
}
</script>

<template>
  <section class="admin-section">
    <div class="section-heading"><div><span class="section-kicker">时政管理</span><h2><span v-if="visible">共 {{ total }} 条</span></h2></div></div>

    <form class="admin-form content-filters" role="search" @submit.prevent="search">
      <label><span>标题或摘要</span><input v-model="draft.q" type="search" aria-label="标题或摘要" /></label>
      <label><span>状态筛选</span><select v-model="draft.status" aria-label="状态筛选"><option value="">全部状态</option><option value="published">已发布</option><option value="hidden">已隐藏</option></select></label>
      <label><span>期次筛选</span><select v-model="draft.period" aria-label="期次筛选"><option value="">全部期次</option><option v-if="draft.period && !options.periods.includes(draft.period)" :value="draft.period">{{ draft.period }}</option><option v-for="option in options.periods" :key="option" :value="option">{{ option }}</option></select></label>
      <label><span>每页条数</span><select v-model.number="draft.perPage" aria-label="每页条数"><option v-for="size in sizes" :key="size" :value="size">{{ size }} 条</option></select></label>
      <button class="primary-button" type="submit">筛选</button><button class="ghost-button" type="button" @click="resetFilters">重置筛选</button>
    </form>

    <form class="admin-form" @submit.prevent="create">
      <label><span>标题</span><input v-model="form.title" required /></label>
      <label><span>期次</span><input v-model="form.period" placeholder="2026年10月" /></label>
      <label><span>优先级</span><select v-model="form.priority"><option>S</option><option>A</option><option>B</option><option>C</option></select></label>
      <label><span>等级</span><select v-model="form.level"><option>S</option><option>A</option><option>B</option></select></label>
      <label><span>类型</span><input v-model="form.type" /></label>
      <label><span>标签</span><input v-model="form.tag" /></label>
      <label class="wide"><span>摘要</span><input v-model="form.summary" /></label>
      <label class="wide"><span>正文 HTML</span><textarea v-model="form.html" rows="4"></textarea></label>
      <button class="primary-button" type="submit"><Plus :size="15" />新增热点</button>
    </form>
    <p v-if="message" class="admin-meta">{{ message }}</p>

    <AdminListState :loading="loading" :error="loadError" :empty="loaded && !items.length" @retry="load">没有匹配的内容。</AdminListState>
    <ul v-if="visible && items.length" class="record-list">
      <li v-for="item in items" :key="item.id">
        <span class="record-type">{{ item.priority || 'A' }}</span>
        <span class="record-title">{{ item.title }}</span>
        <time>{{ item.period }}</time>
        <button type="button" class="ghost-button small" :class="{ hidden: item.status === 'hidden' }" @click="toggleStatus(item)">{{ item.status === 'hidden' ? '已隐藏' : '已发布' }}</button>
        <select class="comment-mode-select" :value="item.commentMode ?? 'open'" @change="setCommentMode(item, $event)">
          <option value="open">评论·自动发布</option>
          <option value="review">评论·审核后发布</option>
          <option value="closed">禁止评论</option>
        </select>
        <button type="button" class="icon-button" aria-label="删除" :disabled="deleting !== null" @click="remove(item.id)"><Trash2 :size="14" /></button>
      </li>
    </ul>
    <AdminPagination v-if="visible" :page="filters.page" :per-page="filters.perPage" :total="total" :busy="loading" @change="go" />
  </section>
</template>
<style scoped>
.comment-mode-select {
  padding: 4px 8px;
  border: 1px solid var(--border, #e5e7eb);
  border-radius: 6px;
  background: var(--card-bg, #fff);
  color: var(--text-primary, #111827);
  font-size: 0.8125rem;
}
</style>