<script setup lang="ts">
import { computed, ref } from 'vue'
import { ArrowRight, BookOpen, CalendarDays, ChevronRight, FileText, Search, Sparkles } from 'lucide-vue-next'
import { hotspots, recentDocuments } from '../data/home'
import { subjects as subjectCatalog } from '../data/subjects'

useHead({ title: '观澜｜考研政治知识库', meta: [{ name: 'description', content: '观澜考研政治知识库：从教材、真题与时政资料中快速找到答案。' }] })
const query = ref('')
const filteredHotspots = computed(() => {
  const keyword = query.value.trim().toLowerCase()
  return (keyword ? hotspots.filter((item) => `${item.title}${item.summary}${item.type}`.toLowerCase().includes(keyword)) : hotspots).slice(0, 4)
})
</script>

<template>
  <div class="site-shell">
    <SiteHeader />
    <main id="top" class="page-wrap">
      <section class="hero-block"><div class="eyebrow"><Sparkles :size="14" />2027 考研政治</div><h1>从可靠资料中，<br class="mobile-only" />快速找到答案</h1><p class="hero-copy">已整理 177 个学习资产 · 真题覆盖 1994—2026 · 热点更新至 2026-09-06</p><form class="search-box" @submit.prevent><Search :size="20" aria-hidden="true" /><input v-model="query" aria-label="搜索知识点、会议、年份或题目关键词" placeholder="搜索知识点、会议、年份或题目关键词" /><button type="submit">搜索资料 <ArrowRight :size="16" /></button></form><div class="search-hints"><span>试试</span><button v-for="hint in ['遵义会议', '实践与认识', '十五五']" :key="hint" @click="query = hint">{{ hint }}</button></div></section>
      <section class="content-grid"><div class="primary-column"><div class="section-heading"><div><span class="section-kicker">命题窗口</span><h2>最新热点推荐</h2></div><a href="#hotspots">查看全部热点 <ChevronRight :size="15" /></a></div><div id="hotspots" class="hotspot-list"><NuxtLink v-for="item in filteredHotspots" :key="item.title" class="hotspot-row" :to="{ path: `/subjects/${item.subjectSlug}`, query: { chapter: item.chapterId } }"><span class="level-mark" :class="item.level.toLowerCase()">{{ item.level }}</span><div class="hotspot-body"><div class="hotspot-title">{{ item.title }} <span>{{ item.tag }}</span></div><p>{{ item.summary }}</p><small>{{ item.type }}</small></div><time>{{ item.updatedAt.slice(5).replace('-', '月') }}日</time></NuxtLink><div v-if="!filteredHotspots.length" class="empty-state"><FileText :size="18" />没有找到匹配的热点，换一个关键词试试。</div></div></div><aside class="side-column"><div class="section-heading compact"><div><span class="section-kicker">学习轨迹</span><h2>继续阅读</h2></div><a href="#reading">全部 <ChevronRight :size="15" /></a></div><div id="reading" class="reading-list"><a v-for="document in recentDocuments" :key="document.title" href="#" class="reading-item"><span class="document-cover" :class="document.tone">{{ document.cover }}</span><span class="reading-copy"><strong>{{ document.title }}</strong><small>{{ document.meta }}</small><span class="progress-line"><i :style="{ width: `${document.progress}%` }"></i></span><em>{{ document.progress }}%</em></span></a></div><div class="saved-block"><div class="section-heading compact"><div><span class="section-kicker">稍后回看</span><h2>最近收藏</h2></div><span class="saved-count">12 条</span></div><ul><li>实践与认识的辩证关系</li><li>遵义会议的历史意义</li><li>高质量发展的首要任务</li></ul></div></aside></section>
      <section class="subject-section"><div class="section-heading"><div><span class="section-kicker">资料导航</span><h2>按学科浏览</h2></div><NuxtLink to="/subjects">进入资料库 <ChevronRight :size="15" /></NuxtLink></div><div id="subjects" class="subject-grid"><NuxtLink v-for="subject in subjectCatalog" :key="subject.slug" :to="`/subjects/${subject.slug}`" class="subject-item" :class="subject.tone"><span class="subject-icon"><BookOpen :size="17" /></span><span><strong>{{ subject.short }}</strong><small>{{ subject.detail }}</small></span><b>{{ subject.chapters.length }}<small>章</small></b></NuxtLink></div></section>
      <section class="signal-strip"><div><CalendarDays :size="18" /><span><strong>资料更新</strong> 每周整理新政策与命题窗口</span></div><div><FileText :size="18" /><span><strong>可追溯</strong> 搜索结果返回原始资料和页码</span></div><div><BookOpen :size="18" /><span><strong>177 个资产</strong> 教材、真题、模拟卷与专题</span></div></section>
    </main>
  </div>
</template>
