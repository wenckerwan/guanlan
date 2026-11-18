<script setup lang="ts">
import type { ArticleSummary } from '~/types/api'

useHead({ title: '真题分析｜观澜考研政治知识库', meta: [{ name: 'description', content: '2012—2026 年考研政治真题规律分析：选择题、分析题、会议与周年切合度。' }] })

const route = useRoute()
const router = useRouter()
const { data, pending, error, refresh } = await useApiFetch<ArticleSummary[]>('/analysis', [])
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
    <main class="page-wrap inner-page library-page">
      <Breadcrumbs />
      <section class="library-heading">
        <h1>真题分析</h1>
        <p>基于 2012—2026 共 15 套真题的统计与人工核对。游客每个栏目可免费阅读 3 篇。</p>
      </section>
      <ArticleGrid :items="items" :pending="pending" :failed="!!error" base-path="/analysis" group-key="category" :show-priority="false" @retry="refresh()" />
      <NuxtLink class="back-link" to="/">返回首页</NuxtLink>
      <LoginGateModal :open="gateOpen" @close="gateOpen = false" />
    </main>
    <SiteFooter />
  </div>
</template>
