<script setup lang="ts">
import { computed } from 'vue'
import { ArrowLeft, BookOpen, ChevronRight, RotateCw } from 'lucide-vue-next'
import type { Subject } from '~/types/api'
import { validateSubjects } from '~/utils/subjects.mjs'

useHead({ title: '资料库｜观澜考研政治知识库', meta: [{ name: 'description', content: '观澜考研政治六大学科资料导航。' }] })

const { data, pending, error, refresh } = await useApiFetch<Subject[]>('/subjects', [])

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
    <main class="page-wrap inner-page library-page">
      <Breadcrumbs />
      <section class="library-heading">
        <h1>资料库</h1>
        <p>按学科浏览教材章节与知识点。</p>
      </section>
      <section class="catalog-section">
        <div class="section-heading"><h2>学科目录</h2><span v-if="!pending && !error" class="catalog-count">{{ subjects.length }} 个学科</span></div>
        <p v-if="pending" class="library-state" role="status">正在加载学科…</p>
        <div v-else-if="error" class="library-state">
          <p role="alert">学科暂时加载失败。</p>
          <button class="ghost-button" type="button" @click="refresh()"><RotateCw :size="16" aria-hidden="true" />重试</button>
        </div>
        <p v-else-if="!subjects.length" class="library-state" role="status">暂无学科资料。</p>
        <ul v-else class="library-list">
          <li v-for="subject in subjects" :key="subject.slug">
            <NuxtLink :to="`/subjects/${subject.slug}`" class="library-row" :class="subject.tone">
              <span class="library-icon"><BookOpen :size="19" aria-hidden="true" /></span>
              <span class="library-copy"><strong>{{ subject.name }}</strong><span>{{ subject.detail }}</span><small>{{ subject.chapters.length }} 章 · {{ subject.chapters.reduce((sum, chapter) => sum + chapter.points.length, 0) }} 个知识点</small></span>
              <ChevronRight :size="17" aria-hidden="true" />
            </NuxtLink>
          </li>
        </ul>
      </section>
      <NuxtLink class="back-link" to="/"><ArrowLeft :size="15" aria-hidden="true" />返回首页</NuxtLink>
    </main>
    <SiteFooter />
  </div>
</template>
