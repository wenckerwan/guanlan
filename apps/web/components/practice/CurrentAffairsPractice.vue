<script setup lang="ts">
import { Bookmark, Check, ChevronDown, Moon, Printer, Search, Sun, X } from 'lucide-vue-next'
import { filterPracticeQuestions, pagePracticeQuestions } from '~/lib/practice-study.mjs'
import type { PracticeQuestion } from '~/composables/usePracticeStudy'

const props = defineProps<{ study: ReturnType<typeof usePracticeStudy> }>()
const study = props.study
const questions = computed(() => study.bank.value?.questions ?? [])
const filter = reactive({ module: 'all', type: 'all', status: 'all', keyword: '' })
const page = ref(1)
const drafts = ref<Record<string, string[]>>({})
const active = ref<Record<string, boolean>>({})
const openAnalysis = ref<Record<string, boolean>>({})
const escaped = ref('')
const printAnswers = ref(true)
const printing = ref(false)
const night = ref(false)
const moduleLabels: Record<string, string> = { sx: '习思想', xs: '时政与当代', sg: '史纲', sc: '思法' }
const modules = computed(() => [...new Set(questions.value.map(q => q.module))])
const questionTypes = [{ key: 'all', label: '全部' }, { key: 'single', label: '单选' }, { key: 'multi', label: '多选' }]
const hasFilters = computed(() => filter.module !== 'all' || filter.type !== 'all' || filter.status !== 'all' || filter.keyword !== '')
const combinedStates = computed(() => {
  if (study.loggedIn.value) return study.states.value
  const states: Record<string, { attemptCount: number; lastIsRight: boolean }> = {}
  for (const [ref, result] of Object.entries(study.guestResults.value)) states[ref] = { attemptCount: 1, lastIsRight: result.isRight }
  return states
})
const filtered = computed(() => filterPracticeQuestions(questions.value, filter, combinedStates.value, study.favorites.value))
const pagination = computed(() => pagePracticeQuestions(filtered.value, page.value))
const visible = computed(() => printing.value ? filtered.value : pagination.value.items)
const done = computed(() => Object.values(combinedStates.value).filter(s => s.attemptCount).length)
const right = computed(() => Object.values(combinedStates.value).filter(s => s.lastIsRight).length)
const pendingRefs = computed(() => new Set(study.pending.value.map(event => event.questionRef)))
function resetFilters() {
  Object.assign(filter, { module: 'all', type: 'all', status: 'all', keyword: '' })
}
watch(() => [filter.module, filter.type, filter.status, filter.keyword], () => { page.value = 1; escaped.value = '' })
watch(() => pagination.value.page, value => { if (page.value !== value) page.value = value })

function questionResult(q: PracticeQuestion) {
  if (active.value[q.questionRef]) return null
  if (!study.loggedIn.value) return study.guestResults.value[q.questionRef] ?? null
  const state = study.states.value[q.questionRef]
  if (!state?.attemptCount) return null
  return { chosen: state.lastChosen ?? [], isRight: Boolean(state.lastIsRight), correct: state.lastCorrect ?? '' }
}
function answerKeys(result: { correct: string | string[] } | null) {
  return result ? (Array.isArray(result.correct) ? result.correct : result.correct.split('')) : []
}
function pick(q: PracticeQuestion, letter: string) {
  if (questionResult(q) || pendingRefs.value.has(q.questionRef)) return
  const current = drafts.value[q.questionRef] ?? []
  drafts.value = { ...drafts.value, [q.questionRef]: q.type === 'single' ? (current.includes(letter) ? [] : [letter]) : (current.includes(letter) ? current.filter(c => c !== letter) : [...current, letter].sort()) }
}
async function submit(q: PracticeQuestion) {
  const chosen = drafts.value[q.questionRef] ?? []
  if (!chosen.length) return
  const outcome = await study.submit(q, chosen)
  if (!outcome) return
  active.value = { ...active.value, [q.questionRef]: false }
  openAnalysis.value = { ...openAnalysis.value, [q.questionRef]: true }
  afterChange(q, false)
}
function redo(q: PracticeQuestion) {
  active.value = { ...active.value, [q.questionRef]: true }
  drafts.value = { ...drafts.value, [q.questionRef]: [] }
  openAnalysis.value = { ...openAnalysis.value, [q.questionRef]: false }
  afterChange(q, true)
}
function afterChange(q: PracticeQuestion, retry: boolean) {
  nextTick(() => {
    if (filtered.value.some(item => item.questionRef === q.questionRef)) return
    escaped.value = q.questionRef
    escapedRetry.value = retry
  })
}
const escapedRetry = ref(false)
function revealEscaped() {
  const ref = escaped.value
  filter.status = 'all'
  filter.module = 'all'
  filter.type = 'all'
  filter.keyword = ''
  nextTick(() => {
    const index = filtered.value.findIndex(q => q.questionRef === ref)
    page.value = Math.floor(index / 6) + 1
    openAnalysis.value = { ...openAnalysis.value, [ref]: !escapedRetry.value }
    escaped.value = ''
    nextTick(() => document.getElementById(`q-${ref}`)?.scrollIntoView({ block: 'center' }))
  })
}
function goTo(ref: string) {
  const index = filtered.value.findIndex(q => q.questionRef === ref)
  if (index < 0) { filter.module = 'all'; filter.type = 'all'; filter.status = 'all'; filter.keyword = '' }
  nextTick(() => {
    const position = filtered.value.findIndex(q => q.questionRef === ref)
    if (position < 0) return
    page.value = Math.floor(position / 6) + 1
    nextTick(() => document.getElementById(`q-${ref}`)?.scrollIntoView({ block: 'center' }))
  })
}
function print() {
  printing.value = true
  nextTick(() => window.print())
}
function afterPrint() { printing.value = false }
onMounted(() => {
  night.value = localStorage.getItem('guanlan.practice.theme') === 'dark'
  window.addEventListener('afterprint', afterPrint)
  const ref = decodeURIComponent(location.hash.slice(1)).replace(/^q-/, '')
  if (questions.value.some(q => q.questionRef === ref)) goTo(ref)
})
onBeforeUnmount(() => window.removeEventListener('afterprint', afterPrint))
watch(night, value => { if (import.meta.client) localStorage.setItem('guanlan.practice.theme', value ? 'dark' : 'light') })
</script>

<template>
  <div class="current-affairs" :class="{ night, 'print-answers': printAnswers }">
    <section class="practice-hero">
      <div><span class="eyebrow">2027 考研政治 · 原创专题练习</span><h1>时政练习题</h1>
        <p>按真题命题方式整理，内容截至 2026 年 10 月 10 日。专题题库，不是整套模拟卷。</p>
        <div class="hero-meta"><span>{{ study.bank.value?.questionCount }} 道题</span><span>单选与多选</span><span>{{ study.loggedIn.value ? '账号记录已开启' : '游客试玩 · 不同步账号' }}</span></div>
      </div>
      <div class="hero-stat"><b>{{ done }}</b><span>已做 / {{ study.bank.value?.questionCount }}</span><small>最近正确 {{ right }} 道</small></div>
    </section>
    <div v-if="study.message.value" class="practice-notice" role="status">{{ study.message.value }}</div>
    <div v-if="study.error.value" class="practice-error" role="alert">{{ study.error.value }}</div>
    <div v-if="study.pending.value.length" class="practice-notice" role="status">
      {{ study.pending.value.length }} 次作答仍在本机。<button v-if="study.pending.value.some(item => !item.conflict)" type="button" @click="study.retryPending">重试保存</button>
      <ul class="pending-list"><li v-for="item in study.pending.value" :key="item.eventId">{{ item.questionRef }} · {{ item.conflict ? '版本冲突，无法自动重试' : '待同步' }}<button v-if="item.conflict" type="button" @click="study.discardPending(item.eventId)">清除这条本地记录</button></li></ul>
    </div>
    <div v-if="!study.loggedIn.value" class="practice-notice">游客仅可试玩 {{ questions.length }} 道；结果只在本次页面中显示，不进入个人统计。<NuxtLink to="/login?redirect=%2Fpractice%2Fcurrent-affairs">登录后练习全部题目</NuxtLink></div>
    <div class="practice-workspace">
      <aside class="practice-sidebar">
        <section class="practice-panel"><h2>按模块练习</h2>
          <button type="button" :aria-pressed="filter.module === 'all'" @click="filter.module = 'all'">全部 <span>{{ questions.length }}</span></button>
          <button v-for="module in modules" :key="module" type="button" :aria-pressed="filter.module === module" @click="filter.module = module">{{ moduleLabels[module] ?? module }} <span>{{ questions.filter(q => q.module === module).length }}</span></button>
        </section>
        <section class="practice-panel"><h2>练习进度</h2><p>{{ done }} / {{ study.bank.value?.questionCount }}</p><div class="progress-track"><span :style="{ width: `${done / (study.bank.value?.questionCount || 1) * 100}%` }" /></div><small>账号练习按服务端记录，试玩仅在当前页面。</small></section>
        <details class="practice-panel reading-tools">
          <summary><span><Printer :size="16" aria-hidden="true" />阅读与打印</span><ChevronDown :size="16" aria-hidden="true" /></summary>
          <button type="button" :aria-pressed="night" @click="night = !night"><Sun v-if="night" :size="16" aria-hidden="true" /><Moon v-else :size="16" aria-hidden="true" />{{ night ? '跟随全站外观' : '夜读模式' }}</button>
          <label class="print-choice"><input v-model="printAnswers" type="checkbox" />打印答案与解析</label>
          <button type="button" @click="print"><Printer :size="16" aria-hidden="true" />打印筛选结果</button>
        </details>
      </aside>
      <div class="practice-content">
        <div class="practice-toolbar"><label class="search-field"><Search :size="18" aria-hidden="true" /><span class="sr-only">搜索题目</span><input v-model="filter.keyword" type="search" placeholder="搜索题干、选项或考点" /></label>
          <div class="practice-filters"><div class="segments" role="group" aria-label="题型"><button v-for="item in questionTypes" :key="item.key" type="button" :aria-pressed="filter.type === item.key" @click="filter.type = item.key">{{ item.label }}</button></div>
            <label>练习状态 <select v-model="filter.status"><option value="all">全部</option><option value="todo">未做</option><option value="done">已做</option><option value="wrong">当前错题</option><option value="saved">我的收藏</option></select></label>
            <button v-if="hasFilters && visible.length" type="button" class="reset-filters" @click="resetFilters">清除筛选</button>
          </div>
        </div>
        <div v-if="escaped" class="practice-notice" role="status">{{ escaped }} 已移出当前筛选。<button type="button" @click="revealEscaped">{{ escapedRetry ? '继续作答' : '查看本题解析' }}</button><button type="button" @click="escaped = ''">关闭</button></div>
        <div class="practice-list-title"><h2>{{ filter.module === 'all' ? '全部题目' : moduleLabels[filter.module] ?? filter.module }}</h2><span aria-live="polite">共 {{ pagination.total }} 题<span v-if="pagination.total && !printing"> · 显示 {{ (pagination.page - 1) * 6 + 1 }}—{{ Math.min(pagination.page * 6, pagination.total) }}</span></span></div>
        <div v-if="!visible.length" class="practice-empty">
          <p>{{ questions.length ? '没有符合筛选条件的题目。' : '当前题库暂无题目。' }}</p>
          <button v-if="questions.length && hasFilters" type="button" @click="resetFilters">清除筛选</button>
        </div>
        <article v-for="(q, index) in visible" :id="`q-${q.questionRef}`" :key="q.questionRef" class="practice-card">
          <header class="practice-card-head"><div><strong class="q-num">{{ printing ? filtered.indexOf(q) + 1 : (pagination.page - 1) * 6 + index + 1 }}</strong><span class="type-badge">{{ q.type === 'single' ? '单选' : '多选' }}</span><span class="module-tag">{{ q.moduleLabel ?? moduleLabels[q.module] }}</span><code>{{ q.questionRef }}</code></div>
            <button type="button" class="bookmark" :aria-pressed="study.favorites.value.includes(q.questionRef)" :title="study.favorites.value.includes(q.questionRef) ? '取消收藏' : '收藏题目'" :aria-label="`${study.favorites.value.includes(q.questionRef) ? '取消收藏' : '收藏'}${q.questionRef}`" @click="study.toggleFavorite(q)"><Bookmark :size="18" aria-hidden="true" /></button></header>
          <h3 class="sr-only">{{ q.questionRef }} {{ q.type === 'single' ? '单选' : '多选' }}</h3><p class="stem">{{ q.stem }}</p>
          <fieldset class="options"><legend class="sr-only">请选择{{ q.type === 'single' ? '一项' : '所有符合题意的项' }}</legend>
            <button v-for="(option, letter) in q.options" :key="letter" type="button" class="option" :class="{ selected: (questionResult(q)?.chosen ?? drafts[q.questionRef] ?? []).includes(String(letter)), correct: questionResult(q) && answerKeys(questionResult(q)).includes(String(letter)), incorrect: questionResult(q) && (questionResult(q)?.chosen ?? []).includes(String(letter)) && !answerKeys(questionResult(q)).includes(String(letter)) }" :disabled="!!questionResult(q) || pendingRefs.has(q.questionRef)" :aria-pressed="(questionResult(q)?.chosen ?? drafts[q.questionRef] ?? []).includes(String(letter))" @click="pick(q, String(letter))">
              <b>{{ letter }}</b><span>{{ option }}</span>
              <span v-if="questionResult(q) && answerKeys(questionResult(q)).includes(String(letter))" class="option-mark"><Check :size="18" aria-hidden="true" /><span class="sr-only">正确选项</span></span>
              <span v-else-if="questionResult(q)?.chosen.includes(String(letter))" class="option-mark"><X :size="18" aria-hidden="true" /><span class="sr-only">选错</span></span>
            </button>
          </fieldset>
          <div class="practice-actions"><button v-if="!questionResult(q)" type="button" class="primary" :disabled="!(drafts[q.questionRef]?.length) || pendingRefs.has(q.questionRef)" @click="submit(q)">提交答案</button><button v-else type="button" @click="redo(q)">重新作答</button><span v-if="pendingRefs.has(q.questionRef)" class="pending">待同步 · 请重试保存</span><span v-if="questionResult(q)" :class="questionResult(q)?.isRight ? 'good' : 'bad'">{{ questionResult(q)?.isRight ? '回答正确' : '本题答错 · 请核对解析' }}</span></div>
          <details class="explanation" :open="openAnalysis[q.questionRef] || printing && printAnswers" @toggle="openAnalysis[q.questionRef] = ($event.target as HTMLDetailsElement).open"><summary>答案与解析 <ChevronDown :size="18" aria-hidden="true" /></summary>
            <div class="explanation-body"><p class="answer-line">参考答案 <strong>{{ answerKeys(questionResult(q)).join('') || (printing && printAnswers ? q.answer ?? '暂无' : study.loggedIn.value ? '提交后显示' : q.answer ?? '暂无') }}</strong></p><p class="analysis">{{ q.analysis }}</p>
              <details v-if="q.event || q.knowledge || q.sources?.length" class="evidence"><summary>考点与资料依据</summary><p v-if="q.event"><b>对应事件</b><br />{{ q.event }}</p><p v-if="q.knowledge"><b>知识接口</b><br />{{ q.knowledge }}</p><div class="source-links"><a v-for="source in q.sources ?? []" :key="source.url" :href="source.url" target="_blank" rel="noopener noreferrer">{{ source.label }} ↗</a></div></details>
            </div></details>
        </article>
        <nav v-if="pagination.total > 6 && !printing" class="practice-pagination" aria-label="题目分页"><button type="button" :disabled="pagination.page <= 1" @click="page--">上一页</button><span>{{ pagination.page }} / {{ pagination.pages }}</span><button type="button" :disabled="pagination.page >= pagination.pages" @click="page++">下一页</button></nav>
      </div>
    </div>
  </div>
</template>

<style scoped>
.current-affairs {
  --practice-good: light-dark(#17604a, #8addbd);
  --practice-bad: light-dark(#a22638, #ff9ba7);
  --practice-warn: light-dark(#78500a, #efc57d);
  color: var(--gl-ink);
  margin-block: 16px 48px;
}
.current-affairs.night {
  color-scheme: dark;
  background: var(--gl-page);
  box-shadow: 0 0 0 8px var(--gl-page);
  border-radius: var(--gl-radius-card);
}
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  overflow: hidden;
  clip-path: inset(50%);
  white-space: nowrap;
  border: 0;
}
.current-affairs :where(button, input, select) { font: inherit; }
.current-affairs :focus-visible { outline: 2px solid var(--gl-accent); outline-offset: 2px; }
.current-affairs a { color: var(--gl-accent); }
.current-affairs button {
  min-height: 44px;
  padding: 8px 12px;
  border: 1px solid var(--gl-line);
  border-radius: var(--gl-radius-control);
  background: var(--gl-surface);
  color: var(--gl-ink);
  cursor: pointer;
}
.current-affairs button:not(:disabled):hover { border-color: var(--gl-accent-line); }
.current-affairs button:disabled { opacity: .58; cursor: default; }
.practice-hero {
  display: flex;
  align-items: end;
  justify-content: space-between;
  gap: 24px;
  padding: 8px 0 20px;
  margin-bottom: 16px;
  border-bottom: 1px solid var(--gl-line);
}
.eyebrow { font-size: 13px; font-weight: 650; color: var(--gl-accent); }
.practice-hero h1 { margin: 4px 0 8px; font-size: 36px; line-height: 1.2; }
.practice-hero p { margin: 0; color: var(--gl-muted); font-size: 14px; }
.hero-meta { display: flex; flex-wrap: wrap; gap: 4px 12px; margin-top: 10px; color: var(--gl-muted); font-size: 12px; }
.hero-stat { display: flex; flex: none; align-items: baseline; flex-wrap: wrap; gap: 2px 8px; color: var(--gl-muted); font-size: 13px; }
.hero-stat b { color: var(--gl-ink); font-size: 30px; line-height: 1; }
.hero-stat small { width: 100%; }
.hero-stat, .q-num, .practice-list-title span, .practice-panel p, .practice-pagination { font-variant-numeric: tabular-nums; }
.practice-notice, .practice-error {
  padding: 10px 14px;
  margin: 0 0 16px;
  border: 1px solid var(--gl-line);
  border-radius: var(--gl-radius-control);
  background: var(--gl-surface);
  font-size: 13px;
  overflow-wrap: anywhere;
}
.practice-error { border-color: var(--gl-accent-line); color: var(--practice-bad); }
.practice-notice a, .practice-notice button { display: inline-flex; align-items: center; min-height: 44px; margin-inline-start: 8px; }
.pending-list { margin: 4px 0; padding-inline-start: 20px; }
.pending-list li + li { margin-top: 6px; }
.practice-workspace { display: grid; grid-template-columns: minmax(0, 210px) minmax(0, 1fr); gap: 24px; }
.practice-sidebar { display: grid; align-content: start; gap: 16px; min-width: 0; }
.practice-panel { padding-bottom: 12px; border-bottom: 1px solid var(--gl-line); min-width: 0; }
.practice-panel h2 { margin: 0 0 6px; font-size: 14px; }
.practice-panel > button {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  width: 100%;
  border-color: transparent;
  background: transparent;
  font-size: 13px;
  text-align: start;
  overflow-wrap: anywhere;
}
.practice-panel > button[aria-pressed="true"] { color: var(--gl-accent); background: var(--gl-accent-soft); border-color: var(--gl-accent-line); font-weight: 650; }
.practice-panel button span, .practice-panel small { color: var(--gl-muted); }
.practice-panel p { margin: 0; font-size: 14px; }
.progress-track { height: 6px; margin: 8px 0; overflow: hidden; border-radius: var(--gl-radius-pill); background: var(--gl-secondary); }
.progress-track span { display: block; height: 100%; background: var(--gl-accent); }
.reading-tools > button { justify-content: start; }
.print-choice { display: flex; align-items: center; gap: 8px; min-height: 44px; padding-inline: 12px; font-size: 13px; }
.print-choice input { accent-color: var(--gl-action); }
.practice-content { min-width: 0; }
.practice-toolbar { display: grid; gap: 10px; margin-bottom: 18px; }
.search-field {
  display: flex;
  align-items: center;
  gap: 10px;
  padding-inline: 12px;
  background: var(--gl-surface);
  color: var(--gl-muted);
  border: 1px solid var(--gl-line);
  border-radius: var(--gl-radius-control);
}
.search-field svg { flex: none; }
.search-field:focus-within { outline: 2px solid var(--gl-accent); outline-offset: 2px; }
.search-field input { flex: 1; min-width: 0; min-height: 44px; border: 0; background: transparent; color: var(--gl-ink); }
.search-field input:focus-visible { outline: none; }
.search-field input::placeholder { color: var(--gl-muted); }
.practice-filters, .practice-filters label { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; font-size: 13px; }
.practice-filters label { margin-inline-start: auto; color: var(--gl-muted); }
.segments { display: flex; gap: 2px; padding: 3px; border-radius: var(--gl-radius-control); background: var(--gl-secondary); }
.segments button { border-color: transparent; background: transparent; }
.segments button[aria-pressed="true"] { color: var(--gl-accent); background: var(--gl-surface); box-shadow: var(--gl-shadow-card); font-weight: 650; }
.practice-filters select { min-height: 44px; max-width: 100%; padding: 4px 8px; border: 1px solid var(--gl-line); border-radius: var(--gl-radius-control); color: var(--gl-ink); background: var(--gl-surface); }
.practice-filters .reset-filters { color: var(--gl-accent); }
.practice-list-title { display: flex; align-items: baseline; justify-content: space-between; flex-wrap: wrap; gap: 4px 12px; margin-bottom: 10px; color: var(--gl-muted); font-size: 13px; }
.practice-list-title h2 { margin: 0; color: var(--gl-ink); font-size: 18px; }
.practice-card, .practice-empty { border: 1px solid var(--gl-line); border-radius: var(--gl-radius-card); background: var(--gl-surface); box-shadow: var(--gl-shadow-card); }
.practice-empty { padding: 20px; color: var(--gl-muted); }
.practice-empty p { margin: 0; }
.practice-empty button { margin-top: 10px; }
.practice-card { padding: 22px 24px; margin-bottom: 14px; scroll-margin-top: 96px; overflow-wrap: anywhere; }
.practice-card-head, .practice-card-head > div { display: flex; align-items: center; gap: 6px 10px; }
.practice-card-head { justify-content: space-between; }
.practice-card-head > div { flex-wrap: wrap; min-width: 0; }
.q-num { color: var(--gl-ink); font-size: 20px; }
.type-badge { padding: 2px 8px; border-radius: var(--gl-radius-pill); background: var(--gl-accent-soft); color: var(--gl-accent); font-size: 12px; }
.module-tag, .practice-card-head code { color: var(--gl-muted); font-size: 12px; }
.practice-card-head .bookmark { display: grid; place-items: center; flex: none; width: 44px; padding: 0; color: var(--gl-muted); }
.bookmark[aria-pressed="true"] { background: var(--gl-accent-soft); color: var(--gl-accent); }
.bookmark[aria-pressed="true"] svg { fill: currentColor; }
.stem { margin: 8px 0 16px; font-size: 16px; line-height: 1.8; }
.options { display: grid; gap: 8px; min-width: 0; margin: 0; padding: 0; border: 0; }
.options .option { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; align-items: start; gap: 10px; width: 100%; padding: 10px 12px; text-align: start; font-size: 14px; line-height: 24px; }
.option b { display: grid; place-items: center; width: 24px; border-radius: 6px; background: var(--gl-secondary); color: var(--gl-ink); font-size: 12px; }
.option.selected { border-color: var(--gl-accent); background: var(--gl-accent-soft); }
.option.selected b { background: var(--gl-action); color: #fff; }
.option.correct { border-color: var(--practice-good); background: color-mix(in srgb, var(--practice-good) 9%, var(--gl-surface)); }
.option.incorrect { border-color: var(--practice-bad); background: color-mix(in srgb, var(--practice-bad) 9%, var(--gl-surface)); }
.option-mark { display: flex; align-items: center; min-height: 24px; }
.correct .option-mark { color: var(--practice-good); }
.incorrect .option-mark { color: var(--practice-bad); }
.option:disabled { opacity: 1; }
.practice-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 8px 12px; margin-top: 14px; font-size: 13px; }
.practice-actions .primary { border-color: transparent; background: var(--gl-action); color: #fff; font-weight: 650; }
.practice-actions .primary:not(:disabled):hover { background: var(--gl-action-hover); }
.good, .bad, .pending { font-weight: 650; }
.good { color: var(--practice-good); }
.bad { color: var(--practice-bad); }
.pending { color: var(--practice-warn); }
.explanation { border-top: 1px solid var(--gl-line); margin-top: 14px; }
:is(.explanation, .reading-tools) > summary { display: flex; align-items: center; justify-content: space-between; gap: 8px; min-height: 44px; color: var(--gl-ink); font-size: 14px; font-weight: 650; cursor: pointer; list-style: none; }
:is(.explanation, .reading-tools) > summary::-webkit-details-marker { display: none; }
:is(.explanation, .reading-tools)[open] > summary > svg { transform: rotate(180deg); }
.reading-tools > summary span { display: flex; align-items: center; gap: 8px; }
.explanation-body { padding: 12px 14px; border-radius: var(--gl-radius-control); background: var(--gl-page); }
.answer-line { margin: 0 0 8px; color: var(--gl-muted); font-size: 13px; }
.answer-line strong { margin-inline-start: 8px; color: var(--practice-good); }
.analysis, .evidence { font-size: 14px; line-height: 1.8; }
.analysis { margin: 0; }
.evidence { border-top: 1px solid var(--gl-line); margin-top: 10px; }
.evidence summary { align-content: center; min-height: 44px; cursor: pointer; }
.evidence p { margin: 6px 0; }
.source-links { display: flex; flex-wrap: wrap; gap: 8px; }
.source-links a { align-content: center; min-height: 44px; }
.practice-pagination { display: flex; align-items: center; justify-content: center; gap: 12px; margin-block: 20px; color: var(--gl-muted); }

@media (max-width: 820px) {
  .practice-workspace { grid-template-columns: minmax(0, 1fr); gap: 14px; }
  .practice-sidebar { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 8px 12px; }
  .practice-panel:first-child { grid-column: 1 / -1; }
  .practice-panel:first-child button { display: inline-flex; width: auto; max-width: 100%; margin: 2px 4px 2px 0; }
}
@media (max-width: 560px) {
  .practice-hero { display: block; padding-bottom: 12px; }
  .practice-hero h1 { font-size: 28px; }
  .hero-stat { margin-top: 10px; }
  .hero-stat b { font-size: 22px; }
  .hero-stat small { width: auto; }
  .practice-card { padding: 14px; }
  .practice-filters label { margin-inline-start: 0; }
}
@media print {
  .current-affairs, .current-affairs.night { color-scheme: light; margin: 0; color: #111; background: #fff; box-shadow: none; }
  .practice-sidebar, .practice-toolbar, .practice-notice, .practice-error, .practice-actions, .practice-pagination, .bookmark { display: none !important; }
  .practice-workspace { display: block; }
  .practice-card { break-inside: avoid; box-shadow: none; }
  .explanation-body { background: #fff; }
  .current-affairs:not(.print-answers) .explanation { display: none; }
  .hero-stat { display: none; }
}
</style>
