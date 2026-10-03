<script setup lang="ts">
import { computed, ref, onMounted } from 'vue'
import { ArrowLeft, Calendar, CheckCircle, Clock, RotateCcw, TrendingUp, BookOpen } from 'lucide-vue-next'

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
  } catch (err: any) {
    error.value = err.message || '加载失败'
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

useHead(() => ({ title: `复习概览 - 考生 ${code}｜观澜` }))
</script>

<template>
  <div class="site-shell">
    <SiteHeader />
    <main class="page-wrap inner-page">
      <Breadcrumbs />

      <section class="page-hero">
        <div class="eyebrow"><RotateCcw :size="14" />复习系统</div>
        <h1>错题复习概览</h1>
        <p>基于间隔重复算法，帮助你高效掌握每道错题</p>
      </section>

      <div v-if="loading" class="loading-state">加载中...</div>
      <div v-else-if="error" class="error-state">{{ error }}</div>

      <section v-else-if="summary" class="review-stats">
        <div class="stat-card primary">
          <div class="stat-icon"><Clock :size="24" /></div>
          <div class="stat-content">
            <strong>{{ summary.dueToday + summary.overdue }}</strong>
            <small>今日待复习</small>
            <p v-if="summary.overdue > 0" class="stat-note warning">
              其中 {{ summary.overdue }} 道已逾期
            </p>
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

      <section v-if="summary" class="status-breakdown">
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

      <section v-if="summary && (summary.dueToday + summary.overdue) > 0" class="action-section">
        <h2>开始复习</h2>
        <p>今天有 {{ summary.dueToday + summary.overdue }} 道错题需要复习</p>
        <NuxtLink :to="`/mistakes/${code}`" class="primary-button">
          <RotateCcw :size="15" />前往错题本开始复习
        </NuxtLink>
      </section>

      <div class="help-text">
        <h3>复习间隔规则</h3>
        <ul>
          <li><strong>答错：</strong>1 天后复习</li>
          <li><strong>第 1 次答对：</strong>3 天后复习</li>
          <li><strong>第 2 次答对：</strong>7 天后复习</li>
          <li><strong>第 3 次答对：</strong>14 天后复习，标记为已掌握</li>
        </ul>
      </div>

      <NuxtLink class="back-link" :to="`/mistakes/${code}`">
        <ArrowLeft :size="15" />返回错题本
      </NuxtLink>
    </main>
    <SiteFooter />
  </div>
</template>

<style scoped>
.review-stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 1.5rem;
  margin: 2rem 0;
}

.stat-card {
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 1.5rem;
  display: flex;
  gap: 1rem;
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
  color: var(--text-secondary);
  margin-top: 0.25rem;
}

.stat-note {
  margin-top: 0.5rem;
  font-size: 0.875rem;
  color: var(--text-muted);
}

.stat-note.warning {
  color: var(--warning, #f59e0b);
}

.status-breakdown {
  margin: 3rem 0;
}

.status-breakdown h2 {
  font-size: 1.25rem;
  margin-bottom: 1rem;
}

.status-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 1rem;
}

.status-item {
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-radius: 6px;
  padding: 1rem;
  text-align: center;
}

.status-label {
  display: block;
  font-size: 0.875rem;
  color: var(--text-secondary);
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
  margin: 3rem 0;
  text-align: center;
  padding: 2rem;
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-radius: 8px;
}

.action-section h2 {
  font-size: 1.25rem;
  margin-bottom: 0.5rem;
}

.action-section p {
  color: var(--text-secondary);
  margin-bottom: 1.5rem;
}

.help-text {
  margin: 3rem 0;
  padding: 1.5rem;
  background: var(--info-bg, rgba(59, 130, 246, 0.05));
  border: 1px solid var(--info-border, rgba(59, 130, 246, 0.2));
  border-radius: 8px;
}

.help-text h3 {
  font-size: 1rem;
  margin-bottom: 1rem;
  color: var(--text-primary);
}

.help-text ul {
  list-style: none;
  padding: 0;
}

.help-text li {
  padding: 0.5rem 0;
  color: var(--text-secondary);
}

.loading-state,
.error-state {
  padding: 3rem;
  text-align: center;
  color: var(--text-secondary);
}

.error-state {
  color: var(--error, #ef4444);
}
</style>

