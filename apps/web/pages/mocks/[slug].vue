<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { ArrowLeft, Check, RotateCcw, TimerReset, X } from 'lucide-vue-next'
import type { MockDetail, MockQuestion } from '~/types/api'
import { displayAnswer, isCorrect, scoreOf, sectionOf, toLetters } from '~/utils/quiz.mjs'

const route = useRoute()
const slug = String(route.params.slug)

const { data } = await useApiFetch<MockDetail>(`/mocks/${slug}`, { mock: null as never, questions: [] })
const mock = computed(() => data.value?.mock)
const questions = computed<MockQuestion[]>(() => data.value?.questions ?? [])

const answers = reactive<Record<number, string>>({})
const submitted = ref(false)

useHead(() => ({ title: mock.value ? `${mock.value.title}｜观澜模拟` : '模拟押题｜观澜' }))

function toggle(question: MockQuestion, letter: string) {
  if (submitted.value) return
  const current = answers[question.no] ?? ''
  if (question.type === 'multi') {
    const set = new Set(current.split(''))
    if (set.has(letter)) set.delete(letter)
    else set.add(letter)
    answers[question.no] = [...set].sort().join('')
  } else {
    answers[question.no] = current === letter ? '' : letter
  }
}

function reset() {
  for (const key of Object.keys(answers)) delete answers[Number(key)]
  submitted.value = false
}

const summary = computed(() => {
  let score = 0
  let right = 0
  let wrong = 0
  let blank = 0
  for (const question of questions.value) {
    if (question.type === 'analyse') continue
    const chosen = answers[question.no] ?? ''
    if (!chosen) { blank += 1; continue }
    if (isCorrect(chosen, question.answer)) { right += 1; score += scoreOf(question.no) }
    else wrong += 1
  }
  const done = right + wrong
  return { score, right, wrong, blank, accuracy: done ? Math.round((right / done) * 100) : 0 }
})

function stateOf(question: MockQuestion) {
  if (!submitted.value || question.type === 'analyse') return ''
  const chosen = answers[question.no] ?? ''
  if (!chosen) return 'blank'
  return isCorrect(chosen, question.answer) ? 'right' : 'wrong'
}

const answeredCount = computed(() => Object.values(answers).filter(Boolean).length)
</script>

<template>
  <div class="site-shell">
    <SiteHeader />
    <main class="page-wrap inner-page">
      <Breadcrumbs />

      <section v-if="mock" class="quiz-head">
        <div>
          <span class="section-kicker">{{ mock.durationMinutes }} 分钟 · 满分 {{ mock.totalScore }}</span>
          <h1>{{ mock.title }}</h1>
          <p>{{ mock.summary }}</p>
        </div>
        <div class="quiz-head-actions">
          <span class="answered-count">已答 {{ answeredCount }} / {{ mock.questionCount }}</span>
          <button class="ghost-button" type="button" @click="reset"><RotateCcw :size="15" />清空</button>
          <button class="primary-button" type="button" :disabled="submitted" @click="submitted = true"><Check :size="15" />交卷判分</button>
        </div>
      </section>

      <section v-if="submitted" class="grade-banner">
        <div><strong>{{ summary.score }}</strong><small>客观题得分</small></div>
        <div><strong>{{ summary.right }}</strong><small>做对</small></div>
        <div><strong>{{ summary.wrong }}</strong><small>做错</small></div>
        <div><strong>{{ summary.blank }}</strong><small>未答</small></div>
        <div><strong>{{ summary.accuracy }}%</strong><small>客观题正确率</small></div>
        <p class="grade-note"><TimerReset :size="14" />分析题 50 分需自行对照参考框架估分。</p>
      </section>

      <section class="quiz-body">
        <article v-for="question in questions" :id="`q${question.no}`" :key="question.no" class="question-card" :class="stateOf(question)">
          <header class="question-header">
            <span class="q-no">{{ question.no }}</span>
            <span class="q-type">{{ question.typeCn }}</span>
            <span class="q-module">{{ sectionOf(question.no).label }}</span>
            <span class="q-score">{{ question.score }} 分</span>
            <span v-if="submitted && question.type !== 'analyse'" class="q-state">
              <template v-if="stateOf(question) === 'right'"><Check :size="13" />正确</template>
              <template v-else-if="stateOf(question) === 'wrong'"><X :size="13" />错误</template>
              <template v-else>未作答</template>
            </span>
          </header>

          <p class="question-stem">{{ question.stem }}</p>

          <ul v-if="Object.keys(question.options).length" class="option-list">
            <li v-for="(text, letter) in question.options" :key="letter" :class="{ chosen: toLetters(answers[question.no]).includes(String(letter)) }">
              <button type="button" @click="toggle(question, String(letter))">
                <span class="option-letter">{{ letter }}</span>
                <span class="option-text">{{ text }}</span>
              </button>
            </li>
          </ul>
          <p v-else class="question-open">本题为材料分析题，请自行组织语言作答。</p>

          <footer v-if="submitted && question.answer" class="question-answer">
            <p><strong>参考答案：</strong>{{ displayAnswer(question.answer) }}</p>
            <p v-if="answers[question.no]"><strong>你的作答：</strong>{{ displayAnswer(answers[question.no]) }}</p>
          </footer>
        </article>
      </section>

      <NuxtLink class="back-link" to="/mocks"><ArrowLeft :size="15" />返回模拟卷列表</NuxtLink>
    </main>
    <SiteFooter />
  </div>
</template>