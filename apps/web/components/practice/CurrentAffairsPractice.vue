<script setup lang="ts">
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
        <section class="practice-panel"><h2>阅读与打印</h2><button type="button" @click="night = !night">{{ night ? '日间模式' : '夜读模式' }}</button><label class="print-choice"><input v-model="printAnswers" type="checkbox" />打印答案与解析</label><button type="button" @click="print">打印筛选结果</button></section>
      </aside>
      <div class="practice-content">
        <div class="practice-toolbar"><label class="search-field"><span class="sr-only">搜索题目</span><input v-model="filter.keyword" type="search" placeholder="搜索题干、选项或考点" /></label>
          <div class="practice-filters"><div class="segments"><button v-for="item in [{ key: 'all', label: '全部' }, { key: 'single', label: '单选' }, { key: 'multi', label: '多选' }]" :key="item.key" type="button" :aria-pressed="filter.type === item.key" @click="filter.type = item.key">{{ item.label }}</button></div>
            <label>练习状态 <select v-model="filter.status"><option value="all">全部</option><option value="todo">未做</option><option value="done">已做</option><option value="wrong">当前错题</option><option value="saved">我的收藏</option></select></label></div>
        </div>
        <div v-if="escaped" class="practice-notice" role="status">{{ escaped }} 已移出当前筛选。<button type="button" @click="revealEscaped">{{ escapedRetry ? '继续作答' : '查看本题解析' }}</button><button type="button" @click="escaped = ''">关闭</button></div>
        <div class="practice-list-title"><h2>{{ filter.module === 'all' ? '全部题目' : moduleLabels[filter.module] }}</h2><span>共 {{ pagination.total }} 题<span v-if="pagination.total && !printing"> · 显示 {{ (pagination.page - 1) * 6 + 1 }}—{{ Math.min(pagination.page * 6, pagination.total) }}</span></span></div>
        <div v-if="!visible.length" class="empty-state">没有符合筛选条件的题目。</div>
        <article v-for="(q, index) in visible" :id="`q-${q.questionRef}`" :key="q.questionRef" class="practice-card">
          <header class="practice-card-head"><div><strong class="q-num">{{ printing ? filtered.indexOf(q) + 1 : (pagination.page - 1) * 6 + index + 1 }}</strong><span class="type-badge">{{ q.type === 'single' ? '单选' : '多选' }}</span><span class="module-tag">{{ q.moduleLabel ?? moduleLabels[q.module] }}</span><code>{{ q.questionRef }}</code></div>
            <button type="button" class="bookmark" :aria-pressed="study.favorites.value.includes(q.questionRef)" :aria-label="`${study.favorites.value.includes(q.questionRef) ? '取消收藏' : '收藏'}${q.questionRef}`" @click="study.toggleFavorite(q)">{{ study.favorites.value.includes(q.questionRef) ? '★' : '☆' }}</button></header>
          <h3 class="sr-only">{{ q.questionRef }} {{ q.type === 'single' ? '单选' : '多选' }}</h3><p class="stem">{{ q.stem }}</p>
          <fieldset class="options"><legend class="sr-only">请选择{{ q.type === 'single' ? '一项' : '所有符合题意的项' }}</legend>
            <button v-for="(option, letter) in q.options" :key="letter" type="button" class="option" :class="{ selected: (questionResult(q)?.chosen ?? drafts[q.questionRef] ?? []).includes(String(letter)), correct: questionResult(q) && answerKeys(questionResult(q)).includes(String(letter)), incorrect: questionResult(q) && (questionResult(q)?.chosen ?? []).includes(String(letter)) && !answerKeys(questionResult(q)).includes(String(letter)) }" :disabled="!!questionResult(q) || pendingRefs.has(q.questionRef)" :aria-pressed="(questionResult(q)?.chosen ?? drafts[q.questionRef] ?? []).includes(String(letter))" @click="pick(q, String(letter))"><b>{{ letter }}</b><span>{{ option }}</span></button>
          </fieldset>
          <div class="practice-actions"><button v-if="!questionResult(q)" type="button" class="primary" :disabled="!(drafts[q.questionRef]?.length) || pendingRefs.has(q.questionRef)" @click="submit(q)">提交答案</button><button v-else type="button" @click="redo(q)">重新作答</button><span v-if="pendingRefs.has(q.questionRef)" class="pending">待同步 · 请重试保存</span><span v-if="questionResult(q)" :class="questionResult(q)?.isRight ? 'good' : 'bad'">{{ questionResult(q)?.isRight ? '回答正确' : '本题答错 · 请核对解析' }}</span></div>
          <details class="explanation" :open="openAnalysis[q.questionRef] || printing && printAnswers" @toggle="openAnalysis[q.questionRef] = ($event.target as HTMLDetailsElement).open"><summary>答案与解析 <span>⌄</span></summary>
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
.current-affairs{--p-blue:#285a9b;--p-deep:#1b457b;--p-soft:#eaf2fb;--p-paper:#fff;--p-bg:#f5f8fc;--p-ink:#1e3047;--p-muted:#64758a;--p-line:#d6e1ed;--p-good:#237b64;--p-bad:#b7464c;color:var(--p-ink);background:var(--p-bg);border:1px solid var(--p-line);border-radius:20px;padding:clamp(16px,3vw,36px);margin:16px 0 48px}
.current-affairs.night{--p-blue:#8ebcff;--p-deep:#b7d4ff;--p-soft:#203750;--p-paper:#17283c;--p-bg:#101e30;--p-ink:#edf4ff;--p-muted:#a7b8cd;--p-line:#344b65;--p-good:#83d5b9;--p-bad:#ff9fa2}
.current-affairs button,.current-affairs input,.current-affairs select{font:inherit}.current-affairs button{cursor:pointer;border:1px solid var(--p-line);border-radius:8px;padding:9px 13px;min-height:42px;color:var(--p-ink);background:var(--p-paper)}.current-affairs button:hover{border-color:var(--p-blue);background:var(--p-soft)}.current-affairs button:disabled{opacity:.48;cursor:default}.current-affairs :focus-visible{outline:3px solid var(--p-blue);outline-offset:2px}.current-affairs a{color:var(--p-blue)}.practice-hero{display:flex;justify-content:space-between;align-items:center;gap:30px;padding:28px 32px;margin-bottom:22px;background:linear-gradient(115deg,var(--p-paper),var(--p-soft));border:1px solid var(--p-line);border-radius:16px}.practice-hero .eyebrow{font-size:11px;letter-spacing:.12em;color:var(--p-blue);font-weight:700}.practice-hero h1{margin:8px 0;font-size:clamp(28px,4vw,44px);line-height:1.3}.practice-hero p{font-size:13px;color:var(--p-muted);margin:0}.hero-meta{display:flex;flex-wrap:wrap;gap:7px;margin-top:18px}.hero-meta span{font-size:11px;border:1px solid var(--p-line);padding:4px 8px;border-radius:5px}.hero-stat{min-width:185px;border-left:1px solid var(--p-line);padding-left:26px;display:flex;align-items:baseline;gap:8px;flex-wrap:wrap}.hero-stat b{font-size:58px;line-height:1;color:var(--p-blue)}.hero-stat span,.hero-stat small{font-size:12px;color:var(--p-muted)}.hero-stat small{width:100%}.practice-notice,.practice-error{padding:12px 16px;margin-bottom:16px;border:1px solid var(--p-line);border-radius:9px;background:var(--p-soft);font-size:13px}.practice-error{border-color:var(--p-bad);color:var(--p-bad)}.practice-notice button{margin-left:10px}.pending-list{margin:8px 0 0;padding-left:20px}.pending-list li{margin-top:6px}.practice-workspace{display:grid;grid-template-columns:210px minmax(0,1fr);gap:24px}.practice-sidebar{align-self:start;position:sticky;top:90px}.practice-panel{border:1px solid var(--p-line);background:var(--p-paper);border-radius:11px;padding:16px;margin-bottom:14px}.practice-panel h2{font-size:13px;margin:0 0 12px}.practice-panel>button{display:flex;width:100%;justify-content:space-between;text-align:left;border-color:transparent;background:transparent;font-size:12px;padding:7px 9px}.practice-panel>button[aria-pressed=true]{color:var(--p-blue);background:var(--p-soft);font-weight:700}.practice-panel button span,.practice-panel small{color:var(--p-muted)}.practice-panel p{margin:0;font-size:13px}.progress-track{height:5px;margin:12px 0;border-radius:5px;background:var(--p-line);overflow:hidden}.progress-track span{display:block;height:100%;background:var(--p-good)}.print-choice{display:flex;gap:7px;font-size:11px;align-items:center;margin:8px 0}.practice-content{min-width:0}.practice-toolbar{display:grid;gap:12px;margin-bottom:20px}.search-field{display:block;background:var(--p-paper);border:1px solid var(--p-line);border-radius:9px;padding:0 12px}.search-field input{min-height:44px;width:100%;border:0;outline:0;background:transparent;color:var(--p-ink)}.practice-filters{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;font-size:12px}.segments{display:flex;gap:5px}.segments button[aria-pressed=true]{color:var(--p-blue);background:var(--p-soft);border-color:var(--p-blue)}.practice-filters select{margin-left:7px;min-height:40px;padding:5px 8px;background:var(--p-paper);border:1px solid var(--p-line);border-radius:7px;color:var(--p-ink)}.practice-list-title{display:flex;align-items:baseline;justify-content:space-between;gap:10px;margin:0 0 12px;font-size:12px;color:var(--p-muted)}.practice-list-title h2{font-size:16px;color:var(--p-ink);margin:0}.practice-card{background:var(--p-paper);border:1px solid var(--p-line);border-radius:12px;padding:24px 26px;margin:0 0 18px;scroll-margin-top:95px;overflow-wrap:anywhere}.practice-card-head,.practice-card-head>div{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.practice-card-head{justify-content:space-between}.q-num{font-size:20px;color:var(--p-blue)}.type-badge{font-size:11px;color:var(--p-blue);background:var(--p-soft);padding:2px 7px;border-radius:5px}.module-tag,.practice-card-head code{font-size:11px;color:var(--p-muted)}.practice-card-head .bookmark{border:0;font-size:26px;color:var(--p-blue);line-height:1}.stem{font-size:16px;line-height:1.95;margin:16px 0 20px}.options{border:0;padding:0;margin:0;display:grid;gap:9px;min-width:0}.options .option{display:flex;text-align:left;gap:13px;align-items:flex-start;width:100%;line-height:1.7;font-size:14px;padding:11px 14px}.option b{color:var(--p-muted);min-width:18px}.option span{min-width:0}.option.selected{color:var(--p-blue);background:var(--p-soft);border-color:var(--p-blue)}.option.correct{border-color:var(--p-good);background:color-mix(in srgb,var(--p-good) 13%,var(--p-paper));color:var(--p-ink)}.option.incorrect{border-color:var(--p-bad);background:color-mix(in srgb,var(--p-bad) 12%,var(--p-paper));color:var(--p-ink)}.option:disabled{opacity:1}.practice-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:18px;font-size:12px}.practice-actions .primary{background:var(--p-blue);border-color:var(--p-blue);color:#fff}.good{color:var(--p-good)}.bad,.pending{color:var(--p-bad)}.explanation{border-top:1px solid var(--p-line);margin-top:20px}.explanation>summary{cursor:pointer;display:flex;justify-content:space-between;align-items:center;padding-top:13px;min-height:44px;color:var(--p-blue);font-size:12px}.explanation-body{background:var(--p-soft);padding:17px 20px;margin-top:12px;border-radius:8px}.answer-line{font-size:12px;color:var(--p-muted);margin:0 0 10px}.answer-line strong{font-size:17px;color:var(--p-good);margin-left:10px}.analysis,.evidence{font-size:13px;line-height:1.9}.analysis{margin:0}.evidence{border-top:1px solid var(--p-line);margin-top:14px;padding-top:10px}.evidence p{margin:7px 0}.source-links{display:flex;gap:10px;flex-wrap:wrap}.practice-pagination{display:flex;align-items:center;justify-content:center;gap:14px;margin:24px 0}.empty-state{text-align:center;padding:38px;color:var(--p-muted)}
@media(max-width:820px){.practice-workspace{grid-template-columns:1fr}.practice-sidebar{position:static;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.practice-panel{margin:0}.practice-panel:first-child{grid-column:1/-1}.practice-panel:first-child button{display:inline-flex;width:auto;margin:2px 4px 2px 0}.practice-hero{padding:24px}.hero-stat{min-width:120px}}
@media(max-width:560px){.current-affairs{padding:13px}.practice-hero{display:block;padding:20px}.hero-stat{border-left:0;border-top:1px solid var(--p-line);margin-top:18px;padding:14px 0 0}.practice-sidebar{display:block}.practice-panel{margin-bottom:10px}.practice-card{padding:18px 15px}.practice-filters{align-items:flex-start}.practice-list-title{display:block}}
@media print{.current-affairs{border:0;padding:0;background:#fff;color:#111}.practice-sidebar,.practice-toolbar,.practice-notice,.practice-error,.practice-actions,.practice-pagination,.bookmark{display:none!important}.practice-workspace{display:block}.practice-card{break-inside:avoid;box-shadow:none}.explanation-body{background:#fff}.current-affairs:not(.print-answers) .explanation{display:none}.practice-hero{margin:0 0 16px;padding:12px;border:0}.hero-stat{display:none}}
</style>
