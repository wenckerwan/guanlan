<script setup lang="ts">
import { computed, ref } from 'vue'
import { ArrowRight, BookOpen, CalendarDays, ChevronRight, ClipboardList, FileText, Globe2, History, Newspaper, Search, Sparkles, TrendingUp } from 'lucide-vue-next'
import type { HomePayload, LeaderboardEntry } from '~/types/api'
import { sortHotspots } from '~/utils/home.mjs'

useHead({ title: '观澜｜考研政治知识库', meta: [{ name: 'description', content: '观澜考研政治知识库：真题、时政、错题分析与模拟押题一站式复习。' }] })

const config = useRuntimeConfig()
const mayuanBase = computed(() => (config.public.mayuanBase as string) || '/mayuan/')
const historyBase = computed(() => (config.public.historyBase as string) || '/history/')

const universeSections = computed(() => [
  { href: mayuanBase.value, label: '马原知识宇宙', copy: '概念星球 · 关系网络 · 主动回忆', icon: Globe2, tone: 'blue' },
  { href: historyBase.value, label: '近现代史时间实验室', copy: '时间轴探索 · 来源对照 · 排序回忆', icon: History, tone: 'gold' },
  { href: '/meetings/index.html', label: '党史会议专题', copy: '会议汇总 · 精讲速记 · 配套练习', icon: BookOpen, tone: 'red' },
])

const { data: home } = await useApiFetch<HomePayload>('/home', { hotspots: [], documents: [], subjects: [], stats: undefined })

const { data: leaderboardData } = useApiFetch<{ period: string; items: LeaderboardEntry[] }>('/stats/leaderboard?period=week&limit=10', { period: 'week', items: [] }, { lazy: true, server: false })
const leaderboard = computed(() => leaderboardData.value?.items ?? [])

function formatDuration(seconds: number) {
  if (seconds < 60) return `${seconds} 秒`
  if (seconds < 3600) return `${Math.round(seconds / 60)} 分钟`
  const h = Math.floor(seconds / 3600)
  const m = Math.round((seconds % 3600) / 60)
  return m ? `${h} 小时 ${m} 分` : `${h} 小时`
}

const router = useRouter()
const query = ref('')

const sortedHotspots = computed(() => sortHotspots(home.value?.hotspots ?? []))
const filteredHotspots = computed(() => {
  const keyword = query.value.trim().toLowerCase()
  const list = keyword
    ? sortedHotspots.value.filter((item) => `${item.title}${item.summary}${item.type}`.toLowerCase().includes(keyword))
    : sortedHotspots.value
  return list.slice(0, 4)
})
const documents = computed(() => home.value?.documents ?? [])
const subjectSummaries = computed(() => (home.value?.subjects ?? []).slice(0, 6))
const stats = computed(() => home.value?.stats)

const sections = computed(() => [
  { to: '/papers', label: '真题回顾', copy: `${stats.value?.papers ?? 0} 份试卷 · ${stats.value?.questions ?? 0} 道题`, icon: FileText, tone: 'jade' },
  { to: '/hotspots', label: '时政热点', copy: `${stats.value?.hotspots ?? 0} 期逐月整理`, icon: Newspaper, tone: 'red' },
  { to: '/predictions', label: '时政预测', copy: `${stats.value?.predictions ?? 0} 篇真题反推`, icon: TrendingUp, tone: 'gold' },
  { to: '/analysis', label: '真题分析', copy: `${stats.value?.analysis ?? 0} 篇规律统计`, icon: Sparkles, tone: 'blue' },
  { to: '/mistakes', label: '错题分析', copy: `${stats.value?.mistakes ?? 0} 道个人错题`, icon: ClipboardList, tone: 'violet' },
  { to: '/mocks', label: '模拟押题', copy: '按真题规律命制', icon: ClipboardList, tone: 'orange' },
])

function submitSearch() {
  const keyword = query.value.trim()
  if (!keyword) return
  router.push({ path: '/search', query: { q: keyword } })
}
</script>

<template>
  <div class="site-shell">
    <SiteHeader />
    <main id="top" class="page-wrap">
      <section class="hero-block">
        <div class="eyebrow"><Sparkles :size="14" />2027 考研政治</div>
        <h1>从可靠资料中，<br class="mobile-only" />快速找到答案</h1>
        <p class="hero-copy">
          真题 {{ stats?.questions ?? 0 }} 道 · 试卷 {{ stats?.papers ?? 0 }} 份 · 时政 {{ stats?.hotspots ?? 0 }} 期 · 错题 {{ stats?.mistakes ?? 0 }} 道
        </p>
        <form class="search-box" @submit.prevent="submitSearch">
          <Search :size="20" aria-hidden="true" />
          <input v-model="query" aria-label="搜索知识点、会议、年份或题目关键词" placeholder="搜索知识点、会议、年份或题目关键词" />
          <button type="submit">搜索资料 <ArrowRight :size="16" /></button>
        </form>
        <div class="search-hints">
          <span>试试</span>
          <button v-for="hint in ['遵义会议', '实践与认识', '十五五']" :key="hint" @click="router.push({ path: '/search', query: { q: hint } })">{{ hint }}</button>
        </div>
      </section>

      <section class="subject-section">
        <div class="section-heading"><div><span class="section-kicker">六大板块</span><h2>复习从这里开始</h2></div></div>
        <div class="section-grid">
          <NuxtLink v-for="section in sections" :key="section.to" :to="section.to" class="section-card" :class="section.tone">
            <span class="subject-icon"><component :is="section.icon" :size="17" /></span>
            <span><strong>{{ section.label }}</strong><small>{{ section.copy }}</small></span>
            <ChevronRight :size="16" class="section-arrow" />
          </NuxtLink>
        </div>
      </section>

      <section class="subject-section">
        <div class="section-heading"><div><span class="section-kicker">学习宇宙</span><h2>交互式探索</h2></div></div>
        <div class="section-grid">
          <a v-for="section in universeSections" :key="section.href" :href="section.href" class="section-card" :class="section.tone">
            <span class="subject-icon"><component :is="section.icon" :size="17" /></span>
            <span><strong>{{ section.label }}</strong><small>{{ section.copy }}</small></span>
            <ChevronRight :size="16" class="section-arrow" />
          </a>
        </div>
      </section>

      <section class="content-grid">
        <div class="primary-column">
          <div class="section-heading"><div><span class="section-kicker">命题窗口</span><h2>最新时政热点</h2></div><NuxtLink to="/hotspots">查看全部 <ChevronRight :size="15" /></NuxtLink></div>
          <div id="hotspots" class="hotspot-list">
            <NuxtLink v-for="item in filteredHotspots" :key="item.slug || item.title" class="hotspot-row" :to="item.url || `/subjects/${item.subjectSlug}`">
              <span class="level-mark" :class="(item.priority || item.level).toLowerCase()">{{ item.priority || item.level }}</span>
              <div class="hotspot-body">
                <div class="hotspot-title">{{ item.title }} <span>{{ item.tag }}</span></div>
                <p>{{ item.summary }}</p>
                <small>{{ item.type }}{{ item.period ? ` · ${item.period}` : '' }}</small>
              </div>
              <time>{{ (item.updatedAt || '').slice(5).replace('-', '月') }}日</time>
            </NuxtLink>
            <div v-if="!filteredHotspots.length" class="empty-state"><FileText :size="18" />没有找到匹配的热点，换一个关键词试试。</div>
          </div>
        </div>

        <aside class="side-column">
          <div class="section-heading compact"><div><span class="section-kicker">数据规模</span><h2>资料库</h2></div></div>
          <div id="reading" class="reading-list">
            <NuxtLink v-for="document in documents" :key="document.title" to="/subjects" class="reading-item">
              <span class="document-cover" :class="document.tone">{{ document.cover }}</span>
              <span class="reading-copy"><strong>{{ document.title }}</strong><small>{{ document.meta }}</small></span>
            </NuxtLink>
            <div v-if="!documents.length" class="empty-state"><FileText :size="18" />暂无可继续阅读的资料。</div>
          </div>
          <div class="saved-block">
            <div class="section-heading compact"><div><span class="section-kicker">本周学习时长</span><h2>学习排行</h2></div></div>
            <ol v-if="leaderboard.length" class="leaderboard-list">
              <li v-for="(item, i) in leaderboard" :key="item.userId">
                <b class="leaderboard-rank" :class="`rank-${i + 1}`">{{ i + 1 }}</b>
                <span class="leaderboard-name">{{ item.displayName || '同学' }}</span>
                <UserGroupBadge :group="item.userGroup" :role="item.role" />
                <small>{{ formatDuration(item.seconds) }}</small>
              </li>
            </ol>
            <p v-else class="empty-state"><ClipboardList :size="18" />还没有学习时长记录，登录后开始学习即可上榜。</p>
          </div>
          <div class="saved-block">
            <div class="section-heading compact"><div><span class="section-kicker">快捷入口</span><h2>我的学习</h2></div></div>
            <ul>
              <li><NuxtLink to="/me/favorites">我的收藏</NuxtLink></li>
              <li><NuxtLink to="/me/notes">我的笔记</NuxtLink></li>
              <li><NuxtLink to="/me/progress">学习进度</NuxtLink></li>
            </ul>
          </div>
        </aside>
      </section>

      <section class="subject-section">
        <div class="section-heading"><div><span class="section-kicker">资料导航</span><h2>按学科浏览</h2></div><NuxtLink to="/subjects">进入资料库 <ChevronRight :size="15" /></NuxtLink></div>
        <div id="subjects" class="subject-grid">
          <NuxtLink v-for="subject in subjectSummaries" :key="subject.slug" :to="`/subjects/${subject.slug}`" class="subject-item" :class="subject.tone">
            <span class="subject-icon"><BookOpen :size="17" /></span>
            <span><strong>{{ subject.short }}</strong><small>{{ subject.detail }}</small></span>
            <b>{{ subject.count }}<small>章</small></b>
          </NuxtLink>
          <div v-if="!subjectSummaries.length" class="empty-state"><BookOpen :size="18" />学科数据暂不可用，请稍后重试。</div>
        </div>
      </section>

      <section class="signal-strip">
        <div><CalendarDays :size="18" /><span><strong>资料更新</strong> 每周整理新政策与命题窗口</span></div>
        <div><FileText :size="18" /><span><strong>可追溯</strong> 搜索结果返回原始资料和页码</span></div>
        <div><BookOpen :size="18" /><span><strong>{{ stats?.questions ?? 0 }} 道真题</strong> 覆盖 1994—2026</span></div>
      </section>
    </main>
    <SiteFooter />
  </div>
</template>