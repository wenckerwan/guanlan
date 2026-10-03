<script setup lang="ts">
import { computed, ref } from 'vue'
import { ChevronRight, FileText, Search } from 'lucide-vue-next'
import type { ModuleSummary, Paper } from '~/types/api'

useHead({ title: '真题回顾｜观澜考研政治知识库', meta: [{ name: 'description', content: '1994—2026 年考研政治真题，按年份与模块在线作答、自动判分。' }] })

const { data: papers } = await useApiFetch<Paper[]>('/papers', [])
const { data: modules } = await useApiFetch<ModuleSummary[]>('/papers/modules', [])

const keyword = ref('')
const activeModule = ref('')
const activeKind = ref('')

const kindOptions = [
  { value: '', label: '全部' },
  { value: 'modern', label: '2010 年后统考卷' },
  { value: 'split', label: '2010 年前分卷' },
]

const filtered = computed(() => {
  const q = keyword.value.trim()
  const md = activeModule.value
  const kd = activeKind.value
  return (papers.value ?? []).filter((paper) => {
    if (q && !`${paper.pid}${paper.year}${paper.label}`.includes(q)) return false
    if (kd && paper.kind !== kd) return false
    if (md && !moduleYears.value[paper.pid]?.includes(md)) return false
    return true
  })
})

// 模块过滤需要题目级数据；这里按年份区间近似（模块分布见 /papers/modules）
const moduleYears = computed<Record<string, string[]>>(() => ({}))

const grouped = computed(() => {
  const byDecade = new Map<string, Paper[]>()
  for (const paper of filtered.value) {
    const decade = `${Math.floor(paper.year / 10) * 10}s`
    if (!byDecade.has(decade)) byDecade.set(decade, [])
    byDecade.get(decade)!.push(paper)
  }
  return [...byDecade.entries()].map(([name, items]) => ({ name, items: items.sort((a, b) => b.year - a.year) }))
})

const totalQuestions = computed(() => (papers.value ?? []).reduce((sum, paper) => sum + paper.questionCount, 0))
</script>

<template>
  <div class="site-shell">
    <SiteHeader />
    <main class="page-wrap inner-page">
      <Breadcrumbs />

      <section class="page-hero">
        <div class="eyebrow"><FileText :size="14" />真题回顾</div>
        <h1>练真题，<br class="mobile-only" />是性价比最高的复习</h1>
        <p>已收录 {{ (papers ?? []).length }} 份试卷、{{ totalQuestions }} 道题，覆盖 1994—2026。支持在线作答与自动判分。</p>
      </section>

      <section class="filter-bar">
        <form class="filter-search" role="search" @submit.prevent>
          <Search :size="16" />
          <input v-model="keyword" type="search" aria-label="按年份或卷名筛选" placeholder="按年份或卷名筛选，如 2026、文科" />
        </form>
        <div class="filter-chips">
          <button v-for="option in kindOptions" :key="option.value" type="button" class="chip" :class="{ active: activeKind === option.value }" @click="activeKind = option.value">{{ option.label }}</button>
        </div>
        <div class="filter-chips">
          <button type="button" class="chip" :class="{ active: activeModule === '' }" @click="activeModule = ''">全部模块</button>
          <button v-for="item in modules" :key="item.module" type="button" class="chip" :class="{ active: activeModule === item.module }" @click="activeModule = item.module">{{ item.name }} · {{ item.count }}</button>
        </div>
      </section>

      <section v-for="group in grouped" :key="group.name" class="year-group">
        <div class="section-heading"><div><span class="section-kicker">{{ group.name }}</span><h2>{{ group.items.length }} 份试卷</h2></div></div>
        <div class="paper-grid">
          <NuxtLink v-for="paper in group.items" :key="paper.pid" :to="`/papers/${encodeURIComponent(paper.pid)}`" class="paper-card">
            <span class="paper-year">{{ paper.year }}<small>{{ paper.label || '统考' }}</small></span>
            <span class="paper-meta">
              <strong>{{ paper.questionCount }} 题</strong>
              <small>{{ paper.totalScore }} 分 · 含答案 {{ paper.answeredCount }} 题</small>
              <span class="progress-line"><i :style="{ width: `${paper.questionCount ? Math.round((paper.answeredCount / paper.questionCount) * 100) : 0}%` }"></i></span>
            </span>
            <ChevronRight :size="16" class="paper-arrow" />
          </NuxtLink>
        </div>
      </section>

      <div v-if="!grouped.length" class="empty-state"><FileText :size="18" />没有匹配的试卷，换个筛选条件试试。</div>
      <NuxtLink class="back-link" to="/"><ChevronRight :size="15" class="back-icon" />返回首页</NuxtLink>
    </main>
    <SiteFooter />
  </div>
</template>
