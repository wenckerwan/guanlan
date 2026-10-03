<script setup lang="ts">
import { ClipboardList } from 'lucide-vue-next'
import type { Mock } from '~/types/api'

useHead({ title: '模拟押题｜观澜考研政治知识库', meta: [{ name: 'description', content: '按真题规律命制的 2027 考研政治全真模拟卷，含答案与解析。' }] })

const { data } = await useApiFetch<Mock[]>('/mocks', [])
const items = computed(() => data.value ?? [])
</script>

<template>
  <div class="site-shell">
    <SiteHeader />
    <main class="page-wrap inner-page">
      <Breadcrumbs />
      <section class="page-hero">
        <div class="eyebrow"><ClipboardList :size="14" />模拟押题</div>
        <h1>按真题规律命制，<br class="mobile-only" />按考场时间作答</h1>
        <p>共 {{ items.length }} 套模拟卷，每套 16 单选 + 17 多选 + 5 分析题，满分 100 分。</p>
      </section>
      <div class="article-grid">
        <NuxtLink v-for="mock in items" :key="mock.slug" :to="`/mocks/${mock.slug}`" class="article-card">
          <span class="article-copy">
            <strong>{{ mock.title }}</strong>
            <small>{{ mock.summary }}</small>
            <em>{{ mock.questionCount }} 题 · {{ mock.totalScore }} 分 · {{ mock.durationMinutes }} 分钟 · 含答案 {{ mock.answeredCount }} 题</em>
          </span>
        </NuxtLink>
      </div>
      <div v-if="!items.length" class="empty-state">暂无模拟卷。</div>
      <NuxtLink class="back-link" to="/">返回首页</NuxtLink>
    </main>
    <SiteFooter />
  </div>
</template>