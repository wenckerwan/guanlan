<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { Trash2 } from 'lucide-vue-next'
import type { Note } from '~/types/api'

const { request, restore } = useAuth()
const items = ref<Note[]>([])
const loading = ref(true)

onMounted(async () => {
  restore()
  try {
    items.value = await request<Note[]>('/study/notes')
  } finally {
    loading.value = false
  }
})

async function remove(id: number) {
  await request(`/study/notes/${id}`, { method: 'DELETE' })
  items.value = items.value.filter((item) => item.id !== id)
}
</script>

<template>
  <div class="site-shell">
    <SiteHeader />
    <main class="page-wrap inner-page">
      <Breadcrumbs />
      <section class="page-hero">
        <div class="eyebrow">笔记</div>
        <h1>我的笔记</h1>
        <p>共 {{ items.length }} 条。</p>
      </section>

      <ul class="note-list">
        <li v-for="item in items" :key="item.id">
          <header>
            <span class="record-type">{{ item.targetType }}</span>
            <strong>{{ item.title || item.targetId }}</strong>
            <time>{{ item.createdAt.slice(0, 10) }}</time>
            <button type="button" class="icon-button" aria-label="删除笔记" @click="remove(item.id)"><Trash2 :size="14" /></button>
          </header>
          <p>{{ item.content }}</p>
        </li>
      </ul>
      <div v-if="!loading && !items.length" class="empty-state">还没有笔记。</div>
      <NuxtLink class="back-link" to="/me">返回个人中心</NuxtLink>
    </main>
  </div>
</template>