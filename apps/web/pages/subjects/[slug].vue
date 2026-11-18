<script setup lang="ts">
import { ArrowLeft, ArrowDown, BookOpen, FileText, RotateCw } from 'lucide-vue-next'
import type { Subject } from '~/types/api'
import { getSubjectBySlug } from '~/utils/subjects.mjs'

const route = useRoute()
const { data, pending, error, refresh } = await useApiFetch<Subject[]>('/subjects', [])
const subject = computed(() => getSubjectBySlug(data.value ?? [], String(route.params.slug)))

useHead(() => ({ title: subject.value ? `${subject.value.short}｜${subject.value.name}｜观澜` : '学科资料｜观澜', meta: [{ name: 'description', content: subject.value?.intro ?? '观澜考研政治资料库' }] }))
</script>

<template>
  <div class="site-shell">
    <SiteHeader />
    <main class="page-wrap inner-page library-page">
      <Breadcrumbs :subject-name="subject?.name" />
      <p v-if="pending" class="library-state" role="status">正在加载学科…</p>
      <div v-else-if="error" class="library-state">
        <p role="alert">学科暂时加载失败。</p>
        <button class="ghost-button" type="button" @click="refresh()"><RotateCw :size="16" aria-hidden="true" />重试</button>
      </div>
      <template v-else-if="subject">
        <section class="library-heading" :class="subject.tone">
          <span class="library-icon"><BookOpen :size="20" aria-hidden="true" /></span>
          <h1>{{ subject.name }}</h1>
          <p>{{ subject.intro }}</p>
        </section>
        <section class="chapter-section">
          <div class="section-heading"><h2>章节与知识点</h2><span class="catalog-count">{{ subject.chapters.length }} 个章节</span></div>
          <nav v-if="subject.chapters.length > 1" class="library-toc" aria-label="章节目录">
            <ol class="library-list">
              <li v-for="(chapter, index) in subject.chapters" :key="chapter.id">
                <a class="library-toc-link" :href="`#${encodeURIComponent(chapter.id)}`">
                  <span class="library-number">{{ String(index + 1).padStart(2, '0') }}</span>
                  <span>{{ chapter.title }}</span>
                  <ArrowDown :size="15" aria-hidden="true" />
                </a>
              </li>
            </ol>
          </nav>
          <p v-if="!subject.chapters.length" class="library-state" role="status">这个学科暂无章节资料。</p>
          <div class="chapter-list">
            <article v-for="(chapter, index) in subject.chapters" :id="chapter.id" :key="chapter.id" class="library-chapter" tabindex="-1">
              <header>
                <span class="library-number">{{ String(index + 1).padStart(2, '0') }}</span>
                <div><h3>{{ chapter.title }}</h3><p v-if="chapter.summary">{{ chapter.summary }}</p></div>
              </header>
              <ul v-if="chapter.points.length" class="library-points">
                <li v-for="point in chapter.points" :key="point.title"><FileText :size="16" aria-hidden="true" /><div><h4>{{ point.title }}</h4><p v-if="point.summary">{{ point.summary }}</p></div></li>
              </ul>
              <p v-else class="library-state">本章暂无知识点。</p>
            </article>
          </div>
        </section>
      </template>
      <section v-else class="library-heading">
        <h1>没有找到这个学科</h1>
      </section>
      <NuxtLink class="back-link" to="/subjects"><ArrowLeft :size="15" aria-hidden="true" />返回资料库</NuxtLink>
    </main>
    <SiteFooter />
  </div>
</template>
