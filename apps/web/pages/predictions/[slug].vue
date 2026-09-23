<script setup lang="ts">
import { computed } from 'vue'
import type { ArticleDetail } from '~/types/api'

const route = useRoute()
const slug = String(route.params.slug)

const { data } = await useApiFetch<ArticleDetail | null>(`/predictions/${slug}`, null)
const article = computed(() => data.value)

useHead(() => ({ title: article.value ? `${article.value.title}｜观澜预测` : '时政预测｜观澜' }))
</script>

<template>
  <div class="site-shell">
    <SiteHeader />
    <main class="page-wrap inner-page">
      <Breadcrumbs />
      <ArticleReader v-if="article" :article="article" back-to="/predictions" back-label="返回预测列表" />
      <section v-else class="not-found-card">
        <h1>没有找到这篇预测</h1>
        <NuxtLink class="primary-link" to="/predictions">前往预测列表</NuxtLink>
      </section>
    </main>
  </div>
</template>