<script setup lang="ts">
import { computed, nextTick, reactive, ref } from 'vue'
import { ArrowLeft, Check, RotateCcw, TimerReset, X } from 'lucide-vue-next'
import type { MockDetail, MockQuestion } from '~/types/api'
import { displayAnswer, isCorrect, scoreOf, sectionOf, toLetters } from '~/utils/quiz.mjs'

const route = useRoute()
const slug = String(route.params.slug)

const { data, pending, error, refresh } = await useApiFetch<MockDetail | null>(`/mocks/${encodeURIComponent(slug)}`, null)
const mock = computed(() => data.value?.mock)
const questions = computed<MockQuestion[]>(() => data.value?.questions ?? [])

const answers = reactive<Record<number, string>>({})
const submitted = ref(false)
const grade = ref<HTMLElement | null>(null)

async function submit() {
  submitted.value = true
  await nextTick()
  grade.value?.focus()
}

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
    <main class="page-wrap inner-page library-page mock-page">
      <Breadcrumbs parent-name="模拟押题" parent-path="/mocks" :subject-name="mock?.title" />

      <p v-if="pending" class="library-state" role="status">正在加载试卷…</p>
      <div v-else-if="error" class="library-state">
        <p role="alert">{{ error.statusCode === 404 ? '这套模拟卷不存在。' : '试卷加载失败。' }}</p>
        <button v-if="error.statusCode !== 404" class="ghost-button" type="button" @click="refresh()">重新加载</button>
      </div>
      <section v-else-if="mock" class="quiz-head">
        <div>
          <span class="section-kicker">{{ mock.durationMinutes }} 分钟 · 满分 {{ mock.totalScore }}</span>
          <h1>{{ mock.title }}</h1>
          <p>{{ mock.summary }}</p>
        </div>
        <div v-if="questions.length" class="quiz-head-actions">
          <span class="answered-count">已答 {{ answeredCount }} / {{ mock.questionCount }}</span>
          <button class="ghost-button" type="button" @click="reset"><RotateCcw :size="15" />清空</button>
          <button class="primary-button" type="button" :disabled="submitted" @click="submit"><Check :size="15" />交卷判分</button>
        </div>
      </section>
      <p v-if="!pending && !error && !questions.length" class="library-state" role="status">{{ mock ? '这套模拟卷暂无题目。' : '暂无试卷内容。' }}</p>

      <section v-if="submitted" ref="grade" class="grade-banner" tabindex="-1" aria-label="判分结果">
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
              <button type="button" :aria-pressed="toLetters(answers[question.no]).includes(String(letter))" :disabled="submitted" @click="toggle(question, String(letter))">
                <span class="option-letter">{{ letter }}</span>
                <span class="option-text">{{ text }}</span>
              </button>
            </li>
          </ul>
          <p v-else class="question-open">本题为材料分析题，请自行组织语言作答。</p>

          <footer v-if="submitted && (question.answer || question.analysis)" class="question-answer">
            <p v-if="question.answer"><strong>参考答案：</strong>{{ displayAnswer(question.answer) }}</p>
            <p v-if="answers[question.no]"><strong>你的作答：</strong>{{ displayAnswer(answers[question.no]) }}</p>
            <p v-if="question.analysis"><strong>解析：</strong>{{ question.analysis }}</p>
          </footer>
        </article>
      </section>

      <NuxtLink class="back-link" to="/mocks"><ArrowLeft :size="15" />返回模拟卷列表</NuxtLink>
    </main>
    <SiteFooter />
  </div>
</template>

<style scoped>
.quiz-head, .question-card { overflow-wrap: anywhere; }
.quiz-head h1 { font-size: 28px; }
.quiz-head p { font-size: 14px; }
.quiz-head-actions { flex-wrap: wrap; }
.grade-banner { padding: 14px 16px; gap: 12px 20px; scroll-margin-top: 92px; font-variant-numeric: tabular-nums; }
.grade-note { flex-basis: 100%; margin: 0; }
.question-answer { padding: 12px; border: 0; border-radius: var(--gl-radius-control); background: var(--gl-page); }
.question-answer p { white-space: pre-wrap; }
.question-answer p:last-child { margin-bottom: 0; }
.option-list button:disabled { cursor: default; }
.option-list li:not(.chosen) > button:disabled:hover { border-color: var(--gl-line); }
@media (max-width: 560px) {
  .question-card { padding: 14px; }
}
</style>
