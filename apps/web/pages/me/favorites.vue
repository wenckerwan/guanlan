<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Trash2 } from 'lucide-vue-next'
import type { Favorite } from '~/types/api'

const { request, restore } = useAuth()
const items = ref<Favorite[]>([])
const loading = ref(true)

onMounted(async () => {
  restore()
  try {
    items.value = await request<Favorite[]>('/study/favorites')
  } finally {
    loading.value = false
  }
})

async function remove(id: number) {
  await request(`/study/favorites/${id}`, { method: 'DELETE' })
  items.value = items.value.filter((item) => item.id !== id)
}

const grouped = computed(() => items.value)
</script>

<template>
  <div class="site-shell">
    <SiteHeader />
    <main class="page-wrap inner-page">
      <Breadcrumbs />
      <section class="page-hero">
        <div class="eyebrow">收藏</div>
        <h1>我的收藏</h1>
        <p>共 {{ grouped.length }} 条。</p>
      </section>

      <ul class="record-list">
        <li v-for="item in grouped" :key="item.id">
          <span class="record-type">{{ targetTypeLabel(item.targetType) }}</span>
          <a v-if="item.url && isSubAppUrl(item.url)" :href="item.url" class="record-title">{{ item.title || item.targetId }}</a>
          <NuxtLink v-else-if="item.url" :to="item.url" class="record-title">{{ item.title || item.targetId }}</NuxtLink>
          <span v-else class="record-title">{{ item.title || item.targetId }}</span>
          <time>{{ item.createdAt.slice(0, 10) }}</time>
          <button type="button" class="icon-button" aria-label="取消收藏" @click="remove(item.id)"><Trash2 :size="14" /></button>
        </li>
      </ul>
      <div v-if="!loading && !grouped.length" class="empty-state">还没有收藏，去真题或时政页点收藏试试。</div>
      <NuxtLink class="back-link" to="/me">返回个人中心</NuxtLink>
    </main>
    <SiteFooter />
  </div>
</template>