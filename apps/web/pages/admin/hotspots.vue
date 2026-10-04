<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { Plus, Trash2 } from 'lucide-vue-next'
import type { ArticleSummary } from '~/types/api'

type AdminArticle = ArticleSummary & { id: number }

const { request, restore } = useAuth()
const items = ref<AdminArticle[]>([])
const message = ref('')
const form = reactive({ title: '', summary: '', period: '', priority: 'A', level: 'S', type: '形势与政策', tag: '', html: '' })

async function load() {
  items.value = await request<AdminArticle[]>('/admin/hotspots')
}

onMounted(async () => {
  restore()
  await load()
})

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
  await request(`/admin/hotspots/${id}`, { method: 'DELETE' })
  await load()
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
    <div class="section-heading"><div><span class="section-kicker">时政管理</span><h2>共 {{ items.length }} 条</h2></div></div>

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

    <ul class="record-list">
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