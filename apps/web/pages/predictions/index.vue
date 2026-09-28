<script setup lang="ts">
import { TrendingUp } from 'lucide-vue-next'
import type { ArticleSummary } from '~/types/api'

useHead({ title: '时政预测｜观澜考研政治知识库', meta: [{ name: 'description', content: '2027 考研政治时政考点预测：真题反推、热点预测与专题精选。' }] })

const route = useRoute()
const router = useRouter()
const { data } = await useApiFetch<ArticleSummary[]>('/predictions', [])
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
        <div class="eyebrow"><TrendingUp :size="14" />时政预测</div>
        <h1>不猜新闻，<br class="mobile-only" />只推可能考的事件类型</h1>
        <p>共 {{ items.length }} 篇：纯真题反推、热点预测与专题精选；游客每个栏目可免费阅读 3 篇。</p>
      </section>
      <ArticleGrid :items="items" base-path="/predictions" group-key="layer" />
      <NuxtLink class="back-link" to="/">返回首页</NuxtLink>
      <LoginGateModal :open="gateOpen" @close="gateOpen = false" />
    </main>
  </div>
</template>
