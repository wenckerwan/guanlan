<script setup lang="ts">
import { ClipboardList, ShieldCheck } from 'lucide-vue-next'
import type { MistakeStudent } from '~/types/api'
import { truncate } from '~/utils/articles.mjs'

useHead({ title: '错题分析｜观澜考研政治知识库', meta: [{ name: 'description', content: '按考生隔离的错题分析与提分手册，含错因统计与重做清单。' }] })

const { data } = await useApiFetch<MistakeStudent[]>('/mistakes/students', [])
const students = computed(() => data.value ?? [])

function topErrors(record: Record<string, number>) {
  return Object.entries(record).sort((a, b) => b[1] - a[1]).slice(0, 3)
}
</script>

<template>
  <div class="site-shell">
    <SiteHeader />
    <main class="page-wrap inner-page">
      <Breadcrumbs />
      <section class="page-hero">
        <div class="eyebrow"><ClipboardList :size="14" />个人错题分析</div>
        <h1>不告诉你错了什么，<br class="mobile-only" />只告诉你下次怎么做</h1>
        <p>每位考生独立目录、互不引用。已收录 {{ students.length }} 位考生的错题与提分手册。</p>
      </section>

      <section class="student-grid">
        <NuxtLink v-for="student in students" :key="student.code" :to="`/mistakes/${student.code}`" class="student-card">
          <header>
            <span class="student-code">考生 {{ student.code }}</span>
            <strong>{{ student.name }}</strong>
            <small>{{ student.relation }}</small>
          </header>
          <div class="student-stat">
            <b>{{ student.itemCount }}</b><small>道错题</small>
          </div>
          <ul class="student-modules">
            <li v-for="[name, count] in Object.entries(student.moduleCounts)" :key="name">{{ name }} <b>{{ count }}</b></li>
          </ul>
          <ul class="student-errors">
            <li v-for="[name, count] in topErrors(student.errorTypes)" :key="name">{{ name }} · {{ count }}</li>
          </ul>
          <p class="student-note"><ShieldCheck :size="13" />数据在考生之间完全隔离</p>
        </NuxtLink>
      </section>
      <div v-if="!students.length" class="empty-state">错题数据暂不可用。</div>
      <NuxtLink class="back-link" to="/">返回首页</NuxtLink>
    </main>
  </div>
</template>