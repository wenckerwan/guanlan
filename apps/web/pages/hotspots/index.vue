<script setup lang="ts">
import { Newspaper } from 'lucide-vue-next'
import type { ArticleSummary } from '~/types/api'

useHead({ title: '时政热点｜观澜考研政治知识库', meta: [{ name: 'description', content: '2026 年 1—9 月考研政治时政考点，按月份与优先级整理。' }] })

const route = useRoute()
const router = useRouter()
const { data, pending, error, refresh } = await useApiFetch<ArticleSummary[]>('/hotspots', [])
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
    <main class="page-wrap inner-page library-page">
      <Breadcrumbs />
      <section class="library-heading">
        <h1>时政热点</h1>
        <p>按月份整理，标注优先级与对应教材知识点。游客每个栏目可免费阅读 3 篇。</p>
      </section>
      <NuxtLink class="library-row current-affairs-entry" to="/practice/current-affairs">
        <span class="library-icon"><Newspaper :size="18" aria-hidden="true" /></span>
        <span class="library-copy"><strong>进入时政练习题库</strong><small>按模块、题型与作答状态筛选；登录后记录每次作答，游客可试玩 6 题。</small></span>
        <span aria-hidden="true">→</span>
      </NuxtLink>
      <ArticleGrid :items="items" :pending="pending" :failed="!!error" base-path="/hotspots" group-key="period" @retry="refresh()" />
      <NuxtLink class="back-link" to="/">返回首页</NuxtLink>
      <LoginGateModal :open="gateOpen" @close="gateOpen = false" />
    </main>
    <SiteFooter />
  </div>
</template>

<style scoped>
.current-affairs-entry { margin-bottom: 28px; border: 1px solid var(--line); border-radius: var(--gl-radius-control); }
</style>
