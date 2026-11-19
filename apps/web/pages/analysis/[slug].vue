<script setup lang="ts">
import { computed } from 'vue'
import type { ArticleDetail, ArchivedAnalysisResponse } from '~/types/api'

const route = useRoute()
const slug = String(route.params.slug)

const { data, error } = await useApiFetch<ArticleDetail | null>(`/analysis/${slug}`, null)
const article = computed(() => data.value)
const archived = computed(() => {
  const failure = error.value as { statusCode?: number; data?: ArchivedAnalysisResponse } | null
  if (failure?.statusCode !== 410 || failure.data?.code !== 'ANALYSIS_VERSION_ARCHIVED') return null
  const replacementSlug = failure.data.replacementSlug
  return {
    replacementUrl: replacementSlug && /^[a-z0-9-]+$/.test(replacementSlug)
      ? `/analysis/${replacementSlug}`
      : null,
  }
})

useHead(() => ({ title: article.value ? `${article.value.title}｜观澜分析` : '内容｜观澜' }))

// 游客第 4 篇起会收到 403：先回列表页并带上 login=1，由列表页弹出登录引导。
if ((error.value as { statusCode?: number } | null)?.statusCode === 403) {
  await navigateTo({ path: '/analysis', query: { login: 1 } })
}
</script>

<template>
  <div class="site-shell">
    <SiteHeader />
    <main class="page-wrap inner-page">
      <Breadcrumbs />
      <ArticleReader v-if="article" :article="article" back-to="/analysis" back-label="返回分析列表" />
      <section v-else-if="archived" class="not-found-card">
        <h1>此版本已下架</h1>
        <p>你打开的是旧版分析文章。原收藏、笔记和阅读进度仍保留在旧稿下，不会合并到发行版。</p>
        <p>历史评论保留在旧稿关联下，当前发行版不展示这些评论。</p>
        <NuxtLink v-if="archived.replacementUrl" class="primary-link" :to="archived.replacementUrl">查看当前发行版</NuxtLink>
        <p v-else>当前发行版暂不可用，请稍后从分析列表查看。</p>
        <NuxtLink class="primary-link" to="/analysis">返回分析列表</NuxtLink>
      </section>
      <section v-else class="not-found-card">
        <h1>没有找到这篇内容</h1>
        <p>可能链接已失效，或该篇需要登录后查看。</p>
        <NuxtLink class="primary-link" to="/analysis">前往列表</NuxtLink>
      </section>
      <CommentSection v-if="article" article-type="analysis" :slug="slug" />
    </main>
    <SiteFooter />
  </div>
</template>
