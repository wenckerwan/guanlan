<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import sanitizeHtml from 'sanitize-html'

type Format = 'html' | 'markdown'
type Rendered = { html: string; outline: unknown[]; wordCount: number }
type Detail = Rendered & { id: number; title: string; summary: string; format: Format; body: string; revision: number; contentSource: string; [key: string]: unknown }
const props = defineProps<{ kind: 'hotspots' | 'analysis'; articleId: number | null }>()
const emit = defineEmits<{ close: []; saved: [] }>()
const { request } = useAuth()
const form = reactive({ title: '', summary: '', status: 'published', period: '', priority: 'A', level: 'S', type: '形势与政策', tag: '', category: '选择题规律', format: 'markdown' as Format })
const buffers = reactive({ html: '', markdown: '' })
const body = computed({ get: () => buffers[form.format], set: value => { buffers[form.format] = value } })
const revision = ref(0), contentSource = ref('admin')
const loading = ref(false), saving = ref(false), previewing = ref(false)
const detailReady = ref(false)
const error = ref(''), feedback = ref(''), conflict = ref(false)
const rendered = ref<Rendered | null>(null), latest = ref<Detail | null>(null)
const baseline = ref('')
const persistedContent = ref<{ format: Format; body: string } | null>(null)
let session = 0, previewSequence = 0
const snapshot = () => JSON.stringify({ ...form, ...buffers })
const dirty = computed(() => baseline.value !== '' && baseline.value !== snapshot())
const safeHtml = computed(() => sanitizeHtml(rendered.value?.html || '', {
  allowedTags: sanitizeHtml.defaults.allowedTags.concat(['h1', 'h2', 'img']),
  allowedAttributes: { ...sanitizeHtml.defaults.allowedAttributes, '*': ['id'], img: ['src', 'alt'] },
  allowedSchemes: ['http', 'https', 'mailto'], allowProtocolRelative: false,
}))
const previewDocument = computed(() => `<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src https: http:; style-src 'unsafe-inline'"><style>body{background:#fff;color:#202124;font:16px/1.8 system-ui,sans-serif;padding:20px;overflow-wrap:anywhere}img{max-width:100%}pre{white-space:pre-wrap}table{border-collapse:collapse}td,th{border:1px solid #ddd;padding:8px}</style></head><body>${safeHtml.value}</body></html>`)

function validRendered(value: unknown): value is Rendered {
  const data = value as Rendered | null
  return !!data && typeof data.html === 'string' && Array.isArray(data.outline) && Number.isInteger(data.wordCount) && data.wordCount >= 0
}
function validDetail(value: unknown): value is Detail {
  const data = value as Detail | null
  return validRendered(data) && typeof data!.title === 'string' && typeof data!.summary === 'string' && typeof data!.body === 'string' && ['html', 'markdown'].includes(data!.format) && ['dataset', 'admin'].includes(data!.contentSource) && Number.isInteger(data!.revision) && data!.revision >= 1 && Number.isInteger(data!.id) && data!.id > 0 && (props.articleId === null || data!.id === props.articleId)
}
function exceptionMessage(exception: unknown, fallback: string) {
  return (exception as { data?: { message?: string } })?.data?.message || fallback
}
function populate(detail: Detail) {
  for (const key of Object.keys(form) as (keyof typeof form)[]) {
    if (typeof detail[key] === 'string') (form as Record<string, string>)[key] = detail[key] as string
  }
  buffers.html = detail.format === 'html' ? detail.body : ''
  buffers.markdown = detail.format === 'markdown' ? detail.body : ''
  revision.value = detail.revision
  contentSource.value = detail.contentSource
  rendered.value = detail
  persistedContent.value = { format: detail.format, body: detail.body }
  baseline.value = snapshot()
}
async function loadDetail(keepDraft = false) {
  if (saving.value) return
  const active = ++session
  ++previewSequence
  previewing.value = false
  loading.value = true
  error.value = ''
  try {
    const detail = await request<Detail>(`/admin/${props.kind}/${props.articleId}`)
    if (active !== session) return
    if (!validDetail(detail)) throw new Error('invalid response')
    if (keepDraft) {
      latest.value = detail
      feedback.value = '已读取最新版本；你的草稿仍保留。采用最新版本后可重新编辑。'
    } else { populate(detail); detailReady.value = true }
  } catch (exception) {
    if (active === session) error.value = exceptionMessage(exception, '读取失败或详情格式无效，请重试。')
  } finally { if (active === session) loading.value = false }
}
function confirmLeave() {
  if (saving.value) { feedback.value = '正在保存，请等待保存结果。'; return false }
  return !dirty.value || window.confirm('有未保存的修改，确定放弃并离开？')
}
function close() { if (confirmLeave()) emit('close') }
function adoptLatest() {
  if (latest.value && window.confirm('确定用最新版本替换当前草稿？当前输入将被放弃。')) {
    populate(latest.value); latest.value = null; conflict.value = false; feedback.value = '已采用最新版本'; error.value = ''
  }
}
async function preview() {
  if (loading.value || saving.value) return
  const active = session, sequence = ++previewSequence
  const input = { format: form.format, body: body.value }
  previewing.value = true; error.value = ''; feedback.value = ''
  try {
    const response = await request<Rendered>('/admin/articles/preview', { method: 'POST', body: input })
    if (active !== session || sequence !== previewSequence) return
    if (!validRendered(response)) throw new Error('invalid response')
    rendered.value = response
    feedback.value = '预览已更新'
  } catch (exception) {
    if (active === session && sequence === previewSequence) error.value = exceptionMessage(exception, '预览失败或响应格式无效，请重试。')
  } finally { if (active === session && sequence === previewSequence) previewing.value = false }
}
async function save() {
  if (saving.value || loading.value || !detailReady.value || conflict.value || !form.title.trim()) return
  const active = session
  saving.value = true; error.value = ''; feedback.value = ''
  ++previewSequence; previewing.value = false
  const { format, ...metadata } = form
  const contentChanged = props.articleId === null || persistedContent.value?.format !== format || persistedContent.value?.body !== body.value
  const payload = { ...metadata, ...(contentChanged ? { format, body: body.value } : {}), ...(props.articleId === null ? {} : { expectedRevision: revision.value }) }
  try {
    const response = await request<Detail>(`/admin/${props.kind}${props.articleId === null ? '' : `/${props.articleId}`}`, { method: props.articleId === null ? 'POST' : 'PATCH', body: payload })
    if (active !== session) return
    if (!validDetail(response)) throw new Error('invalid response')
    // The saved response is authoritative: HTML writes may be sanitized by the server.
    // Keep the inactive format draft while aligning the active buffer with persisted bytes.
    form.format = response.format
    buffers[response.format] = response.body
    persistedContent.value = { format: response.format, body: response.body }
    rendered.value = response; revision.value = response.revision; contentSource.value = response.contentSource
    baseline.value = snapshot()
    feedback.value = '已保存'
    emit('saved')
    // Close new articles after success so subsequent saves cannot create duplicates.
    if (props.articleId === null) emit('close')
  } catch (exception) {
    if (active !== session) return
    const status = (exception as { status?: number; statusCode?: number; response?: { status?: number } })
    conflict.value = [status.status, status.statusCode, status.response?.status].includes(409)
    error.value = conflict.value ? '此文章已被其他修改更新。你的输入已保留，请读取最新版本后处理冲突。' : exceptionMessage(exception, '保存失败或响应格式无效，输入已保留，请重试。')
  } finally { if (active === session) saving.value = false }
}
watch(() => [props.kind, props.articleId], () => {
  ++session; ++previewSequence
  loading.value = false; previewing.value = false; saving.value = false; detailReady.value = props.articleId === null
  error.value = ''; feedback.value = ''; conflict.value = false; latest.value = null; rendered.value = null; persistedContent.value = null
  Object.assign(form, { title: '', summary: '', status: 'published', period: '', priority: 'A', level: 'S', type: '形势与政策', tag: '', category: '选择题规律', format: 'markdown' })
  buffers.html = ''; buffers.markdown = ''; revision.value = 0; contentSource.value = 'admin'
  baseline.value = snapshot()
  if (props.articleId !== null) void loadDetail()
}, { immediate: true })
watch(() => [form.format, body.value], () => {
  ++previewSequence; previewing.value = false; rendered.value = null
}, { flush: 'sync' })
const beforeUnload = (event: BeforeUnloadEvent) => { if (dirty.value || saving.value) { event.preventDefault(); event.returnValue = '' } }
onMounted(() => window.addEventListener('beforeunload', beforeUnload))
onBeforeUnmount(() => { ++session; ++previewSequence; window.removeEventListener('beforeunload', beforeUnload) })
onBeforeRouteLeave(() => confirmLeave())
onBeforeRouteUpdate(() => confirmLeave())
defineExpose({ confirmLeave })
</script>

<template>
  <section class="article-editor" aria-label="文章编辑器">
    <div class="section-heading"><h3>{{ articleId === null ? '新增文章' : '编辑文章' }}</h3><button class="ghost-button" type="button" :disabled="saving" @click="close">关闭编辑器</button></div>
    <p v-if="loading" role="status">正在读取文章…</p>
    <p v-if="error" role="alert">{{ error }}</p>
    <div v-if="error && !conflict"><button v-if="articleId !== null && !dirty" class="ghost-button" type="button" :disabled="loading || saving" @click="loadDetail()">重试读取</button></div>
    <div v-if="conflict"><button class="ghost-button" type="button" :disabled="loading || saving" @click="loadDetail(true)">读取最新版本（保留草稿）</button><div v-if="latest"><p>最新版本 {{ latest.revision }}：{{ latest.title }}</p><button class="ghost-button" type="button" @click="adoptLatest">采用最新版本</button></div></div>
    <p v-if="feedback" role="status">{{ feedback }}</p>
    <form class="admin-form" @submit.prevent="save">
      <fieldset :disabled="loading || saving || !detailReady">
        <label><span>标题</span><input v-model="form.title" required aria-label="文章标题" /></label>
        <label><span>状态</span><select v-model="form.status" aria-label="文章状态"><option value="published">已发布</option><option value="hidden">已隐藏</option></select></label>
        <template v-if="kind === 'hotspots'">
          <label><span>期次</span><input v-model="form.period" aria-label="文章期次" /></label>
          <label><span>优先级</span><select v-model="form.priority" aria-label="文章优先级"><option>S</option><option>A</option><option>B</option><option>C</option></select></label>
          <label><span>等级</span><select v-model="form.level" aria-label="文章等级"><option>S</option><option>A</option><option>B</option></select></label>
          <label><span>类型</span><input v-model="form.type" aria-label="文章类型" /></label>
          <label><span>标签</span><input v-model="form.tag" aria-label="文章标签" /></label>
        </template>
        <label v-else><span>分类</span><input v-model="form.category" aria-label="文章分类" /></label>
        <label class="wide"><span>摘要</span><textarea v-model="form.summary" rows="2" aria-label="文章摘要" /></label>
        <label><span>正文格式</span><select v-model="form.format" aria-label="正文格式"><option value="markdown">Markdown</option><option value="html">HTML</option></select></label>
        <p class="wide admin-meta">HTML 与 Markdown 各自保留草稿，切换格式不会自动转换正文。保存使用当前选中的格式。</p>
        <label class="wide"><span>正文 {{ form.format === 'html' ? 'HTML' : 'Markdown' }}</span><textarea v-model="body" rows="14" aria-label="文章正文" /></label>
        <div class="wide editor-actions"><button class="ghost-button" type="button" @click="preview">{{ previewing ? '更新预览…' : '预览正文' }}</button><button class="primary-button" type="submit" :disabled="conflict || !form.title.trim()">{{ saving ? '保存中…' : '保存文章' }}</button><span class="admin-meta">{{ dirty ? '有未保存修改' : '无未保存修改' }} · 修订 {{ revision }} · 来源 {{ contentSource }}</span></div>
      </fieldset>
    </form>
    <div v-if="rendered" class="article-preview"><h4>正文预览 · {{ rendered.wordCount }} 字 · {{ rendered.outline.length }} 个标题</h4><iframe title="安全正文预览" sandbox="" :srcdoc="previewDocument" /></div>
  </section>
</template>

<style scoped>
.article-editor { margin: 24px 0; padding: 20px; border: 1px solid var(--border); border-radius: 12px; background: var(--card-bg, #fff); }
.article-editor fieldset { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; grid-column: 1 / -1; padding: 0; border: 0; min-width: 0; }
.wide { grid-column: 1 / -1; }
.editor-actions { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
.article-preview { background: #fff; color: #202124; padding: 16px; border-radius: 8px; margin-top: 20px; }
.article-preview iframe { width: 100%; min-height: 360px; border: 1px solid #ddd; background: #fff; }
@media (max-width: 640px) { .article-editor fieldset { grid-template-columns: 1fr; } .article-editor { padding: 12px; } }
</style>
