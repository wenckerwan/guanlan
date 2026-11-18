<script setup lang="ts">
import { computed, ref, onMounted } from 'vue'
import { ArrowLeft, CheckCircle, Clock, RotateCcw, TrendingUp, BookOpen } from 'lucide-vue-next'

const route = useRoute()
const code = String(route.params.code)
const { request, isLoggedIn, restore } = useAuth()
if (import.meta.client) restore()

interface ReviewSummary {
  total: number
  new: number
  reviewing: number
  mastered: number
  snoozed: number
  dueToday: number
  overdue: number
  accuracy: number
  reviewCount: number
}

const summary = ref<ReviewSummary | null>(null)
const loading = ref(true)
const error = ref('')

async function loadSummary() {
  if (!isLoggedIn.value) {
    error.value = '请先登录查看复习概览'
    loading.value = false
    return
  }

  try {
    loading.value = true
    error.value = ''
    const data = await request<ReviewSummary>(`/mistakes/students/${code}/review-summary`)
    summary.value = data
  } catch (err) {
    const status = (err as { statusCode?: number } | null)?.statusCode
    error.value = status === 403 ? '当前账号无法查看这份复习记录。' : '复习概览暂时加载失败。'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  loadSummary()
})

const accuracyPercent = computed(() => {
  if (!summary.value) return 0
  return Math.round(summary.value.accuracy * 100)
})
const dueCount = computed(() => (summary.value?.dueToday ?? 0) + (summary.value?.overdue ?? 0))
const bookPath = `/mistakes/${code}`

useHead(() => ({ title: `复习概览 - 考生 ${code}｜观澜` }))
</script>

<template>
  <div class="site-shell">
    <SiteHeader />
    <main class="page-wrap inner-page library-page">
      <Breadcrumbs parent-name="错题本" :parent-path="bookPath" />

      <section class="library-heading">
        <h1>错题复习概览</h1>
        <p>基于间隔重复算法，帮助你高效掌握每道错题</p>
      </section>

      <p v-if="loading" class="library-state" role="status">正在加载复习概览…</p>
      <div v-else-if="error" class="library-state">
        <p role="alert">{{ error }}</p>
        <button v-if="isLoggedIn" class="ghost-button" type="button" @click="loadSummary"><RotateCcw :size="16" aria-hidden="true" />重试</button>
        <NuxtLink v-else class="ghost-button" :to="{ path: '/login', query: { redirect: route.fullPath } }">去登录</NuxtLink>
      </div>

      <template v-else-if="summary">
      <section class="action-section" aria-labelledby="review-action">
        <div>
          <h2 id="review-action">{{ dueCount ? `${dueCount} 道待复习` : '今天没有待复习的错题' }}</h2>
          <p v-if="summary.overdue" class="warning">{{ summary.dueToday }} 道今日到期 · {{ summary.overdue }} 道已逾期</p>
        </div>
        <NuxtLink v-if="dueCount" :to="bookPath" class="primary-button">
          <RotateCcw :size="15" aria-hidden="true" />前往错题本开始复习
        </NuxtLink>
      </section>

      <section class="review-stats" aria-label="复习统计">
        <div class="stat-card primary">
          <div class="stat-icon"><Clock :size="24" /></div>
          <div class="stat-content">
            <strong>{{ dueCount }}</strong>
            <small>待复习</small>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon"><CheckCircle :size="24" /></div>
          <div class="stat-content">
            <strong>{{ summary.mastered }}</strong>
            <small>已掌握</small>
            <p class="stat-note">占比 {{ summary.total ? Math.round((summary.mastered / summary.total) * 100) : 0 }}%</p>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon"><TrendingUp :size="24" /></div>
          <div class="stat-content">
            <strong>{{ accuracyPercent }}%</strong>
            <small>正确率</small>
            <p class="stat-note">共复习 {{ summary.reviewCount }} 次</p>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon"><BookOpen :size="24" /></div>
          <div class="stat-content">
            <strong>{{ summary.total }}</strong>
            <small>总错题数</small>
          </div>
        </div>
      </section>

      <section class="status-breakdown">
        <h2>复习进度分布</h2>
        <div class="status-grid">
          <div class="status-item">
            <span class="status-label">新题目</span>
            <strong>{{ summary.new }}</strong>
            <small>尚未开始复习</small>
          </div>
          <div class="status-item">
            <span class="status-label">复习中</span>
            <strong>{{ summary.reviewing }}</strong>
            <small>正在巩固记忆</small>
          </div>
          <div class="status-item">
            <span class="status-label">已掌握</span>
            <strong>{{ summary.mastered }}</strong>
            <small>连续答对 3 次</small>
          </div>
          <div class="status-item">
            <span class="status-label">已暂停</span>
            <strong>{{ summary.snoozed }}</strong>
            <small>手动延后复习</small>
          </div>
        </div>
      </section>

      </template>

      <details class="help-text">
        <summary>复习间隔规则</summary>
        <ul>
          <li><strong>答错：</strong>1 天后复习</li>
          <li><strong>第 1 次答对：</strong>3 天后复习</li>
          <li><strong>第 2 次答对：</strong>7 天后复习</li>
          <li><strong>第 3 次答对：</strong>14 天后复习，标记为已掌握</li>
        </ul>
      </details>

      <NuxtLink class="back-link" :to="bookPath">
        <ArrowLeft :size="15" />返回错题本
      </NuxtLink>
    </main>
    <SiteFooter />
  </div>
</template>

<style scoped>
.review-stats {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
  margin: 16px 0 28px;
}

.stat-card {
  background: var(--gl-surface);
  border: 1px solid var(--line);
  border-radius: var(--gl-radius-card);
  padding: 14px;
  display: flex;
  gap: 10px;
  align-items: flex-start;
}

.stat-card.primary {
  border-color: var(--primary);
  background: var(--primary-light, rgba(59, 130, 246, 0.05));
}

.stat-icon {
  color: var(--primary);
  flex-shrink: 0;
}

.stat-content {
  flex: 1;
}

.stat-content strong {
  display: block;
  font-size: 2rem;
  font-weight: 700;
  color: var(--text-primary);
  line-height: 1.2;
}

.stat-content small {
  display: block;
  color: var(--muted);
  margin-top: 0.25rem;
}

.stat-note {
  margin-top: 0.5rem;
  font-size: 0.875rem;
  color: var(--text-muted);
}

.action-section .warning { color: var(--gold); }

.status-breakdown {
  margin: 3rem 0;
}

.status-breakdown h2 {
  font-size: 1.25rem;
  margin-bottom: 1rem;
}

.status-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
}

.status-item {
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-radius: var(--gl-radius-control);
  padding: 1rem;
  text-align: center;
}

.status-label {
  display: block;
  font-size: 0.875rem;
  color: var(--muted);
  margin-bottom: 0.5rem;
}

.status-item strong {
  display: block;
  font-size: 1.5rem;
  color: var(--primary);
  margin-bottom: 0.25rem;
}

.status-item small {
  display: block;
  font-size: 0.75rem;
  color: var(--text-muted);
}

.action-section {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
  padding: 18px;
  background: var(--gl-surface);
  border: 1px solid var(--line);
  border-radius: var(--gl-radius-card);
}

.action-section h2 {
  font-size: 1.25rem;
  margin: 0;
}

.action-section p {
  margin: 6px 0 0;
}

.help-text {
  margin: 24px 0 0;
  padding: 0 16px;
  background: var(--gl-surface);
  border: 1px solid var(--line);
  border-radius: var(--gl-radius-control);
}

.help-text summary { cursor: pointer; padding-block: 13px; font-weight: 600; }

.help-text ul {
  list-style: none;
  padding: 0;
}

.help-text li {
  padding: 0.5rem 0;
  color: var(--muted);
}

@media (max-width: 640px) {
  .review-stats, .status-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .stat-icon { display: none; }
}
</style>

