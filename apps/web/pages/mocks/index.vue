<script setup lang="ts">
import { ChevronRight, ClipboardList } from 'lucide-vue-next'
import type { Mock } from '~/types/api'

useHead({ title: '模拟押题｜观澜考研政治知识库', meta: [{ name: 'description', content: '按真题规律命制的 2027 考研政治全真模拟卷，含答案与解析。' }] })

const { data, pending, error, refresh } = await useApiFetch<Mock[]>('/mocks', [])
const items = computed(() => data.value ?? [])
</script>

<template>
  <div class="site-shell">
    <SiteHeader />
    <main class="page-wrap inner-page library-page">
      <Breadcrumbs parent-name="模拟押题" parent-path="/mocks" />
      <section class="library-heading">
        <h1>模拟押题</h1>
        <p v-if="items.length">共 {{ items.length }} 套模拟卷</p>
      </section>
      <p v-if="pending" class="library-state" role="status">正在加载模拟卷…</p>
      <div v-else-if="error" class="library-state">
        <p role="alert">模拟卷加载失败。</p><button class="ghost-button" type="button" @click="refresh()">重新加载</button>
      </div>
      <p v-else-if="!items.length" class="library-state" role="status">暂无模拟卷。</p>
      <ul v-else class="library-list" aria-label="模拟卷列表">
        <li v-for="mock in items" :key="mock.slug">
          <NuxtLink :to="`/mocks/${encodeURIComponent(mock.slug)}`" class="library-row">
            <span class="library-icon"><ClipboardList :size="18" aria-hidden="true" /></span>
            <span class="library-copy">
              <strong>{{ mock.title }}</strong>
              <span v-if="mock.summary">{{ mock.summary }}</span>
              <small>{{ mock.questionCount }} 题 · {{ mock.totalScore }} 分 · {{ mock.durationMinutes }} 分钟 · 含答案 {{ mock.answeredCount }} 题</small>
            </span>
            <ChevronRight :size="18" aria-hidden="true" />
          </NuxtLink>
        </li>
      </ul>
      <NuxtLink class="back-link" to="/">返回首页</NuxtLink>
    </main>
    <SiteFooter />
  </div>
</template>
