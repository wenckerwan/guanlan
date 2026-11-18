<script setup lang="ts">
import { computed, ref } from 'vue'
import { ChevronRight, FileText, Search } from 'lucide-vue-next'
import type { ModuleSummary, Paper } from '~/types/api'

useHead({ title: '真题回顾｜观澜考研政治知识库', meta: [{ name: 'description', content: '1994—2026 年考研政治真题，按年份与模块在线作答、自动判分。' }] })

const keyword = ref('')
const activeModule = ref('')
const activeKind = ref('')
const paperPath = computed(() => withQuery('/papers', { module: activeModule.value }))
const { data: papers, pending, error, refresh } = await useApiFetch<Paper[]>(paperPath, [])
const { data: modules } = await useApiFetch<ModuleSummary[]>('/papers/modules', [])
const hasFilters = computed(() => Boolean(keyword.value || activeModule.value || activeKind.value))

function resetFilters() {
  keyword.value = ''
  activeModule.value = ''
  activeKind.value = ''
}

const kindOptions = [
  { value: '', label: '全部' },
  { value: 'modern', label: '2010 年后统考卷' },
  { value: 'split', label: '2010 年前分卷' },
]

const filtered = computed(() => {
  const q = keyword.value.trim()
  const kd = activeKind.value
  return (papers.value ?? []).filter((paper) => {
    if (q && !`${paper.pid}${paper.year}${paper.label}`.includes(q)) return false
    if (kd && paper.kind !== kd) return false
    return true
  })
})

const grouped = computed(() => {
  const byDecade = new Map<string, Paper[]>()
  for (const paper of filtered.value) {
    const decade = `${Math.floor(paper.year / 10) * 10}s`
    if (!byDecade.has(decade)) byDecade.set(decade, [])
    byDecade.get(decade)!.push(paper)
  }
  return [...byDecade.entries()].sort(([a], [b]) => b.localeCompare(a))
    .map(([name, items]) => ({ name, items: items.sort((a, b) => b.year - a.year) }))
})

const totalQuestions = computed(() => (papers.value ?? []).reduce((sum, paper) => sum + paper.questionCount, 0))
</script>

<template>
  <div class="site-shell">
    <SiteHeader />
    <main class="page-wrap inner-page library-page paper-library">
      <Breadcrumbs parent-name="真题回顾" parent-path="/papers" />

      <section class="library-heading">
        <h1>真题回顾</h1>
        <p v-if="!pending && !error && papers?.length">{{ activeModule ? `本模块涉及 ${papers.length} 份试卷` : `已收录 ${papers.length} 份试卷、${totalQuestions} 道题` }}</p>
      </section>

      <section class="filter-bar">
        <form class="filter-search" role="search" @submit.prevent>
          <Search :size="16" aria-hidden="true" />
          <input v-model="keyword" type="search" aria-label="按年份或卷名筛选" placeholder="按年份或卷名筛选，如 2026、文科" />
        </form>
        <div class="filter-chips" role="group" aria-label="试卷类别">
          <button v-for="option in kindOptions" :key="option.value" type="button" class="chip" :aria-pressed="activeKind === option.value" :class="{ active: activeKind === option.value }" @click="activeKind = option.value">{{ option.label }}</button>
        </div>
        <div v-if="modules?.length" class="filter-chips" role="group" aria-label="学科模块">
          <button type="button" class="chip" :aria-pressed="activeModule === ''" :class="{ active: activeModule === '' }" @click="activeModule = ''">全部模块</button>
          <button v-for="item in modules" :key="item.module" type="button" class="chip" :aria-pressed="activeModule === item.module" :class="{ active: activeModule === item.module }" @click="activeModule = item.module">{{ item.name }} · {{ item.count }}</button>
        </div>
      </section>

      <p v-if="pending" class="library-state" role="status">正在加载真题…</p>
      <div v-else-if="error" class="library-state">
        <p role="alert">真题加载失败。</p><button class="ghost-button" type="button" @click="refresh()">重新加载</button>
      </div>
      <div v-else-if="!grouped.length" class="library-state">
        <p role="status">{{ hasFilters ? '没有匹配的试卷。' : '暂无真题试卷。' }}</p>
        <button v-if="hasFilters" class="ghost-button" type="button" @click="resetFilters">清除筛选</button>
      </div>
      <template v-else>
        <section v-for="group in grouped" :key="group.name" class="year-group">
          <div class="section-heading compact"><div><h2>{{ group.name }}</h2><span class="section-kicker">{{ group.items.length }} 份试卷</span></div></div>
          <ul class="library-list">
            <li v-for="paper in group.items" :key="paper.pid">
              <NuxtLink :to="`/papers/${encodeURIComponent(paper.pid)}`" class="library-row">
                <span class="library-icon"><FileText :size="18" aria-hidden="true" /></span>
                <span class="library-copy">
                  <strong>{{ paper.year }} 年 · {{ paper.label || '统考' }}</strong>
                  <small>{{ paper.questionCount }} 题 · {{ paper.totalScore }} 分 · 含答案 {{ paper.answeredCount }} 题</small>
                </span>
                <ChevronRight :size="18" aria-hidden="true" />
              </NuxtLink>
            </li>
          </ul>
        </section>
      </template>
      <NuxtLink class="back-link" to="/"><ChevronRight :size="15" class="back-icon" />返回首页</NuxtLink>
    </main>
    <SiteFooter />
  </div>
</template>

<style scoped>
.filter-bar { margin-bottom: 20px; padding-bottom: 16px; }
.filter-search { min-height: 44px; }
.filter-search input { min-width: 0; }
.filter-chips .chip { min-height: 44px; white-space: normal; }
</style>
