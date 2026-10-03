<script setup lang="ts">
import { computed } from 'vue'
import { ArrowLeft, BookOpen } from 'lucide-vue-next'
import type { Handbook } from '~/types/api'

const route = useRoute()
const code = String(route.params.code)
const id = String(route.params.id)

const { data, error } = await useApiFetch<Handbook | null>(`/mistakes/handbooks/${id}`, null)
const handbook = computed(() => data.value)

// 非本人 / 未登录访问私有手册：回错题列表并提示登录。
if ((error.value as { statusCode?: number } | null)?.statusCode === 403) {
  await navigateTo({ path: '/mistakes', query: { login: 1 } })
}

useHead(() => ({ title: handbook.value ? `${handbook.value.title}｜观澜提分手册` : '提分手册｜观澜' }))
</script>

<template>
  <div class="site-shell">
    <SiteHeader />
    <main class="page-wrap inner-page">
      <Breadcrumbs />

      <template v-if="handbook">
        <section class="page-hero">
          <div class="eyebrow"><BookOpen :size="14" />考生 {{ handbook.studentCode }} · {{ handbook.module }}</div>
          <h1>{{ handbook.title }}</h1>
          <p>每章四段：考点结论 / 我错在哪 / 真题怎么考 / 下次怎么做。</p>
        </section>

        <nav class="handbook-tabs">
          <a v-for="(section, index) in handbook.sections" :key="index" :href="`#sec-${index}`">{{ section.title }}</a>
        </nav>

        <article v-for="(section, index) in handbook.sections" :id="`sec-${index}`" :key="index" class="handbook-section">
          <h2>{{ section.title }}</h2>
          <div class="markdown-body" v-html="section.html" />
        </article>

        <NuxtLink class="back-link" :to="`/mistakes/${code}`"><ArrowLeft :size="15" />返回错题册</NuxtLink>
      </template>
      <section v-else class="not-found-card">
        <h1>没有找到这份手册</h1>
        <NuxtLink class="primary-link" :to="`/mistakes/${code}`">返回错题册</NuxtLink>
      </section>
    </main>
    <SiteFooter />
  </div>
</template>