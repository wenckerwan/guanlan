<script setup lang="ts">
import { computed, reactive, ref, watch, onMounted } from 'vue'
import { ArrowLeft, BookOpen, Check, RotateCcw, Send, Sparkles } from 'lucide-vue-next'
import type { Handbook, MistakeItemsPayload, MistakeItem } from '~/types/api'
import { displayAnswer, isCorrect } from '~/utils/quiz.mjs'
import { normalizePage } from '~/utils/pagination.mjs'
import { withQuery } from '~/composables/useApi'

const route = useRoute()
const code = String(route.params.code)
const { request, isLoggedIn, restore } = useAuth()
if (import.meta.client) restore()

const router = useRouter()
const requestedPage = computed(() => Math.max(1, Number(route.query.page) || 1))
const page = ref(requestedPage.value)
const activeModule = ref(String(route.query.module ?? ''))
const perPage = 20
const itemsPath = computed(() => withQuery(`/mistakes/students/${code}/items`, {
  page: page.value,
  perPage,
  module: activeModule.value,
}))
const { data, error } = await useApiFetch<MistakeItemsPayload | null>(
  itemsPath,
  null,
  { watch: [page, activeModule] },
)
const { data: handbooks } = await useApiFetch<Handbook[]>(`/mistakes/students/${code}/handbooks`, [])

// 私有错题本对未登录/非绑定账号返回 403：回列表页并提示登录。
if ((error.value as { statusCode?: number } | null)?.statusCode === 403) {
  await navigateTo({ path: '/mistakes', query: { login: 1 } })
}

const student = computed(() => data.value?.student ?? null)
const items = computed(() => data.value?.items ?? [])

// 错题分析正文：管理员在后台上传后，这里立即可见（默认占位内容不渲染）
const analysis = ref<{ html: string; isDefault: boolean; updatedAt: string } | null>(null)
onMounted(async () => {
  try {
    analysis.value = await request<{ html: string; isDefault: boolean; updatedAt: string }>(
      `/mistakes/students/${code}/detail`
    )
  } catch {
    analysis.value = null
  }
})
const showAnalysis = computed(() => !!analysis.value && !analysis.value.isDefault && !!analysis.value.html)

const redoOpen = reactive<Record<number, boolean>>({})
const redoChoices = reactive<Record<number, string[]>>({})
const redoResults = reactive<Record<number, boolean | null>>({})
const redoActions = reactive<Record<number, string>>({})
const redoNotices = reactive<Record<number, string>>({})

const modules = computed(() => Object.keys(student.value?.moduleCounts ?? {}))
const total = computed(() => data.value?.total ?? student.value?.itemCount ?? 0)

watch(requestedPage, (value) => { page.value = value })
watch(() => String(route.query.module ?? ''), (value) => { activeModule.value = value })
watch(total, (value) => {
  const normalized = normalizePage(page.value, value, perPage)
  if (normalized !== page.value) page.value = normalized
})
watch(page, (value) => {
  const nextPage = value === 1 ? undefined : String(value)
  if (String(route.query.page ?? '') !== String(nextPage ?? '')) {
    router.replace({ query: { ...route.query, page: nextPage } })
  }
})
watch(activeModule, (value) => {
  if (page.value !== 1) page.value = 1
  const nextModule = value || undefined
  if (String(route.query.module ?? '') !== String(nextModule ?? '') || route.query.page) {
    router.replace({ query: { ...route.query, module: nextModule, page: undefined } })
  }
})

useHead(() => ({ title: student.value ? `考生 ${student.value.code} 错题｜观澜` : '错题分析｜观澜' }))

function marksOf(item: MistakeItem) {
  return item.options.filter((option) => option.mark)
}

function isMulti(item: MistakeItem) {
  return /多|multi/i.test(item.qType) || displayAnswer(item.correctAnswer).length > 1
}

function toggleRedo(item: MistakeItem) {
  redoOpen[item.id] = !redoOpen[item.id]
  if (redoOpen[item.id] && !redoChoices[item.id]) redoChoices[item.id] = []
}

function chooseRedo(item: MistakeItem, label: string) {
  const current = redoChoices[item.id] ?? []
  redoChoices[item.id] = isMulti(item)
    ? current.includes(label) ? current.filter((value) => value !== label) : [...current, label]
    : [label]
  redoResults[item.id] = null
  redoNotices[item.id] = ''
}

async function submitRedo(item: MistakeItem) {
  const chosen = displayAnswer((redoChoices[item.id] ?? []).join(''))
  if (!chosen) {
    redoNotices[item.id] = '请先选择答案。'
    return
  }

  const correct = displayAnswer(item.correctAnswer)
  const right = isCorrect(chosen, correct)
  redoResults[item.id] = right

  if (!isLoggedIn.value) {
    redoNotices[item.id] = '已完成判分；登录后可保存重练记录并自动安排下次复习。'
    return
  }

  try {
    // 使用新的复习 API 提交答题记录
    const reviewResult = await request(`/mistakes/items/${item.id}/review`, {
      method: 'POST',
      body: { chosen },
    })

    const action = reviewResult.isCorrect
      ? `本次重练正确！下次复习时间：${reviewResult.nextReviewAt}（${reviewResult.status === 'mastered' ? '已掌握' : '继续巩固'}）`
      : `本次答错，1 天后再次复习。已复习 ${reviewResult.reviewCount} 次，答对 ${reviewResult.correctCount} 次。`

    redoActions[item.id] = action
    redoNotices[item.id] = '已保存复习记录，系统已自动计算下次复习时间。'
  } catch (err: any) {
    // 降级到原有逻辑
    const action = right
      ? '本次重练正确，继续保持并定期回访。'
      : `本次重练仍未掌握，复习原错因（${item.errorType || '未记录'}）后再练。`

    try {
      await request(`/mistakes/items/${item.id}/action`, {
        method: 'PATCH',
        body: { action },
      })
      redoActions[item.id] = action
      redoNotices[item.id] = '已保存行动建议（复习系统暂不可用）。'
    } catch {
      redoNotices[item.id] = '已完成判分，但保存失败，请稍后重试。'
    }
  }
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
          <NuxtLink class="ghost-button" :to="`/mistakes/analyze/${code}`"><Sparkles :size="15" />AI 分析</NuxtLink>
          <NuxtLink v-if="isLoggedIn" class="primary-button" :to="`/mistakes/${code}/review`"><RotateCcw :size="15" />复习概览</NuxtLink>
          <NuxtLink v-if="handbooks.length" class="primary-button" :to="`/mistakes/${code}/${handbooks[0].id}`"><BookOpen :size="15" />看提分手册</NuxtLink>
        </div>
      </section>

      <section v-if="showAnalysis" class="mistake-analysis">
        <details open>
          <summary><Sparkles :size="14" />错题分析<time v-if="analysis?.updatedAt">（更新于 {{ analysis.updatedAt.slice(0, 10) }}）</time></summary>
          <div class="markdown-body" v-html="analysis?.html" />
        </details>
      </section>

      <section v-if="student" class="stat-row">
        <div v-for="[name, count] in Object.entries(student.errorTypes)" :key="name" class="stat-cell">
          <strong>{{ count }}</strong><small>{{ name }}</small>
        </div>
      </section>

      <nav class="filter-chips">
        <button type="button" class="chip" :class="{ active: activeModule === '' }" @click="activeModule = ''">全部模块</button>
        <button v-for="name in modules" :key="name" type="button" class="chip" :class="{ active: activeModule === name }" @click="activeModule = name">{{ name }} · {{ student?.moduleCounts[name] }}</button>
        <span class="filter-count">当前 {{ items.length }} 道 · 共 {{ total }} 道</span>
      </nav>

      <section class="quiz-body">
        <article v-for="item in items" :key="item.id" class="question-card mistake-card" :class="item.errorType === '既漏又错' ? 'wrong' : ''">
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
            <p v-if="redoActions[item.id] || item.personalAction || item.action" class="mistake-action"><strong>下次怎么做：</strong>{{ redoActions[item.id] || item.personalAction || item.action }}</p>
          </div>

          <button class="ghost-button small" type="button" @click="toggleRedo(item)">
            <RotateCcw :size="13" />{{ redoOpen[item.id] ? '收起重练' : '开始重练' }}
          </button>
          <div v-if="redoOpen[item.id]" class="redo-panel">
            <p class="redo-title">重新作答 · {{ isMulti(item) ? '多选题' : '单选题' }}</p>
            <ul class="option-list redo-options">
              <li v-for="option in item.options" :key="option.label" :class="{ chosen: redoChoices[item.id]?.includes(option.label) }">
                <button type="button" @click="chooseRedo(item, option.label)">
                  <span class="option-letter">{{ option.label }}</span>
                  <span class="option-text">{{ option.text }}</span>
                </button>
              </li>
            </ul>
            <button class="primary-button small" type="button" :disabled="!redoChoices[item.id]?.length" @click="submitRedo(item)">
              <Send :size="13" />提交答案
            </button>
            <p v-if="redoResults[item.id] !== null && redoResults[item.id] !== undefined" class="redo-state" :class="redoResults[item.id] ? 'success' : 'failure'">
              <Check :size="13" />{{ redoResults[item.id] ? '本次答对' : '本次答错' }} · 你的答案 {{ displayAnswer((redoChoices[item.id] ?? []).join('')) }} · 正确答案 {{ displayAnswer(item.correctAnswer) }}
            </p>
            <p v-if="redoNotices[item.id]" class="redo-notice">{{ redoNotices[item.id] }}</p>
          </div>
        </article>
      </section>

      <div v-if="!items.length" class="empty-state">暂无错题。</div>
      <PaginationControls :page="data?.page ?? page" :total="total" :per-page="data?.perPage ?? perPage" @change="page = $event" />
      <NuxtLink class="back-link" to="/mistakes"><ArrowLeft :size="15" />返回考生列表</NuxtLink>
    </main>
  </div>
</template>

<style scoped>
.mistake-analysis {
  margin: 1.5rem 0;
  padding: 1rem 1.25rem;
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-radius: 8px;
}

.mistake-analysis summary {
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 0.375rem;
  font-weight: 600;
  color: var(--text-primary);
}

.mistake-analysis summary time {
  font-weight: 400;
  font-size: 0.8125rem;
  color: var(--text-muted);
}

.mistake-analysis .markdown-body {
  margin-top: 0.75rem;
}
</style>
