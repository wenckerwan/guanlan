<script setup lang="ts">
import { computed } from 'vue'
import { ArrowLeft } from 'lucide-vue-next'
import type { ArticleDetail } from '~/types/api'

const route = useRoute()
const slug = String(route.params.slug)

const { data } = await useApiFetch<ArticleDetail | null>(`/hotspots/${slug}`, null)
const article = computed(() => data.value)

useHead(() => ({ title: article.value ? `${article.value.title}｜观澜时政` : '时政内容｜观澜' }))
</script>

<template>
  <div class="site-shell">
    <SiteHeader />
    <main class="page-wrap inner-page">
      <Breadcrumbs />
      <ArticleReader v-if="article" :article="article" back-to="/hotspots" back-label="返回时政列表" />
      <section v-else class="not-found-card">
        <h1>没有找到这篇时政</h1>
        <p>可能链接已失效，请从时政列表进入。</p>
        <NuxtLink class="primary-link" to="/hotspots">前往时政列表</NuxtLink>
      </section>
    </main>
  </div>
</template>