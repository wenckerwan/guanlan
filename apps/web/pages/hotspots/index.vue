<script setup lang="ts">
import { Newspaper } from 'lucide-vue-next'
import type { ArticleSummary } from '~/types/api'

useHead({ title: '时政热点｜观澜考研政治知识库', meta: [{ name: 'description', content: '2026 年 1—9 月考研政治时政考点，按月份与优先级整理。' }] })

const route = useRoute()
const router = useRouter()
const { data } = await useApiFetch<ArticleSummary[]>('/hotspots', [])
const items = computed(() => data.value ?? [])
const gateOpen = ref(false)

onMounted(() => {
  if (String(route.query.login ?? '') === '1') {
    gateOpen.value = true
    const query = { ...route.query }
    delete query.login
    router.replace({ query })
  }
})
</script>

<template>
  <div class="site-shell">
    <SiteHeader />
    <main class="page-wrap inner-page">
      <Breadcrumbs />
      <section class="page-hero">
        <div class="eyebrow"><Newspaper :size="14" />时政热点</div>
        <h1>时政不靠猜，<br class="mobile-only" />靠每月固定整理</h1>
        <p>按人民网实测抓取整理，标注优先级与教材接口，共 {{ items.length }} 期；游客每个栏目可免费阅读 3 篇。</p>
      </section>
      <NuxtLink class="current-affairs-entry" to="/practice/current-affairs">
        <span class="entry-kicker">原创专题练习 · 2026 时政</span>
        <strong>进入时政练习题库 <span aria-hidden="true">↗</span></strong>
        <span>按模块、题型与作答状态筛选；登录后记录每次作答，游客可试玩 6 题。</span>
      </NuxtLink>
      <ArticleGrid :items="items" base-path="/hotspots" group-key="period" />
      <NuxtLink class="back-link" to="/">返回首页</NuxtLink>
      <LoginGateModal :open="gateOpen" @close="gateOpen = false" />
    </main>
    <SiteFooter />
  </div>
</template>

<style scoped>
.current-affairs-entry{display:grid;gap:6px;margin:0 0 28px;padding:20px 24px;border:1px solid #9fbde0;border-radius:14px;background:linear-gradient(115deg,#f6faff,#eaf3fc);color:#294b74;text-decoration:none}.current-affairs-entry:hover{border-color:#4d82b8;box-shadow:0 8px 26px #325c8c18}.current-affairs-entry strong{font-size:18px}.current-affairs-entry>span:last-child{font-size:13px;color:#5b6e84}.entry-kicker{font-size:11px;font-weight:700;letter-spacing:.1em}
</style>
