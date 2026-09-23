<script setup lang="ts">
import { Newspaper } from 'lucide-vue-next'
import type { ArticleSummary } from '~/types/api'

useHead({ title: '时政热点｜观澜考研政治知识库', meta: [{ name: 'description', content: '2026 年 1—9 月考研政治时政考点，按月份与优先级整理。' }] })

const { data } = await useApiFetch<ArticleSummary[]>('/hotspots', [])
const items = computed(() => data.value ?? [])
</script>

<template>
  <div class="site-shell">
    <SiteHeader />
    <main class="page-wrap inner-page">
      <Breadcrumbs />
      <section class="page-hero">
        <div class="eyebrow"><Newspaper :size="14" />时政热点</div>
        <h1>时政不靠猜，<br class="mobile-only" />靠每月固定整理</h1>
        <p>按人民网实测抓取整理，标注优先级与教材接口，共 {{ items.length }} 期。</p>
      </section>
      <ArticleGrid :items="items" base-path="/hotspots" group-key="period" />
      <NuxtLink class="back-link" to="/">返回首页</NuxtLink>
    </main>
  </div>
</template>