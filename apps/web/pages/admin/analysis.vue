<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { Plus, Trash2 } from 'lucide-vue-next'
import type { ArticleSummary } from '~/types/api'

type AdminArticle = ArticleSummary & { id: number }

const { request, restore } = useAuth()
const items = ref<AdminArticle[]>([])
const message = ref('')
const form = reactive({ title: '', category: '选择题规律', summary: '', html: '' })

async function load() {
  items.value = await request<AdminArticle[]>('/admin/analysis')
}

onMounted(async () => {
  restore()
  await load()
})

async function create() {
  message.value = ''
  try {
    await request('/admin/analysis', { method: 'POST', body: { ...form } })
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
  await request(`/admin/analysis/${id}`, { method: 'DELETE' })
  await load()
}

async function toggleStatus(item: AdminArticle) {
  const status = item.status === 'hidden' ? 'published' : 'hidden'
  try {
    await request(`/admin/analysis/${item.id}`, { method: 'PATCH', body: { status } })
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
    <div class="section-heading"><div><span class="section-kicker">分析文章</span><h2>共 {{ items.length }} 篇</h2></div></div>

    <form class="admin-form" @submit.prevent="create">
      <label><span>标题</span><input v-model="form.title" required /></label>
      <label><span>分类</span><input v-model="form.category" /></label>
      <label class="wide"><span>摘要</span><input v-model="form.summary" /></label>
      <label class="wide"><span>正文 HTML</span><textarea v-model="form.html" rows="4"></textarea></label>
      <button class="primary-button" type="submit"><Plus :size="15" />新增分析</button>
    </form>
    <p v-if="message" class="admin-meta">{{ message }}</p>

    <ul class="record-list">
      <li v-for="item in items" :key="item.id">
        <span class="record-type">{{ item.category }}</span>
        <span class="record-title">{{ item.title }}</span>
        <button type="button" class="ghost-button small" :class="{ hidden: item.status === 'hidden' }" @click="toggleStatus(item)">{{ item.status === 'hidden' ? '已隐藏' : '已发布' }}</button>
        <select class="comment-mode-select" :value="item.commentMode ?? 'open'" @change="setCommentMode(item, $event)">
          <option value="open">评论·自动发布</option>
          <option value="review">评论·审核后发布</option>
          <option value="closed">禁止评论</option>
        </select>
        <button type="button" class="icon-button" aria-label="删除" @click="remove(item.id)"><Trash2 :size="14" /></button>
      </li>
    </ul>
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