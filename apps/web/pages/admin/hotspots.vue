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
        <button type="button" class="icon-button" aria-label="删除" @click="remove(item.id)"><Trash2 :size="14" /></button>
      </li>
    </ul>
  </section>
</template>