<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'

type Entry = { revision: number; title: string; status: string; format: string; contentSource: string; wordCount: number; adminId: number | null; createdAt: string }
type Version = { revision: number; createdAt: string; adminId: number | null; article: Record<string, unknown> }
type Diff = { revision: number; sourceStatus: 'same' | 'different' | 'database-only'; maintained: boolean; fields: { field: string; databaseValue: string; sourceValue: string | null; equal: boolean }[] }
type Overview = { items: { slug: string; title: string; articleId: number | null; sourceStatus: string }[]; total: number; page: number; perPage: number; counts: { same: number; different: number; databaseOnly: number; sourceOnly: number } }
const props = defineProps<{ kind: 'hotspots' | 'analysis'; articleId: number; revision: number; busy: boolean; confirmDiscard: () => boolean; validateDetail: (value: unknown) => boolean }>()
const emit = defineEmits<{ applied: [detail: unknown]; conflict: []; restoring: [value: boolean]; adoptSource: [fields: Record<string, string>] }>()
const { request } = useAuth()
const endpoint = computed(() => `/admin/articles/${props.kind === 'hotspots' ? 'hotspot' : 'analysis'}/${props.articleId}`)
const opened = ref(false), loading = ref(false), working = ref(false), restoring = ref(false)
const entries = ref<Entry[]>([]), total = ref(0), page = ref(1), perPage = 10
const selected = ref<Version | null>(null), diff = ref<Diff | null>(null), error = ref(''), feedback = ref('')
const overview = ref<Overview | null>(null), sourceFields = ref<string[]>([])
const blocked = computed(() => props.busy || working.value || loading.value || restoring.value)
const fields = ['title', 'summary', 'body', 'html', 'format', 'period', 'priority', 'level', 'type', 'tag', 'category']
const detailFields = [...fields, 'id', 'slug', 'status', 'commentMode', 'contentSource', 'wordCount', 'outline']
const labels: Record<string, string> = { title: '标题', summary: '摘要', body: '正文', html: '正文 HTML', format: '格式', period: '期次', priority: '优先级', level: '等级', type: '类型', tag: '标签', category: '分类', status: '发布状态', contentSource: '内容来源', wordCount: '字数' }
const diffLabel = computed(() => diff.value ? ({ same: '数据库与来源一致', different: '数据库与来源存在差异', 'database-only': '仅数据库内容（无匹配来源）' })[diff.value.sourceStatus] : '')
const mergeFields = ['title', 'summary', 'period', 'category', 'html']
function adopt() {
  if (blocked.value || !diff.value) return
  const selectedFields: Record<string, string> = {}
  for (const field of diff.value.fields) if (mergeFields.includes(field.field) && sourceFields.value.includes(field.field) && typeof field.sourceValue === 'string') selectedFields[field.field] = field.sourceValue
  if (Object.keys(selectedFields).length) emit('adoptSource', selectedFields)
}
async function loadOverview(targetPage = 1) {
  if (blocked.value) return
  const active = session, epoch = readEpoch
  working.value = true; error.value = ''
  try {
    const result = await request<Overview>(`${endpoint.value.replace(/\/\d+$/, '')}/source-diff?page=${targetPage}&perPage=${perPage}`)
    if (active !== session || epoch !== readEpoch) return
    if (!Array.isArray(result?.items) || !result.counts || !Number.isInteger(result.total) || !Number.isInteger(result.page)) throw new Error('invalid response')
    overview.value = result
  } catch (exception) { if (active === session && epoch === readEpoch) error.value = message(exception, '数据集对账总览读取失败，请重试。') }
  finally { if (active === session && epoch === readEpoch) working.value = false; flushReload() }
}
let session = 0, detailSequence = 0, readEpoch = 0, reloadPending = false
function flushReload() {
  if (!reloadPending || !opened.value || blocked.value) return
  reloadPending = false
  void load(1)
}
function message(exception: unknown, fallback: string) { return (exception as { data?: { message?: string } })?.data?.message || fallback }
async function load(targetPage = page.value) {
  if (blocked.value) return
  const active = session, epoch = readEpoch
  loading.value = true; error.value = ''
  try {
    const result = await request<{ items: Entry[]; total: number; page: number; perPage: number }>(`${endpoint.value}/revisions?page=${targetPage}&perPage=${perPage}`)
    if (active !== session || epoch !== readEpoch) return
    if (!Array.isArray(result?.items) || !Number.isInteger(result.total) || !Number.isInteger(result.page) || result.page < 1) throw new Error('invalid response')
    entries.value = result.items; total.value = result.total; page.value = result.page
  } catch (exception) { if (active === session && epoch === readEpoch) error.value = message(exception, '修订历史读取失败，请重试。') }
  finally { if (active === session && epoch === readEpoch) loading.value = false; flushReload() }
}
function toggle() { opened.value = !opened.value; if (opened.value) void load() }
async function view(revision: number) {
  if (blocked.value) return
  const active = session, epoch = readEpoch, sequence = ++detailSequence
  working.value = true; error.value = ''; feedback.value = ''
  try {
    const result = await request<Version>(`${endpoint.value}/revisions/${revision}`)
    if (active !== session || epoch !== readEpoch || sequence !== detailSequence) return
    if (result?.revision !== revision || !result.article || typeof result.article.body !== 'string') throw new Error('invalid response')
    selected.value = result
  } catch (exception) { if (active === session && epoch === readEpoch && sequence === detailSequence) error.value = message(exception, '历史版本读取失败，请重试。') }
  finally { if (active === session && epoch === readEpoch && sequence === detailSequence) working.value = false; flushReload() }
}
async function restore() {
  if (blocked.value || !selected.value || !props.confirmDiscard()) return
  if (!window.confirm(`恢复修订 ${selected.value.revision} 的正文与元数据为新修订，保持当前发布/评论状态。确认恢复？`)) return
  const active = session
  let applied = false
  restoring.value = true; emit('restoring', true); error.value = ''; feedback.value = ''
  try {
    const result = await request<unknown>(`${endpoint.value}/restore`, { method: 'POST', body: { revision: selected.value.revision, expectedRevision: props.revision } })
    if (active !== session) return
    if (!props.validateDetail(result)) throw new Error('invalid response')
    emit('applied', result); selected.value = null; diff.value = null; applied = true
    feedback.value = '已恢复为新修订，当前发布/评论状态保持不变。'
  } catch (exception) {
    if (active !== session) return
    const status = exception as { status?: number; statusCode?: number; response?: { status?: number } }
    if ([status.status, status.statusCode, status.response?.status].includes(409)) { emit('conflict'); error.value = '文章已有新修订，恢复未执行；当前编辑输入已保留，请读取最新版本处理冲突。' }
    else error.value = message(exception, '恢复失败或响应格式无效，当前输入已保留。')
  } finally { if (active === session) { restoring.value = false; emit('restoring', false); if (applied) { reloadPending = opened.value; flushReload() } } }
}
async function sourceDiff() {
  if (blocked.value) return
  const active = session, epoch = readEpoch
  working.value = true; error.value = ''
  try {
    const result = await request<Diff>(`${endpoint.value}/source-diff`)
    if (active !== session || epoch !== readEpoch) return
    if (!result || !['same', 'different', 'database-only'].includes(result.sourceStatus) || !Array.isArray(result.fields) || typeof result.maintained !== 'boolean') throw new Error('invalid response')
    diff.value = { ...result, fields: result.fields.filter(item => fields.includes(item.field)) }; sourceFields.value = []
  } catch (exception) { if (active === session && epoch === readEpoch) error.value = message(exception, '来源差异读取失败，请重试。') }
  finally { if (active === session && epoch === readEpoch) working.value = false; flushReload() }
}
async function download(revision?: number) {
  if (blocked.value) return
  const active = session, epoch = readEpoch
  working.value = true; error.value = ''
  try {
    const result = await request<{ format: string; body: string; title: string; revision: number; fileName: string }>(`${endpoint.value}/export${revision === undefined ? '' : `?revision=${revision}`}`)
    if (active !== session || epoch !== readEpoch) return
    if (!result || !['markdown', 'html'].includes(result.format) || typeof result.body !== 'string') throw new Error('invalid response')
    const extension = result.format === 'markdown' ? '.md' : '.html'
    const name = (String(result.fileName || result.title || 'article').replace(/[\x00-\x1f\x7f<>:"/\\|?*]/g, '_').replace(/[. ]+$/g, '').replace(/\.(md|html?)$/i, '').slice(0, 100) || 'article')
    const safeName = /^(con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i.test(name) ? `article-${name}` : name
    const url = URL.createObjectURL(new Blob([result.body], { type: result.format === 'markdown' ? 'text/markdown;charset=utf-8' : 'text/html;charset=utf-8' }))
    const anchor = document.createElement('a'); anchor.href = url; anchor.download = safeName + extension; anchor.click()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
    feedback.value = `已导出修订 ${result.revision} 的可编辑原文。`
  } catch (exception) { if (active === session && epoch === readEpoch) error.value = message(exception, '导出失败，请重试。') }
  finally { if (active === session && epoch === readEpoch) working.value = false; flushReload() }
}
watch(() => [props.kind, props.articleId], () => {
  ++session; ++detailSequence; ++readEpoch; reloadPending = false; opened.value = false; loading.value = false; working.value = false; restoring.value = false
  entries.value = []; total.value = 0; page.value = 1; selected.value = null; diff.value = null; error.value = ''; feedback.value = ''
  overview.value = null; sourceFields.value = []
})
watch(() => props.revision, () => {
  ++readEpoch; ++detailSequence
  // Invalidate reads without invalidating our own restore mutation completion.
  loading.value = false; working.value = false
  diff.value = null; overview.value = null; sourceFields.value = []
  reloadPending = opened.value
  flushReload()
})
watch(() => props.busy, () => flushReload())
onBeforeUnmount(() => { ++session; ++detailSequence })
</script>

<template>
  <section class="article-history" aria-label="文章修订与来源">
    <div class="history-actions"><button class="ghost-button" type="button" :disabled="blocked" @click="toggle">{{ opened ? '收起修订历史' : '查看修订历史' }}</button><button class="ghost-button" type="button" :disabled="blocked" @click="sourceDiff">读取来源差异</button><button class="ghost-button" type="button" :disabled="blocked" @click="download()">导出当前已保存原文</button></div>
    <button class="ghost-button" type="button" :disabled="blocked" @click="loadOverview()">查看数据集对账总览</button>
    <p class="admin-meta">来源差异供人工对账；可将所选来源字段放入草稿再保存。管理员维护标记与导入保护继续保留。导出使用已保存版本。</p>
    <p v-if="loading || working || restoring" role="status">{{ restoring ? '正在恢复修订…' : '正在读取…' }}</p><p v-if="error" role="alert">{{ error }}</p><p v-if="feedback" role="status">{{ feedback }}</p>
    <div v-if="opened"><h4>修订历史</h4><button class="ghost-button" type="button" :disabled="blocked" @click="load()">刷新修订历史</button><p v-if="!loading && !entries.length">暂无历史修订。</p><ul class="history-list"><li v-for="entry in entries" :key="entry.revision"><button class="ghost-button" type="button" :disabled="blocked" @click="view(entry.revision)">查看修订 {{ entry.revision }}</button><span>{{ entry.title }} · {{ entry.status }} · {{ entry.format }} · {{ entry.wordCount }} 字 · 来源 {{ entry.contentSource }} · 管理员 {{ entry.adminId ?? '未知' }} · {{ entry.createdAt }}</span></li></ul><div class="history-actions"><button class="ghost-button" type="button" :disabled="blocked || page <= 1" @click="load(page - 1)">历史上一页</button><span>第 {{ page }} 页 · 共 {{ total }} 条</span><button class="ghost-button" type="button" :disabled="blocked || page * perPage >= total" @click="load(page + 1)">历史下一页</button></div></div>
    <div v-if="selected" class="history-detail"><h4>只读修订 {{ selected.revision }}</h4><p class="admin-meta">管理员 {{ selected.adminId ?? '未知' }} · {{ selected.createdAt }}</p><dl><template v-for="field in detailFields" :key="field"><template v-if="selected.article[field] !== undefined"><dt>{{ labels[field] || field }}</dt><dd><pre>{{ selected.article[field] }}</pre></dd></template></template></dl><div class="history-actions"><button class="ghost-button" type="button" :disabled="blocked" @click="download(selected.revision)">导出所选修订原文</button><button class="primary-button" type="button" :disabled="blocked || selected.revision === revision" @click="restore">恢复所选修订</button></div></div>
    <div v-if="diff" class="source-diff"><h4>来源对账 · {{ diffLabel }}</h4><p>修订 {{ diff.revision }} · 管理员维护标记：{{ diff.maintained ? '已启用（导入保护保留）' : '未启用' }}</p><div v-for="item in diff.fields" :key="item.field" class="diff-field"><h5>{{ labels[item.field] || item.field }} · {{ item.equal ? '一致' : '不同' }}</h5><label v-if="mergeFields.includes(item.field) && typeof item.sourceValue === 'string'"><input v-model="sourceFields" type="checkbox" :value="item.field" :disabled="blocked" :aria-label="`采用来源字段 ${item.field}`" /> 选择来源字段</label><div class="diff-values"><div><strong>数据库</strong><pre>{{ item.databaseValue }}</pre></div><div><strong>来源文件</strong><pre>{{ item.sourceValue === null ? '无来源值' : item.sourceValue }}</pre></div></div></div><button class="ghost-button" type="button" :disabled="blocked || !sourceFields.length" @click="adopt">将所选源字段放入草稿</button></div>
    <div v-if="overview" class="source-diff"><h4>数据集对账总览</h4><p>一致 {{ overview.counts.same }} · 差异 {{ overview.counts.different }} · 仅数据库 {{ overview.counts.databaseOnly }} · 仅来源 {{ overview.counts.sourceOnly }}</p><ul><li v-for="item in overview.items" :key="item.slug">{{ item.title }} · {{ item.slug }} · {{ ({ same: '一致', different: '差异', 'database-only': '仅数据库', 'source-only': '仅来源（数据库缺失）' } as Record<string, string>)[item.sourceStatus] || item.sourceStatus }}</li></ul><div class="history-actions"><button class="ghost-button" type="button" :disabled="blocked || overview.page <= 1" @click="loadOverview(overview.page - 1)">对账上一页</button><span>第 {{ overview.page }} 页 · 共 {{ overview.total }} 条</span><button class="ghost-button" type="button" :disabled="blocked || overview.page * overview.perPage >= overview.total" @click="loadOverview(overview.page + 1)">对账下一页</button></div></div>
  </section>
</template>

<style scoped>
.article-history { margin-top: 20px; border-top: 1px solid var(--border); padding-top: 16px; min-width: 0; }
.history-actions, .history-list li { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
.history-list { padding: 0; list-style: none; } .history-list li { padding: 10px 0; border-bottom: 1px solid var(--border); }
.history-detail, .source-diff { margin-top: 20px; } dd { margin: 4px 0 14px; }
pre { white-space: pre-wrap; overflow-wrap: anywhere; max-height: 360px; overflow: auto; padding: 12px; background: var(--surface-muted, rgba(127,127,127,.08)); border-radius: 8px; }
.diff-values { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
@media (max-width: 640px) { .diff-values { grid-template-columns: 1fr; } }
</style>
