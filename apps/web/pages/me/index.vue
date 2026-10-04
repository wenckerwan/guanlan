<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { ArrowRight, Bookmark, Globe2, NotebookPen, Target, UserRound } from 'lucide-vue-next'
import type { MayuanSummary, StudyStats } from '~/types/api'

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

// 马原学习摘要（观澜侧 §3.2）：自评与练习正确率分开标注，自评不叫「掌握率」。
const { data: mayuan } = await useAsyncData('me-mayuan-summary', async () => {
  if (!import.meta.client) return null
  try {
    return await request<MayuanSummary | null>('/study/mayuan/summary')
  } catch {
    return null
  }
}, { watch: [ready] })

const config = useRuntimeConfig()
const mayuanBase = computed(() => (config.public.mayuanBase as string) || '/mayuan/')
const mayuanAccuracy = computed(() => {
  const s = mayuan.value
  if (!s || !s.practiceAttemptCount) return null
  return Math.round((s.practiceCorrectCount / s.practiceAttemptCount) * 100)
})
const mayuanResumeHref = computed(() => {
  const rt = mayuan.value?.resumeTarget
  if (rt?.view === 'map' && rt?.nodeId) return `${mayuanBase.value}#map/${encodeURIComponent(rt.nodeId)}`
  return mayuanBase.value
})
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

      <section v-if="mayuan" class="mayuan-summary">
        <div class="section-heading compact"><div><span class="section-kicker">马原知识宇宙</span><h2>学习摘要</h2></div>
          <a :href="mayuanResumeHref" class="mayuan-resume">继续学习<ArrowRight :size="14" /></a>
        </div>
        <div class="mayuan-grid">
          <div class="mayuan-cell"><strong>{{ mayuan.visitedConceptCount }}</strong><small>已探索概念</small></div>
          <div class="mayuan-cell"><strong>{{ mayuan.selfAssessedMasteredCount }}</strong><small>自评已掌握<em>（主观自评）</em></small></div>
          <div class="mayuan-cell"><strong>{{ mayuanAccuracy === null ? '—' : `${mayuanAccuracy}%` }}</strong><small>练习正确率<em>（{{ mayuan.practiceCorrectCount }}/{{ mayuan.practiceAttemptCount }}）</em></small></div>
          <div class="mayuan-cell"><strong>{{ mayuan.dueReviewCount }}</strong><small>待复习</small></div>
        </div>
      </section>
      <section v-else class="mayuan-summary mayuan-empty">
        <div class="mayuan-empty-inner">
          <Globe2 :size="18" />
          <span>还没有马原学习记录。</span>
          <a :href="mayuanBase" class="mayuan-resume">去探索马原宇宙<ArrowRight :size="14" /></a>
        </div>
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

<style scoped>
.mayuan-summary { margin-top: 32px; padding: 20px 22px; border: 1px solid var(--line); border-radius: 4px; background: #fff; }
.mayuan-summary .section-heading.compact { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.mayuan-resume { display: inline-flex; align-items: center; gap: 5px; color: var(--jade); font-size: 13px; text-decoration: none; }
.mayuan-resume:hover { text-decoration: underline; }
.mayuan-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
.mayuan-cell strong { display: block; font-size: 22px; color: var(--ink); font-weight: 600; }
.mayuan-cell small { display: block; margin-top: 4px; color: var(--muted); font-size: 12px; }
.mayuan-cell small em { font-style: normal; color: #a9a49a; font-size: 11px; }
.mayuan-empty-inner { display: flex; align-items: center; gap: 10px; color: var(--muted); font-size: 13px; }
.mayuan-empty-inner svg { color: var(--gold); flex: none; }
@media (max-width: 640px) { .mayuan-grid { grid-template-columns: repeat(2, 1fr); } }
</style>