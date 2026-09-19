<script setup lang="ts">
import { computed } from 'vue'
import { BookOpen, ChevronRight } from 'lucide-vue-next'
import type { Subject } from '~/types/api'
import { validateSubjects } from '~/utils/subjects.mjs'

useHead({ title: '资料库｜观澜考研政治知识库', meta: [{ name: 'description', content: '观澜考研政治六大学科资料导航。' }] })

const { data } = await useApiFetch<Subject[]>('/subjects', [])

const subjects = computed(() => data.value ?? [])

if (import.meta.server) {
  const result = validateSubjects(subjects.value)
  if (!result.valid) {
    console.error('[subjects] 学科数据校验失败：', result.errors)
  }
}
</script>

<template>
  <div class="site-shell">
    <SiteHeader />
    <main class="page-wrap inner-page">
      <Breadcrumbs />
      <section class="page-hero">
        <div class="eyebrow"><BookOpen :size="14" />资料导航</div>
        <h1>六大学科，<br class="mobile-only" />从一张地图开始</h1>
        <p>按教材结构进入章节，再用知识点和真题线索建立自己的复习路径。</p>
      </section>
      <section class="catalog-section">
        <div class="section-heading"><div><span class="section-kicker">SUBJECTS</span><h2>选择一个学科</h2></div><span class="catalog-count">{{ subjects.length }} 个学科</span></div>
        <div class="catalog-grid">
          <NuxtLink v-for="subject in subjects" :key="subject.slug" :to="`/subjects/${subject.slug}`" class="catalog-card" :class="subject.tone">
            <span class="subject-icon"><BookOpen :size="18" /></span>
            <span class="catalog-card-copy"><strong>{{ subject.name }}</strong><small>{{ subject.detail }}</small><em>{{ subject.chapters.length }} 个章节 · {{ subject.chapters.reduce((sum, chapter) => sum + chapter.points.length, 0) }} 个知识点</em></span>
            <ChevronRight :size="17" class="catalog-arrow" />
          </NuxtLink>
          <div v-if="!subjects.length" class="empty-state"><BookOpen :size="18" />学科数据暂不可用，请稍后重试。</div>
        </div>
      </section>
      <NuxtLink class="back-link" to="/"><ChevronRight :size="15" class="back-icon" />返回首页</NuxtLink>
    </main>
  </div>
</template>
