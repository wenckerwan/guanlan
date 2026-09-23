<script setup lang="ts">
import { TrendingUp } from 'lucide-vue-next'
import type { ArticleSummary } from '~/types/api'

useHead({ title: '时政预测｜观澜考研政治知识库', meta: [{ name: 'description', content: '2027 考研政治时政考点预测：真題反推、热点预测与专题精选。' }] })

const { data } = await useApiFetch<ArticleSummary[]>('/predictions', [])
const items = computed(() => data.value ?? [])
</script>

<template>
  <div class="site-shell">
    <SiteHeader />
    <main class="page-wrap inner-page">
      <Breadcrumbs />
      <section class="page-hero">
        <div class="eyebrow"><TrendingUp :size="14" />时政预测</div>
        <h1>不猜新闻，<br class="mobile-only" />只推可能考的事件类型</h1>
        <p>共 {{ items.length }} 篇：纯真题反推、热点预测与专题精选。</p>
      </section>
      <ArticleGrid :items="items" base-path="/predictions" group-key="layer" />
      <NuxtLink class="back-link" to="/">返回首页</NuxtLink>
    </main>
  </div>
</template>