<script setup lang="ts">
import { computed, reactive } from 'vue'
import { ArrowLeft, BookOpen, Check, RotateCcw, X } from 'lucide-vue-next'
import type { Handbook, MistakeItemsPayload, MistakeItem } from '~/types/api'
import { displayAnswer } from '~/utils/quiz.mjs'

const route = useRoute()
const code = String(route.params.code)

const { data } = await useApiFetch<MistakeItemsPayload | null>(`/mistakes/students/${code}/items`, null)
const { data: handbooks } = await useApiFetch<Handbook[]>(`/mistakes/students/${code}/handbooks`, [])

const student = computed(() => data.value?.student ?? null)
const items = computed(() => data.value?.items ?? [])

const activeModule = ref('')
const showAnswer = reactive<Record<number, boolean>>({})

const modules = computed(() => Object.keys(student.value?.moduleCounts ?? {}))
const filtered = computed(() => (activeModule.value ? items.value.filter((item) => item.module === activeModule.value) : items.value))

useHead(() => ({ title: student.value ? `考生 ${student.value.code} 错题｜观澜` : '错题分析｜观澜' }))

function marksOf(item: MistakeItem) {
  return item.options.filter((option) => option.mark)
}
</script>

<template>
  <div class="site-shell">
    <SiteHeader />
    <main class="page-wrap inner-page">
      <Breadcrumbs />

      <section v-if="student" class="quiz-head">
        <div>
          <span class="section-kicker">考生 {{ student.code }} · {{ student.relation }}</span>
          <h1>{{ student.name }} 的错题册</h1>
          <p>共 {{ student.itemCount }} 道错题，按模块与错因归类，配提分手册。</p>
        </div>
        <div class="quiz-head-actions">
          <NuxtLink v-if="handbooks.length" class="primary-button" :to="`/mistakes/${code}/handbook/${handbooks[0].id}`"><BookOpen :size="15" />看提分手册</NuxtLink>
        </div>
      </section>

      <section v-if="student" class="stat-row">
        <div v-for="[name, count] in Object.entries(student.errorTypes)" :key="name" class="stat-cell">
          <strong>{{ count }}</strong><small>{{ name }}</small>
        </div>
      </section>

      <nav class="filter-chips">
        <button type="button" class="chip" :class="{ active: activeModule === '' }" @click="activeModule = ''">全部模块 · {{ items.length }}</button>
        <button v-for="name in modules" :key="name" type="button" class="chip" :class="{ active: activeModule === name }" @click="activeModule = name">{{ name }} · {{ student?.moduleCounts[name] }}</button>
      </nav>

      <section class="quiz-body">
        <article v-for="item in filtered" :key="item.id" class="question-card mistake-card" :class="item.errorType === '既漏又错' ? 'wrong' : ''">
          <header class="question-header">
            <span class="q-no">{{ item.sourceNo }}</span>
            <span class="q-type">{{ item.qType }}</span>
            <span class="q-module">{{ item.module }}</span>
            <span v-if="item.chapter" class="q-kaodian">{{ item.chapter }}</span>
            <span class="q-score error-tag">{{ item.errorType }}</span>
          </header>

          <p v-if="item.kaodian" class="mistake-kaodian">〔{{ item.kaodian }}〕</p>
          <p class="question-stem">{{ item.stem }}</p>

          <ul class="option-list static">
            <li v-for="option in item.options" :key="option.label" :class="option.mark">
              <span class="option-letter">{{ option.label }}</span>
              <span class="option-text">{{ option.text }}</span>
              <em v-if="option.mark === 'chosen'">我选了（错）</em>
              <em v-else-if="option.mark === 'missed'">正确项（我漏了）</em>
              <em v-else-if="option.mark === 'hit'">选对了</em>
            </li>
          </ul>

          <div class="mistake-answer">
            <p><strong>上次：</strong>我选 <b>{{ displayAnswer(item.myAnswer) || '未记录' }}</b> ｜ 正确 <b>{{ displayAnswer(item.correctAnswer) }}</b></p>
            <p v-if="item.action" class="mistake-action"><strong>下次怎么做：</strong>{{ item.action }}</p>
          </div>

          <button class="ghost-button small" type="button" @click="showAnswer[item.id] = !showAnswer[item.id]">
            <RotateCcw :size="13" />{{ showAnswer[item.id] ? '收起' : '标记已重做' }}
          </button>
          <p v-if="showAnswer[item.id]" class="redo-state"><Check :size="13" />已重做，记得回访清单核对。</p>
        </article>
      </section>

      <div v-if="!filtered.length" class="empty-state">该筛选条件下暂无错题。</div>
      <NuxtLink class="back-link" to="/mistakes"><ArrowLeft :size="15" />返回考生列表</NuxtLink>
    </main>
  </div>
</template>