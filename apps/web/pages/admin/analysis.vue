<script setup lang="ts">
import { ref } from 'vue'
import { Plus, Trash2 } from 'lucide-vue-next'
import type { ArticleSummary } from '~/types/api'

type AdminArticle = ArticleSummary & { id: number; revision?: number }

const { request } = useAuth()
const { items, total, filters, draft, sizes, options, loading, loaded, loadError, visible, load, search, resetFilters, go } = useAdminList<AdminArticle>('/admin/analysis', ['q', 'status', 'category'])
const message = ref('')
const deleting = ref<number | null>(null)
const editor = ref<{ confirmLeave: () => boolean } | null>(null)
const editorOpen = ref(false)
const editorSession = ref(0)
const articleId = ref<number | null>(null)
function openEditor(id: number | null = null) {
  if (editorOpen.value && !editor.value?.confirmLeave()) return
  editorSession.value++
  articleId.value = id
  editorOpen.value = true
}
async function articleSaved() {
  message.value = '已保存文章'
  await load()
}

async function remove(id: number) {
  if (deleting.value !== null || !window.confirm('确定删除这篇内容？删除后无法撤销。')) return
  deleting.value = id
  message.value = ''
  try {
    await request(`/admin/analysis/${id}`, { method: 'DELETE' })
    await load()
    message.value = '已删除'
  } catch (exception) {
    message.value = (exception as { data?: { message?: string } })?.data?.message || '删除失败'
  } finally { deleting.value = null }
}

async function toggleStatus(item: AdminArticle) {
  const status = item.status === 'hidden' ? 'published' : 'hidden'
  try {
    await request(`/admin/analysis/${item.id}`, { method: 'PATCH', body: { status, expectedRevision: item.revision ?? 1 } })
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
    await request('/admin/comments/mode', { method: 'PUT', body: { articleType: 'analysis', slug: item.slug, mode } })
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
    <div class="section-heading"><div><span class="section-kicker">分析文章</span><h2><span v-if="visible">共 {{ total }} 篇</span></h2></div></div>

    <form class="admin-form content-filters" role="search" @submit.prevent="search">
      <label><span>标题或摘要</span><input v-model="draft.q" type="search" aria-label="标题或摘要" /></label>
      <label><span>状态筛选</span><select v-model="draft.status" aria-label="状态筛选"><option value="">全部状态</option><option value="published">已发布</option><option value="hidden">已隐藏</option></select></label>
      <label><span>分类筛选</span><select v-model="draft.category" aria-label="分类筛选"><option value="">全部分类</option><option v-if="draft.category && !options.categories.includes(draft.category)" :value="draft.category">{{ draft.category }}</option><option v-for="option in options.categories" :key="option" :value="option">{{ option }}</option></select></label>
      <label><span>每页条数</span><select v-model.number="draft.perPage" aria-label="每页条数"><option v-for="size in sizes" :key="size" :value="size">{{ size }} 条</option></select></label>
      <button class="primary-button" type="submit">筛选</button><button class="ghost-button" type="button" @click="resetFilters">重置筛选</button>
    </form>

    <button class="primary-button" type="button" @click="openEditor()"><Plus :size="15" />新增分析</button>
    <AdminArticleEditor v-if="editorOpen" :key="editorSession" ref="editor" kind="analysis" :article-id="articleId" @close="editorOpen = false" @saved="articleSaved" />
    <p v-if="message" class="admin-meta">{{ message }}</p>

    <AdminListState :loading="loading" :error="loadError" :empty="loaded && !items.length" @retry="load">没有匹配的内容。</AdminListState>
    <ul v-if="visible && items.length" class="record-list">
      <li v-for="item in items" :key="item.id">
        <span class="record-type">{{ item.category }}</span>
        <span class="record-title">{{ item.title }}</span>
        <button type="button" class="ghost-button small" :class="{ hidden: item.status === 'hidden' }" @click="toggleStatus(item)">{{ item.status === 'hidden' ? '已隐藏' : '已发布' }}</button>
        <select class="comment-mode-select" :value="item.commentMode ?? 'open'" @change="setCommentMode(item, $event)">
          <option value="open">评论·自动发布</option>
          <option value="review">评论·审核后发布</option>
          <option value="closed">禁止评论</option>
        </select>
        <button type="button" class="ghost-button small" @click="openEditor(item.id)">编辑</button>
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