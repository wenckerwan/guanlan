<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import type { AdminQuestion, Paper } from '~/types/api'
const props = defineProps<{ paper: Paper; questionId: number | null; initialNo: number; refresh: () => Promise<void> }>()
const emit = defineEmits<{ dirty: [value: boolean]; busy: [value: boolean]; close: []; saved: [message: string] }>()
const { request } = useAuth()
const TYPES = [ ['single','单项选择题'], ['multi','多项选择题'], ['analyse','分析题'], ['discern','辨析题'], ['essay','论述题'], ['material','材料题'], ['simple','简答题'] ]
const emptyDraft = () => ({ no: props.initialNo, type: 'single', typeCn: '单项选择题', stem: '', material: '', optionsText: '', answer: '', answerText: '', analysis: '', module: '', moduleName: '', kaodian: '', score: 2, sortOrder: props.initialNo })
const draft = reactive(emptyDraft())
const baseline = ref(JSON.stringify(draft))
const detail = ref<AdminQuestion | null>(null), latest = ref<AdminQuestion | null>(null)
const loading = ref(false), loadError = ref(''), error = ref(''), conflict = ref(false), saving = ref(false), latestLoading = ref(false), latestError = ref('')
const dirty = computed(() => JSON.stringify(draft) !== baseline.value)
let sequence = 0, alive = true
watch(dirty, value => emit('dirty', value), { immediate: true })
watch(saving, value => emit('busy', value), { flush: 'sync' })
function optionsText(options: AdminQuestion['options']) { return Object.entries(options ?? {}).map(([key,value]) => `${key}=${value}`).join('\n') }
function adopt(data: AdminQuestion) {
  detail.value = data
  Object.assign(draft, { no: data.no, type: data.type, typeCn: data.typeCn, stem: data.stem ?? '', material: data.material ?? '', optionsText: optionsText(data.options), answer: data.answer ?? '', answerText: data.answerText ?? '', analysis: data.analysis ?? '', module: data.module ?? '', moduleName: data.moduleName ?? '', kaodian: data.kaodian ?? '', score: data.score, sortOrder: data.sortOrder })
  baseline.value = JSON.stringify(draft); conflict.value = false; error.value = ''; latest.value = null; latestError.value = ''
}
function validDetail(data: AdminQuestion) {
  if (!data || data.id !== props.questionId || data.pid !== props.paper.pid || !Number.isInteger(data.revision) || data.revision < 1) throw new Error('题目详情无效')
}
async function load() {
  if (props.questionId === null || saving.value) return
  const current = ++sequence; loading.value = true; loadError.value = ''
  try { const data = await request<AdminQuestion>(`/admin/questions/${props.questionId}`); if (!alive || current !== sequence) return; validDetail(data); adopt(data) }
  catch { if (alive && current === sequence) loadError.value = '题目详情加载失败，请重试。' }
  finally { if (alive && current === sequence) loading.value = false }
}
async function readLatest() {
  if (props.questionId === null || saving.value || latestLoading.value) return
  const current = ++sequence; latestLoading.value = true; latestError.value = ''; latest.value = null
  try { const data = await request<AdminQuestion>(`/admin/questions/${props.questionId}`); if (!alive || current !== sequence) return; validDetail(data); latest.value = data }
  catch { if (alive && current === sequence) latestError.value = '最新版本读取失败，请重试。' }
  finally { if (alive && current === sequence) latestLoading.value = false }
}
function adoptLatest() {
  if (!latest.value || saving.value) return
  if (dirty.value && !window.confirm('采用最新版本会替换当前未保存草稿，确定继续？')) return
  adopt(latest.value)
}
function typeChanged() { if (TYPES.some(type => type[1] === draft.typeCn)) draft.typeCn = TYPES.find(type => type[0] === draft.type)?.[1] ?? draft.typeCn }
function parseOptions(text: string): Record<string, string> {
  const result: Record<string, string> = {}
  if (!text.trim()) return result
  for (const [index, line] of text.trim().split(/\r?\n/).entries()) {
    const match = line.trim().match(/^([A-H])=(.*)$/)
    if (!match || !match[2].trim()) throw new Error(`第 ${index + 1} 行选项格式错误；请使用 A-H=非空内容。`)
    if (Object.hasOwn(result, match[1])) throw new Error(`选项字母 ${match[1]} 重复。`)
    result[match[1]] = match[2].trim()
  }
  return result
}
function payload() {
  const original = JSON.parse(baseline.value) as typeof draft
  const creating = props.questionId === null
  const changed = (key: keyof typeof draft) => creating || draft[key] !== original[key]
  const body: Record<string, unknown> = {}
  const byteLength = (value: string) => new TextEncoder().encode(value).length
  const limits = { typeCn: 32, module: 16, moduleName: 64, kaodian: 191, answer: 16 }
  for (const [key, max] of Object.entries(limits)) {
    const field = key as keyof typeof limits
    if (changed(field) && [...draft[field]].length > max) throw new Error(`${key} 不能超过 ${max} 个字符。`)
  }
  for (const field of ['stem','material','answerText'] as const) if (changed(field) && byteLength(draft[field]) > 65535) throw new Error(`${field} 内容过长。`)
  if (changed('analysis') && byteLength(draft.analysis) > 1048576) throw new Error('解析不能超过 1 MiB。')
  if (changed('stem') && !draft.stem.trim()) throw new Error('题干不能为空。')
  if (creating && (!Number.isInteger(draft.no) || draft.no < 1 || draft.no > 65535)) throw new Error('题号必须是 1–65535 的整数。')
  if (changed('sortOrder') && (!Number.isInteger(draft.sortOrder) || draft.sortOrder < -2147483648 || draft.sortOrder > 2147483647)) throw new Error('排序必须是 32 位有符号整数。')
  if (changed('score') && (!Number.isFinite(draft.score) || draft.score < 0 || draft.score > 9999.9 || Math.abs(draft.score * 10 - Math.round(draft.score * 10)) > 0.000001)) throw new Error('分值须在 0–9999.9 之间，最多一位小数。')
  const semanticChange = creating || (['type','optionsText','answer','answerText','analysis'] as const).some(changed)
  let options: Record<string, string> | undefined
  if (semanticChange) {
    if (!TYPES.some(type => type[0] === draft.type)) throw new Error('请选择有效题型。')
    options = parseOptions(draft.optionsText)
    if (['single','multi'].includes(draft.type)) {
      if (Object.keys(options).length < 2) throw new Error('客观题至少需要两个 A-H 选项。')
      if (!/^[A-H]+$/.test(draft.answer) || new Set(draft.answer).size !== draft.answer.length || [...draft.answer].some(key => !Object.hasOwn(options!, key))) throw new Error('参考答案须使用已存在且不重复的选项字母。')
      if (draft.type === 'single' && draft.answer.length !== 1) throw new Error('单选题须有一个正确字母。')
      if (draft.type === 'multi' && draft.answer.length < 2) throw new Error('多选题至少有两个正确字母。')
    } else {
      if (Object.keys(options).length) throw new Error('主观题须清空选项。')
      if (!draft.answerText.trim() && !draft.analysis.trim()) throw new Error('主观题须填写答案要点或解析。')
    }
  }
  for (const key of ['type','typeCn','stem','material','answer','answerText','analysis','module','moduleName','kaodian','score','sortOrder'] as const) if (changed(key)) body[key] = draft[key]
  if (changed('optionsText')) body.options = options ?? parseOptions(draft.optionsText)
  if (creating) body.no = draft.no
  else body.expectedRevision = detail.value!.revision
  return body
}
async function save() {
  if (saving.value || loading.value || loadError.value || latestLoading.value || (props.questionId !== null && !dirty.value)) return
  error.value = ''; latestError.value = ''
  let body: Record<string, unknown>
  try { body = payload() } catch (exception) { error.value = (exception as Error).message; return }
  saving.value = true; const current = ++sequence
  try {
    await request<AdminQuestion>(props.questionId === null ? `/admin/papers/${encodeURIComponent(props.paper.pid)}/questions` : `/admin/questions/${props.questionId}`, { method: props.questionId === null ? 'POST' : 'PATCH', body })
    if (!alive || current !== sequence) return
    baseline.value = JSON.stringify(draft)
    await props.refresh()
    if (!alive || current !== sequence) return
    emit('saved', props.questionId === null ? '题目已创建' : `第 ${detail.value!.no} 题已更新`)
  } catch (exception) {
    if (!alive || current !== sequence) return
    const failure = exception as { status?: number; statusCode?: number; data?: { message?: string } }
    conflict.value = (failure.status ?? failure.statusCode) === 409 && props.questionId !== null
    error.value = failure.data?.message || (conflict.value ? '题目版本已变化，请读取最新版本；当前草稿已保留。' : '题目保存失败，请重试。')
  } finally { if (alive && current === sequence) saving.value = false }
}
onMounted(load)
onBeforeUnmount(() => { alive = false; ++sequence })
</script>

<template>
  <section class="question-editor" data-testid="question-editor" :aria-busy="saving || loading">
    <p v-if="loading" role="status">正在读取题目详情…</p>
    <div v-else-if="loadError" role="alert"><p>{{ loadError }}</p><button type="button" class="ghost-button" @click="load">重试详情</button></div>
    <template v-else>
      <p class="admin-meta">{{ questionId === null ? '新增题目' : '编辑题目' }} · {{ paper.pid }} · {{ paper.year }} {{ paper.label }}</p>
      <p v-if="detail" class="admin-meta">修订版本：{{ detail.revision }}</p>
      <div v-if="error" role="alert" class="empty-state">{{ error }}</div>
      <div v-if="conflict" class="conflict-panel">
        <p>草稿保留在表单中。读取最新版本后，需明确采用才会替换草稿。</p>
        <button class="ghost-button" type="button" :disabled="saving || latestLoading" @click="readLatest">读取最新版本</button>
        <p v-if="latestLoading" role="status">正在读取最新版本…</p>
        <p v-if="latestError" role="alert">{{ latestError }}</p>
        <template v-if="latest"><p>最新修订版本：{{ latest.revision }}</p><p class="latest-stem">最新题干：{{ latest.stem }}</p><button class="ghost-button" type="button" :disabled="saving" @click="adoptLatest">采用最新版本</button></template>
      </div>
      <form class="admin-form" @submit.prevent="save">
        <label><span>题号（编辑时不可修改）</span><input v-model.number="draft.no" aria-label="题号" type="number" min="1" max="65535" required :disabled="saving || questionId !== null" /></label>
        <label><span>题型</span><select v-model="draft.type" aria-label="题型" :disabled="saving" @change="typeChanged"><option v-for="type in TYPES" :key="type[0]" :value="type[0]">{{ type[1] }}</option></select></label>
        <label><span>题型名称</span><input v-model="draft.typeCn" aria-label="题型名称" maxlength="32" :disabled="saving" /></label>
        <label><span>分值</span><input v-model.number="draft.score" aria-label="题目分值" type="number" min="0" max="9999.9" step="0.1" required :disabled="saving" /></label>
        <label><span>排序（不改变题号）</span><input v-model.number="draft.sortOrder" aria-label="题目排序" type="number" min="-2147483648" max="2147483647" required :disabled="saving" /></label>
        <label><span>模块代号</span><input v-model="draft.module" aria-label="模块代号" maxlength="16" :disabled="saving" /></label>
        <label><span>模块名称</span><input v-model="draft.moduleName" aria-label="模块名称" maxlength="64" :disabled="saving" /></label>
        <label><span>考点</span><input v-model="draft.kaodian" aria-label="考点" maxlength="191" :disabled="saving" /></label>
        <label class="wide"><span>题干</span><textarea v-model="draft.stem" aria-label="题干" rows="4" :disabled="saving" /></label>
        <label class="wide"><span>材料</span><textarea v-model="draft.material" aria-label="材料" rows="3" :disabled="saving" /></label>
        <label class="wide"><span>选项（每行 A-H=内容，主观题留空）</span><textarea v-model="draft.optionsText" aria-label="选项字母与内容" rows="5" :disabled="saving" /></label>
        <label><span>参考答案（客观题字母）</span><input v-model="draft.answer" aria-label="参考答案" maxlength="16" :disabled="saving" /></label>
        <label class="wide"><span>主观题答案要点</span><textarea v-model="draft.answerText" aria-label="主观题答案要点" rows="4" :disabled="saving" /></label>
        <label class="wide"><span>答案解析</span><textarea v-model="draft.analysis" aria-label="答案解析" rows="6" :disabled="saving" /></label>
        <button class="primary-button" type="submit" :disabled="saving || latestLoading || (questionId !== null && !dirty)">{{ saving ? '保存中…' : '保存题目' }}</button>
      </form>
    </template>
    <button class="ghost-button" type="button" :disabled="saving" @click="emit('close')">取消题目编辑</button>
  </section>
</template>
<style scoped>
.question-editor { margin: 1rem 0; min-width: 0; }
.conflict-panel { border: 1px solid var(--line); padding: 12px; margin-bottom: 12px; overflow-wrap: anywhere; }
.latest-stem { white-space: pre-wrap; }
@media (max-width: 640px) { .admin-form { grid-template-columns: minmax(0, 1fr); } .admin-form label { min-width: 0; } input, select, textarea { min-width: 0; width: 100%; max-width: 100%; } }
</style>
