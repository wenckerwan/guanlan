<script setup lang="ts">
import { onMounted, ref } from 'vue'
import type { ProgressRecord, StudyStats } from '~/types/api'

const { request, restore } = useAuth()
const items = ref<ProgressRecord[]>([])
const stats = ref<StudyStats | null>(null)
const loading = ref(true)
const error = ref('')

onMounted(async () => {
  await restore()
  try {
    const [progress, summary] = await Promise.all([
      request<ProgressRecord[]>('/study/progress'),
      request<StudyStats>('/study/stats'),
    ])
    items.value = progress
    stats.value = summary
  } catch {
    error.value = '学习进度暂时无法加载，请刷新重试。'
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

      <p v-if="error" role="alert">{{ error }}</p>
      <section v-if="stats?.currentAffairs" class="module-stats">
        <div class="section-heading compact"><div><span class="section-kicker">当前生效题目版本</span><h2>时政练习完成度</h2></div></div>
        <p>{{ stats.currentAffairs.uniqueQuestionCount }}/{{ stats.currentAffairs.questionCount }} 题 · {{ stats.currentAffairs.completion }}%</p>
        <span class="progress-line"><i :style="{ width: `${stats.currentAffairs.completion}%` }"></i></span>
        <p>首次答对 {{ stats.currentAffairs.firstCorrectCount }} 题（{{ stats.currentAffairs.firstAccuracy }}%）；最近答对 {{ stats.currentAffairs.latestCorrectCount }} 题（{{ stats.currentAffairs.latestAccuracy }}%）。当前版本重答 {{ stats.currentAffairs.repeatCount }} 次。</p>
        <NuxtLink class="primary-link" to="/practice/current-affairs">继续练习</NuxtLink>
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
      <div v-if="!loading && !items.length && !stats?.currentAffairs?.submissionCount" class="empty-state">还没有做题记录，去真题页练一套吧。</div>
      <NuxtLink class="back-link" to="/me">返回个人中心</NuxtLink>
    </main>
    <SiteFooter />
  </div>
</template>
