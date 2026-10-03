<script setup lang="ts">
import { Sparkles } from 'lucide-vue-next'
import type { ArticleSummary } from '~/types/api'

useHead({ title: '真题分析｜观澜考研政治知识库', meta: [{ name: 'description', content: '2012—2026 年考研政治真题规律分析：选择题、分析题、会议与周年切合度。' }] })

const route = useRoute()
const router = useRouter()
const { data } = await useApiFetch<ArticleSummary[]>('/analysis', [])
const items = computed(() => data.value ?? [])
const gateOpen = ref(false)

// 详情页被 403 拦下后会带着 login=1 回到列表，这里自动打开登录引导。
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
        <div class="eyebrow"><Sparkles :size="14" />真题分析</div>
        <h1>先看清命题规律，<br class="mobile-only" />再决定背什么</h1>
        <p>基于 2012—2026 共 15 套真题的统计与人工核对，共 {{ items.length }} 篇；游客每个栏目可免费阅读 3 篇。</p>
      </section>
      <ArticleGrid :items="items" base-path="/analysis" group-key="category" :show-priority="false" />
      <NuxtLink class="back-link" to="/">返回首页</NuxtLink>
      <LoginGateModal :open="gateOpen" @close="gateOpen = false" />
    </main>
    <SiteFooter />
  </div>
</template>
