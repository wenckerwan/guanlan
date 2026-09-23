<script setup lang="ts">
import { onMounted, ref } from 'vue'
import type { ProgressRecord } from '~/types/api'

const { request, restore } = useAuth()
const items = ref<ProgressRecord[]>([])
const loading = ref(true)

onMounted(async () => {
  restore()
  try {
    items.value = await request<ProgressRecord[]>('/study/progress')
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="site-shell">
    <SiteHeader />
    <main class="page-wrap inner-page">
      <Breadcrumbs />
      <section class="page-hero">
        <div class="eyebrow">进度</div>
        <h1>学习进度</h1>
        <p>共 {{ items.length }} 条记录，按最近作答排序。</p>
      </section>

      <ul class="record-list">
        <li v-for="item in items" :key="item.id">
          <span class="record-type">{{ item.scope }}</span>
          <span class="record-title">{{ item.label || item.ref }}</span>
          <span class="progress-line"><i :style="{ width: `${item.progress}%` }"></i></span>
          <b>{{ item.correctCount }} 对 / {{ item.wrongCount }} 错</b>
          <time>{{ item.lastSeenAt.slice(0, 10) }}</time>
        </li>
      </ul>
      <div v-if="!loading && !items.length" class="empty-state">还没有做题记录，去真题页练一套吧。</div>
      <NuxtLink class="back-link" to="/me">返回个人中心</NuxtLink>
    </main>
  </div>
</template>