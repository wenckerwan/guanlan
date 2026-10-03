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
      <ArticleGrid :items="items" base-path="/hotspots" group-key="period" />
      <NuxtLink class="back-link" to="/">返回首页</NuxtLink>
      <LoginGateModal :open="gateOpen" @close="gateOpen = false" />
    </main>
    <SiteFooter />
  </div>
</template>
