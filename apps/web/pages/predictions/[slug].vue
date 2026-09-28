<script setup lang="ts">
import { computed } from 'vue'
import type { ArticleDetail } from '~/types/api'

const route = useRoute()
const slug = String(route.params.slug)

const { data, error } = await useApiFetch<ArticleDetail | null>(`/predictions/${slug}`, null)
const article = computed(() => data.value)

useHead(() => ({ title: article.value ? `${article.value.title}｜观澜预测` : '内容｜观澜' }))

// 游客第 4 篇起会收到 403：先回列表页并带上 login=1，由列表页弹出登录引导。
if ((error.value as { statusCode?: number } | null)?.statusCode === 403) {
  await navigateTo({ path: '/predictions', query: { login: 1 } })
}
</script>

<template>
  <div class="site-shell">
    <SiteHeader />
    <main class="page-wrap inner-page">
      <Breadcrumbs />
      <ArticleReader v-if="article" :article="article" back-to="/predictions" back-label="返回预测列表" />
      <section v-else class="not-found-card">
        <h1>没有找到这篇内容</h1>
        <p>可能链接已失效，或该篇需要登录后查看。</p>
        <NuxtLink class="primary-link" to="/predictions">前往列表</NuxtLink>
      </section>
    </main>
  </div>
</template>
