<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import type { AdminOverview } from '~/types/api'
import { fillTrend, lastNDays, trendTotal } from '~/utils/trend.mjs'

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
  mistake_reviews: '复习记录',
  attempts: '作答记录',
  favorites: '收藏',
  notes: '笔记',
}

const trendSeries = computed(() => fillTrend(data.value?.registrationTrend ?? {}, lastNDays(new Date(), 14)))
const trendMax = computed(() => Math.max(1, ...trendSeries.value.map((item) => item.count)))
const trendSum = computed(() => trendTotal(trendSeries.value))

const attemptSeries = computed(() => fillTrend((data.value as { attemptsTrend?: Record<string, number> } | null)?.attemptsTrend ?? {}, lastNDays(new Date(), 14)))
const attemptMax = computed(() => Math.max(1, ...attemptSeries.value.map((item) => item.count)))
const attemptSum = computed(() => trendTotal(attemptSeries.value))

const statusLabels: Record<string, string> = { published: '已发布', hidden: '已隐藏' }
const statusNames: Record<string, string> = { hotspots: '时政热点', analysis_articles: '真题分析', predictions: '时政预测' }
const recentAudit = computed(() => (data.value as { recentAudit?: { action: string; targetType: string; targetId: string; adminEmail: string; createdAt: string }[] } | null)?.recentAudit ?? [])
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

    <div class="section-heading compact"><div><span class="section-kicker">近 14 天</span><h2>注册趋势（共 {{ trendSum }} 人）</h2></div></div>
    <div class="trend-chart" role="img" aria-label="近 14 天注册趋势柱状图">
      <div v-for="item in trendSeries" :key="item.day" class="trend-col">
        <div class="trend-bar" :style="{ height: `${Math.round((item.count / trendMax) * 100)}%` }" :title="`${item.day}：${item.count}`" />
        <small>{{ item.day.slice(5) }}</small>
      </div>
    </div>

    <div class="section-heading compact"><div><span class="section-kicker">近 14 天</span><h2>作答趋势（共 {{ attemptSum }} 次）</h2></div></div>
    <div class="trend-chart" role="img" aria-label="近 14 天作答趋势柱状图">
      <div v-for="item in attemptSeries" :key="item.day" class="trend-col">
        <div class="trend-bar accent" :style="{ height: `${Math.round((item.count / attemptMax) * 100)}%` }" :title="`${item.day}：${item.count}`" />
        <small>{{ item.day.slice(5) }}</small>
      </div>
    </div>

    <div class="section-heading compact"><div><span class="section-kicker">内容状态</span><h2>上下线分布</h2></div></div>
    <table class="admin-table">
      <thead><tr><th>内容</th><th>已发布</th><th>已隐藏</th></tr></thead>
      <tbody>
        <tr v-for="(dist, key) in (data as { statusCounts?: Record<string, Record<string, number>> } | null)?.statusCounts ?? {}" :key="key">
          <td>{{ statusNames[key] ?? key }}</td>
          <td>{{ dist.published ?? 0 }}</td>
          <td>{{ dist.hidden ?? 0 }}</td>
        </tr>
      </tbody>
    </table>

    <div class="section-heading compact"><div><span class="section-kicker">最近注册</span><h2>新用户</h2></div></div>
    <ul class="record-list">
      <li v-for="item in data?.recentUsers ?? []" :key="item.id">
        <span class="record-type">{{ item.role }}</span>
        <span class="record-title">{{ item.displayName || item.email }}</span>
        <time>{{ item.createdAt.slice(0, 10) }}</time>
      </li>
    </ul>

    <div class="section-heading compact"><div><span class="section-kicker">操作审计</span><h2>最近 5 条</h2></div></div>
    <ul class="record-list">
      <li v-for="(item, index) in recentAudit" :key="index">
        <span class="record-type">{{ item.action }}</span>
        <span class="record-title">{{ item.targetType }}#{{ item.targetId }}</span>
        <span class="admin-meta">{{ item.adminEmail }}</span>
        <time>{{ item.createdAt.slice(0, 16).replace('T', ' ') }}</time>
      </li>
    </ul>
    <p v-if="!recentAudit.length" class="admin-meta">暂无操作记录。</p>
  </section>
</template>

<style scoped>
.trend-chart {
  display: flex;
  align-items: flex-end;
  gap: 0.5rem;
  height: 8rem;
  margin: 1rem 0;
}

.trend-col {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: flex-end;
  height: 100%;
  gap: 0.25rem;
}

.trend-bar {
  width: 100%;
  min-height: 2px;
  background: var(--primary, #3b82f6);
  border-radius: 3px 3px 0 0;
  opacity: 0.85;
}

.trend-bar.accent { background: var(--accent, #10b981); }

.trend-col small {
  font-size: 0.625rem;
  color: var(--text-muted);
  white-space: nowrap;
}
</style>
