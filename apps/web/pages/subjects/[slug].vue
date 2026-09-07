<script setup lang="ts">
import { ArrowLeft, BookOpen, ChevronRight, FileText } from 'lucide-vue-next'
import { subjects } from '../../data/subjects'
import { getSubjectBySlug } from '../../utils/subjects.mjs'

const route = useRoute()
const subject = computed(() => getSubjectBySlug(subjects, String(route.params.slug)))

useHead(() => ({ title: subject.value ? `${subject.value.short}｜${subject.value.name}｜观澜` : '学科未找到｜观澜', meta: [{ name: 'description', content: subject.value?.intro ?? '观澜考研政治资料库' }] }))
</script>

<template>
  <div class="site-shell">
    <SiteHeader />
    <main class="page-wrap inner-page">
      <template v-if="subject">
        <Breadcrumbs :subject-name="subject.name" />
        <section class="subject-hero" :class="subject.tone">
          <div class="subject-hero-mark"><BookOpen :size="22" /></div>
          <div><span class="section-kicker">{{ subject.short }} · SUBJECT</span><h1>{{ subject.name }}</h1><p>{{ subject.intro }}</p></div>
        </section>
        <section class="chapter-section">
          <div class="section-heading"><div><span class="section-kicker">学习路径</span><h2>章节与知识点</h2></div><span class="catalog-count">{{ subject.chapters.length }} 个章节</span></div>
          <div class="chapter-list">
            <article v-for="(chapter, index) in subject.chapters" :id="chapter.id" :key="chapter.id" class="chapter-card">
              <div class="chapter-index">{{ String(index + 1).padStart(2, '0') }}</div>
              <div class="chapter-main"><h3>{{ chapter.title }}</h3><p>{{ chapter.summary }}</p><ul><li v-for="point in chapter.points" :key="point.title"><FileText :size="14" /><span><strong>{{ point.title }}</strong><small>{{ point.summary }}</small></span></li></ul></div>
            </article>
          </div>
        </section>
        <NuxtLink class="back-link" to="/subjects"><ArrowLeft :size="15" />返回资料库</NuxtLink>
      </template>
      <section v-else class="not-found-card">
        <span class="subject-icon"><BookOpen :size="20" /></span><h1>还没有这个学科</h1><p>请从资料库选择一个有效的学科入口。</p><NuxtLink class="primary-link" to="/subjects">前往资料库<ChevronRight :size="15" /></NuxtLink>
      </section>
    </main>
  </div>
</template>
