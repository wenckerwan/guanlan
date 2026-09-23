<script setup lang="ts">
import { onMounted, ref } from 'vue'
import type { AdminOverview } from '~/types/api'

definePageMeta({ name: 'admin-overview' })

const { request, restore } = useAuth()
const data = ref<AdminOverview | null>(null)

onMounted(async () => {
  restore()
  try {
    data.value = await request<AdminOverview>('/admin/overview')
  } catch {
    data.value = null
  }
})

const labels: Record<string, string> = {
  users: '用户',
  papers: '试卷',
  questions: '题目',
  analysis_articles: '真题分析',
  hotspots: '时政热点',
  predictions: '时政预测',
  mistake_items: '错题',
  attempts: '作答记录',
  favorites: '收藏',
  notes: '笔记',
}
</script>

<template>
  <section class="admin-section">
    <div class="section-heading"><div><span class="section-kicker">数据总览</span><h2>内容与用户</h2></div></div>
    <div class="stat-row">
      <div v-for="(value, key) in data?.counts ?? {}" :key="key" class="stat-cell">
        <strong>{{ value }}</strong><small>{{ labels[key] ?? key }}</small>
      </div>
    </div>
    <p v-if="data" class="admin-meta">今日新增用户：{{ data.todayUsers }}</p>

    <div class="section-heading compact"><div><span class="section-kicker">最近注册</span><h2>新用户</h2></div></div>
    <ul class="record-list">
      <li v-for="item in data?.recentUsers ?? []" :key="item.id">
        <span class="record-type">{{ item.role }}</span>
        <span class="record-title">{{ item.displayName || item.email }}</span>
        <time>{{ item.createdAt.slice(0, 10) }}</time>
      </li>
    </ul>
  </section>
</template>