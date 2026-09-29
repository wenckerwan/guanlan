<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { ArrowLeft, Check, RotateCcw, X } from 'lucide-vue-next'
import type { PaperDetail, Question } from '~/types/api'
import { displayAnswer, gradePaper, isCorrect, toLetters } from '~/utils/quiz.mjs'

const route = useRoute()
const pid = String(route.params.pid)
const { isLoggedIn, request } = useAuth()

useHead({ title: `${pid} 真题｜观澜考研政治知识库` })

const { data } = await useApiFetch<PaperDetail>(`/papers/${encodeURIComponent(pid)}`, { paper: null as never, questions: [] })

const questions = computed<Question[]>(() => data.value?.questions ?? [])
const answers = reactive<Record<number, string>>({})
const submitted = ref(false)
const collectNotice = ref('')

async function submitPaper() {
  submitted.value = true
  if (!isLoggedIn.value) return
  try {
    const result = await request<{ collected: number }>('/study/attempts/batch', {
      method: 'POST',
      body: { pid, answers: { ...answers } },
    })
    collectNotice.value = result.collected > 0
      ? `本次 ${result.collected} 道错题已自动加入你的错题本`
      : ''
  } catch {
    collectNotice.value = ''
  }
}

function toggle(question: Question, letter: string) {
  if (submitted.value) return
  const current = answers[question.id] ?? ''
  if (question.type === 'multi') {
    const set = new Set(current.split(''))
    if (set.has(letter)) set.delete(letter)
    else set.add(letter)
    answers[question.id] = [...set].sort().join('')
  } else {
    answers[question.id] = current === letter ? '' : letter
  }
}

const result = computed(() => gradePaper(
  questions.value.map((q) => ({ ...q, no: q.id })),
  answers,
))

function reset() {
  for (const key of Object.keys(answers)) delete answers[Number(key)]
  submitted.value = false
  collectNotice.value = ''
}

function stateOf(question: Question) {
  if (!submitted.value) return ''
  const chosen = answers[question.id] ?? ''
  if (!chosen) return 'blank'
  return isCorrect(chosen, question.answer) ? 'right' : 'wrong'
}
</script>

<template>
  <div class="site-shell">
    <SiteHeader />
    <main class="page-wrap inner-page">
      <Breadcrumbs />

      <section v-if="data?.paper" class="quiz-head">
        <div>
          <span class="section-kicker">真题作答</span>
          <h1>{{ data.paper.year }} 年考研政治真题{{ data.paper.label ? `（${data.paper.label}）` : '' }}</h1>
          <p>{{ data.paper.questionCount }} 题 · {{ data.paper.totalScore }} 分 · 含参考答案 {{ data.paper.answeredCount }} 题</p>
        </div>
        <div class="quiz-head-actions">
          <button class="ghost-button" type="button" @click="reset"><RotateCcw :size="15" />重做</button>
          <button class="primary-button" type="button" :disabled="submitted" @click="submitPaper"><Check :size="15" />交卷判分</button>
        </div>
      </section>

      <section v-if="submitted" class="grade-banner">
        <div><strong>{{ result.score }}</strong><small>得分</small></div>
        <div><strong>{{ result.right }}</strong><small>做对</small></div>
        <div><strong>{{ result.wrong }}</strong><small>做错</small></div>
        <div><strong>{{ result.blank }}</strong><small>未答</small></div>
        <div><strong>{{ result.accuracy }}%</strong><small>正确率</small></div>
        <span v-if="collectNotice" class="collect-notice">{{ collectNotice }}</span>
        <button class="ghost-button" type="button" @click="reset"><RotateCcw :size="14" />重新作答</button>
      </section>

      <section class="quiz-body">
        <article v-for="question in questions" :id="`q${question.no}`" :key="question.id" class="question-card" :class="stateOf(question)">
          <header class="question-header">
            <span class="q-no">{{ question.no }}</span>
            <span class="q-type">{{ question.typeCn }}</span>
            <span class="q-module">{{ question.moduleName }}</span>
            <span v-if="question.kaodian" class="q-kaodian">{{ question.kaodian }}</span>
            <span class="q-score">{{ question.score }} 分</span>
            <span v-if="submitted" class="q-state">
              <template v-if="stateOf(question) === 'right'"><Check :size="13" />正确</template>
              <template v-else-if="stateOf(question) === 'wrong'"><X :size="13" />错误</template>
              <template v-else>未作答</template>
            </span>
          </header>

          <p v-if="question.material" class="question-material">{{ question.material }}</p>
          <p class="question-stem">{{ question.stem }}</p>

          <ul v-if="Object.keys(question.options).length" class="option-list">
            <li v-for="(text, letter) in question.options" :key="letter" :class="{ chosen: toLetters(answers[question.id]).includes(String(letter)) }">
              <button type="button" :aria-pressed="toLetters(answers[question.id]).includes(String(letter))" @click="toggle(question, String(letter))">
                <span class="option-letter">{{ letter }}</span>
                <span class="option-text">{{ text }}</span>
              </button>
            </li>
          </ul>
          <p v-else class="question-open">本题为材料分析题，请自行组织语言作答。</p>

          <footer v-if="submitted && question.answer" class="question-answer">
            <p><strong>参考答案：</strong>{{ displayAnswer(question.answer) }}</p>
            <p v-if="answers[question.id]"><strong>你的作答：</strong>{{ displayAnswer(answers[question.id]) }}</p>
            <p v-if="question.analysis"><strong>解析：</strong>{{ question.analysis }}</p>
          </footer>
          <footer v-else-if="submitted" class="question-answer">
            <p class="muted">本题暂无参考答案，建议对照教材自行核对。</p>
          </footer>
        </article>
      </section>

      <div v-if="!questions.length" class="empty-state">本卷暂无题目数据。</div>
      <NuxtLink class="back-link" to="/papers"><ArrowLeft :size="15" />返回真题列表</NuxtLink>
    </main>
  </div>
</template>

<style scoped>
.question-material,
.question-stem,
.question-answer p {
  white-space: pre-line;
}

.collect-notice {
  color: var(--accent, #b45309);
  font-size: 0.875rem;
  font-weight: 600;
}
</style>