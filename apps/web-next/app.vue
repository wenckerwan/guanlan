<script setup lang="ts">
import { ArrowDown, ArrowLeft, ArrowRight, BookOpen, Check, ChevronRight, Compass, FileText, GraduationCap, Layers3, Library, Menu, Newspaper, RefreshCw, Search, X } from 'lucide-vue-next'

type Chapter = { id: string; title: string; summary: string; points: { title: string; summary: string }[] }
type Subject = { slug: string; name: string; short: string; detail: string; intro: string; chapters: Chapter[] }
type Hotspot = { title: string; summary: string; period: string; priority: string }
const { data, status, error, refresh } = await useFetch<{ subjects: Subject[]; home: { hotspots: Hotspot[]; stats?: { questions: number; papers: number } } }>('/api/catalog', { lazy: true })
const view = ref('workspace')
const query = ref('')
const drawer = ref(false)
const selected = ref<Subject | null>(null)
const chapter = ref<Chapter | null>(null)
const subjects = computed(() => data.value?.subjects ?? [])
const filtered = computed(() => subjects.value.filter(s => `${s.name} ${s.detail} ${s.chapters.map(c => c.title).join(' ')}`.toLowerCase().includes(query.value.trim().toLowerCase())))
const hotspots = computed(() => data.value?.home.hotspots ?? [])
const pointCount = computed(() => subjects.value.reduce((n, s) => n + s.chapters.reduce((m, c) => m + c.points.length, 0), 0))
const nav = [
  { id: 'workspace', label: '学习工作台', icon: Compass },
  { id: 'library', label: '知识资料库', icon: Library },
  { id: 'news', label: '时政观察', icon: Newspaper },
]
function navigate(id: string) { view.value = id; selected.value = null; chapter.value = null; query.value = ''; drawer.value = false }
function openSubject(s: Subject) { selected.value = s; chapter.value = null; view.value = 'library' }
function search() { view.value = 'library'; selected.value = null; chapter.value = null }
watch(() => query.value, () => { if (query.value.trim()) search() })
const title = computed(() => chapter.value?.title || selected.value?.name || ({ workspace: '学习工作台', library: '知识资料库', news: '时政观察' }[view.value] ?? '观澜'))
</script>

<template>
  <div class="workspace">
    <button v-if="drawer" class="scrim" aria-label="关闭导航" @click="drawer = false" />
    <aside class="rail glass" :class="{ open: drawer }">
      <a class="identity" href="/" @click.prevent="navigate('workspace')"><span class="brand-mark"><img src="/guanlan.png" width="32" height="32" alt="" /></span><span><b>观澜</b><small>GUANLAN / 2027</small></span></a>
      <button class="mobile-close icon-button" title="关闭导航" @click="drawer = false"><X :size="20" /></button>
      <span class="nav-label">学习空间</span>
      <nav aria-label="主导航"><button v-for="item in nav" :key="item.id" :class="{ active: view === item.id }" @click="navigate(item.id)"><component :is="item.icon" :size="19" /><span>{{ item.label }}</span><span v-if="item.id === 'library'" class="nav-count">{{ subjects.length }}</span></button></nav>
      <span class="nav-label secondary-label">知识体系</span>
      <nav class="subject-nav" aria-label="学科导航"><button v-for="(s, i) in subjects" :key="s.slug" :class="{ selected: selected?.slug === s.slug }" @click="openSubject(s)"><i :class="`dot-${i % 4}`" /><span>{{ s.short || s.name }}</span></button></nav>
      <div class="rail-bottom"><GraduationCap :size="22" /><div><strong>2027 考研政治</strong><small>知识 · 真题 · 时政</small></div></div>
    </aside>

    <div class="main-shell">
      <header class="toolbar glass">
        <button class="menu-toggle icon-button" title="打开导航" @click="drawer = true"><Menu :size="20" /></button>
        <div class="breadcrumb"><span>我的学习空间</span><ChevronRight :size="14" /><strong>{{ title }}</strong></div>
        <form class="global-search" role="search" @submit.prevent="search"><Search :size="17" /><input v-model="query" aria-label="搜索资料和章节" placeholder="搜索资料、章节…" /><button v-if="query" type="button" class="icon-button" title="清空搜索" @click="query = ''"><X :size="15" /></button></form>
        <span class="edition">POLITICS · 2027</span>
      </header>

      <main>
        <div v-if="error" class="notice" role="alert"><span>资料暂时无法加载</span><button @click="refresh()"><RefreshCw :size="16" />重试</button></div>
        <div v-if="status === 'pending'" class="loading" role="status"><RefreshCw :size="18" />正在加载资料…</div>

        <template v-if="view === 'workspace'">
          <section class="page-intro"><div><p class="overline">你的知识，从这里连接</p><h1>观澜学习工作台<span class="accent">.</span></h1><p>梳理概念，回到真题，形成自己的理解。</p></div><button class="primary-button" @click="navigate('library')">进入资料库<ArrowRight :size="17" /></button></section>
          <section class="metrics" aria-label="资料规模"><div><span>知识学科</span><strong>{{ subjects.length }}<small>门</small></strong></div><div><span>知识节点</span><strong>{{ pointCount }}<small>个</small></strong></div><div><span>历年真题</span><strong>{{ data?.home.stats?.questions ?? '—' }}<small>道</small></strong></div><div><span>真题试卷</span><strong>{{ data?.home.stats?.papers ?? '—' }}<small>份</small></strong></div></section>
          <div class="dashboard-columns">
            <section class="knowledge-section"><div class="section-title"><div><span class="overline">KNOWLEDGE INDEX</span><h2>进入知识体系</h2></div><button class="text-button" @click="navigate('library')">全部学科<ArrowRight :size="16" /></button></div>
              <div class="subject-tiles"><button v-for="(s, i) in subjects" :key="s.slug" class="subject-tile" @click="openSubject(s)"><span class="tile-top"><span class="subject-number">0{{ i + 1 }}</span><BookOpen :size="21" /></span><h3>{{ s.short || s.name }}</h3><p>{{ s.detail }}</p><span class="tile-bottom">{{ s.chapters.length }} 个章节<ArrowRight :size="17" /></span></button></div>
              <div v-if="status !== 'pending' && !subjects.length" class="empty">暂无可用学科</div>
              <section class="explore-band"><span class="explore-symbol"><Compass :size="36" /></span><div><span class="overline">EXPLORE & CONNECT</span><h2>在时间与概念之间建立联系</h2><p>史纲、马原与会议专题</p></div><button class="icon-button" title="查看专题入口" @click="navigate('library')"><ArrowRight :size="22" /></button></section>
            </section>
            <aside class="news-section"><div class="section-title"><div><span class="overline">CURRENT AFFAIRS</span><h2>时政观察</h2></div><span class="live-dot" /></div><button v-for="(item, i) in hotspots.slice(0, 4)" :key="item.title" class="news-row" @click="navigate('news')"><span class="news-meta">{{ item.period || '最新资料' }}<span>{{ item.priority || 'A' }} 级</span></span><h3>{{ item.title }}</h3><p>{{ item.summary }}</p><span class="news-index">0{{ i + 1 }}<ArrowRight :size="15" /></span></button><p v-if="!hotspots.length" class="empty">暂无时政资料</p></aside>
          </div>
        </template>

        <template v-else-if="view === 'library' && !selected">
          <section class="page-intro"><div><p class="overline">KNOWLEDGE LIBRARY</p><h1>知识资料库<span class="accent">.</span></h1><p>{{ query ? `搜索结果：${query}` : '从学科到章节，从概念到理解。' }}</p></div><span class="result-count">{{ filtered.length }} 个学科</span></section>
          <div class="library-list"><button v-for="(s, i) in filtered" :key="s.slug" class="library-row" @click="openSubject(s)"><span class="subject-number">0{{ i + 1 }}</span><div><h2>{{ s.name }}</h2><p>{{ s.detail }}</p></div><span class="chapter-count">{{ s.chapters.length }} 章节</span><ArrowRight :size="22" /></button></div>
          <div v-if="status !== 'pending' && !filtered.length" class="empty">{{ query ? '没有匹配的资料，请更换关键词。' : '暂无资料' }}</div>
          <section class="destinations"><h2>交互专题</h2><a href="https://guanlan.wencker.top/history/" target="_blank" rel="noopener noreferrer"><Compass :size="19" />近现代史时间实验室<ArrowRight :size="17" /></a><a href="https://guanlan.wencker.top/mayuan/" target="_blank" rel="noopener noreferrer"><Layers3 :size="19" />马原知识宇宙<ArrowRight :size="17" /></a><a href="https://guanlan.wencker.top/meetings/index.html" target="_blank" rel="noopener noreferrer"><FileText :size="19" />党史会议专题<ArrowRight :size="17" /></a></section>
        </template>

        <template v-else-if="selected">
          <button class="text-button back" @click="chapter ? chapter = null : selected = null"><ArrowLeft :size="17" />{{ chapter ? selected.short || selected.name : '全部学科' }}</button>
          <section class="page-intro"><div><p class="overline">{{ chapter ? 'READ & UNDERSTAND' : 'SUBJECT OVERVIEW' }}</p><h1>{{ title }}</h1><p>{{ chapter?.summary || selected.intro || selected.detail }}</p></div></section>
          <div class="reader-layout"><nav class="chapter-nav glass" aria-label="章节目录"><h2>章节目录</h2><button v-for="(c, i) in selected.chapters" :key="c.id" :class="{ active: chapter?.id === c.id }" @click="chapter = c"><span>{{ String(i + 1).padStart(2, '0') }}</span>{{ c.title }}</button></nav>
            <article v-if="chapter" class="reading-content"><div class="reading-label"><BookOpen :size="17" />{{ chapter.points.length }} 个知识点</div><section v-for="(point, i) in chapter.points" :key="`${point.title}-${i}`" class="knowledge-point"><span class="overline">{{ String(i + 1).padStart(2, '0') }}</span><h2>{{ point.title }}</h2><p>{{ point.summary }}</p></section><p v-if="!chapter.points.length" class="empty">本章暂无知识点</p></article>
            <div v-else class="chapter-list"><button v-for="(c, i) in selected.chapters" :key="c.id" @click="chapter = c"><span class="subject-number">{{ String(i + 1).padStart(2, '0') }}</span><div><h2>{{ c.title }}</h2><p>{{ c.summary }}</p><small>{{ c.points.length }} 个知识点</small></div><ChevronRight :size="20" /></button><p v-if="!selected.chapters.length" class="empty">暂无章节</p></div>
          </div>
        </template>

        <template v-else-if="view === 'news'"><section class="page-intro"><div><p class="overline">CURRENT AFFAIRS</p><h1>时政观察<span class="accent">.</span></h1><p>连接当下与考点。</p></div><span class="result-count">{{ hotspots.length }} 条资料</span></section><div class="news-full"><article v-for="item in hotspots" :key="item.title"><span class="news-meta">{{ item.period }} · {{ item.priority || 'A' }} 级</span><h2>{{ item.title }}</h2><p>{{ item.summary }}</p></article><p v-if="!hotspots.length" class="empty">暂无时政资料</p></div></template>
        <footer><span>观澜</span><span>2027 · 考研政治知识库</span><span>READ. THINK. CONNECT.</span></footer>
      </main>
    </div>
  </div>
</template>
