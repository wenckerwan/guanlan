<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { Bookmark, NotebookPen, Target, UserRound } from 'lucide-vue-next'
import type { StudyStats } from '~/types/api'

useHead({ title: '个人中心｜观澜考研政治知识库' })

const { user, isLoggedIn, ready, restore, request } = useAuth()

// SSR 已解析 Cookie 登录态，这里只做兜底跳转。
await restore()
if (!isLoggedIn.value) {
  await navigateTo({ path: '/login', query: { redirect: '/me' } })
}

onMounted(() => restore())

const { data: stats } = await useAsyncData('me-stats', async () => {
  if (!import.meta.client) return null
  try {
    return await request<StudyStats>('/study/stats')
  } catch {
    return null
  }
}, { watch: [ready] })

const cards = computed(() => [
  { label: '累计作答', value: stats.value?.total ?? 0, tone: 'jade' },
  { label: '正确率', value: `${stats.value?.accuracy ?? 0}%`, tone: 'red' },
  { label: '收藏', value: stats.value?.favorites ?? 0, tone: 'gold' },
  { label: '笔记', value: stats.value?.notes ?? 0, tone: 'blue' },
])
</script>

<template>
  <div class="site-shell">
    <SiteHeader />
    <main class="page-wrap inner-page">
      <Breadcrumbs />
      <section class="page-hero">
        <div class="eyebrow"><UserRound :size="14" />个人中心</div>
        <h1>{{ user?.displayName || '我的观澜' }}</h1>
        <p>{{ user?.email || '登录后查看学习记录' }}</p>
      </section>

      <section class="account-id">
        <div>
          <span class="section-kicker">我的账号 ID</span>
          <strong>{{ user?.mistakeCode || '未分配' }}</strong>
          <small>把这串 ID 发给管理员，即可绑定你的专属错题本；绑定后仅你和管理员可见。</small>
        </div>
      </section>

      <section class="stat-row">
        <div v-for="card in cards" :key="card.label" class="stat-cell" :class="card.tone">
          <strong>{{ card.value }}</strong><small>{{ card.label }}</small>
        </div>
      </section>

      <section class="me-links">
        <NuxtLink to="/me/favorites"><Bookmark :size="16" />我的收藏<span>收藏题目、文章与热点</span></NuxtLink>
        <NuxtLink to="/me/notes"><NotebookPen :size="16" />我的笔记<span>针对具体题目与文章的批注</span></NuxtLink>
        <NuxtLink to="/me/progress"><Target :size="16" />学习进度<span>做题记录与掌握程度</span></NuxtLink>
      </section>

      <section v-if="stats?.byModule?.length" class="module-stats">
        <div class="section-heading compact"><div><span class="section-kicker">按模块</span><h2>我的正确率</h2></div></div>
        <ul>
          <li v-for="row in stats.byModule" :key="row.module">
            <span>{{ row.module || '未归类' }}</span>
            <span class="progress-line"><i :style="{ width: `${row.accuracy}%` }"></i></span>
            <b>{{ row.right }}/{{ row.total }} · {{ row.accuracy }}%</b>
          </li>
        </ul>
      </section>

      <NuxtLink v-if="!isLoggedIn" class="primary-link" to="/login">前往登录</NuxtLink>
    </main>
    <SiteFooter />
  </div>
</template>