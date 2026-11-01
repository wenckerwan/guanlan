<script setup lang="ts">
definePageMeta({ layout: 'admin' })
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { onBeforeRouteLeave, onBeforeRouteUpdate } from 'vue-router'
import { Upload } from 'lucide-vue-next'
import type { AdminListPayload, MistakeItem } from '~/types/api'
type AdminMistakeStudent = {
  code: string
  name: string
  relation: string
  itemCount: number
  moduleCounts: Record<string, number>
  errorTypes: Record<string, number>
  ownerEmail: string
  ownerId: number | null
  isDataset: boolean
}

type ProfilePayload = {
  code: string
  name: string
  markdown: string
  html: string
  isDefault: boolean
  sourceFile: string
  updatedAt: string
}

type ReviewStat = {
  code: string
  name: string
  itemCount: number
  learners: number
  reviewCount: number
  correctCount: number
  accuracy: number
  new: number
  reviewing: number
  mastered: number
  snoozed: number
  overdue: number
}


const { request, restore } = useAuth()
const route = useRoute(), router = useRouter()
const text = (value: unknown) => typeof value === 'string' ? value : ''
function positive(value: unknown, fallback = 20, max = 100) {
  const number = Number(text(value)); return Number.isSafeInteger(number) && number > 0 ? Math.min(number, max) : fallback
}
const studentFilters = computed(() => ({ q: text(route.query.studentQ), page: positive(route.query.studentPage, 1, Number.MAX_SAFE_INTEGER), perPage: positive(route.query.studentPerPage) }))
const itemFilters = computed(() => ({ module: text(route.query.module), errorType: text(route.query.errorType), page: positive(route.query.page, 1, Number.MAX_SAFE_INTEGER), perPage: positive(route.query.perPage) }))
const activeCode = computed(() => text(route.query.code))
const studentDraft = reactive({ ...studentFilters.value }), itemDraft = reactive({ ...itemFilters.value })
const sizes = (current: number) => [...new Set([20, 50, 100, current])].sort((a,b) => a-b)
const students = ref<AdminMistakeStudent[]>([]), studentTotal = ref(0)
const items = ref<MistakeItem[]>([]), total = ref(0), activeName = ref('')
const facets = ref({ modules: [] as string[], errorTypes: [] as string[] })
const stats = ref<ReviewStat[]>([])
const profile = ref<ProfilePayload | null>(null), markdown = ref(''), sourceFile = ref(''), previewOpen = ref(false)
const message = ref(''), saving = ref(false), editSaving = ref(false)
const busy = computed(() => saving.value || editSaving.value)
const editing = ref<MistakeItem | null>(null)
const editForm = ref({ action: '', errorType: '', module: '' })
const dirty = computed(() => !!profile.value && (markdown.value !== profile.value.markdown || sourceFile.value !== (profile.value.sourceFile === 'default.md' ? '' : profile.value.sourceFile)))
const editDirty = computed(() => !!editing.value && ['action','errorType','module'].some(key => editForm.value[key as keyof typeof editForm.value] !== editing.value![key as keyof typeof editForm.value]))
function state() { return reactive({ loading: false, loaded: false, error: '' }) }
const studentState = state(), itemState = state(), profileState = state(), statsState = state()
let studentSequence = 0, itemSequence = 0, profileSequence = 0, statsSequence = 0, session = 0, uploadSequence = 0
let started = false, alive = true, replacing = false
const visible = (listState: ReturnType<typeof state>) => listState.loaded && !listState.loading && !listState.error
function begin(listState: ReturnType<typeof state>) { listState.loading = true; listState.loaded = false; listState.error = '' }
function errorMessage(exception: unknown, fallback: string) {
  const error = exception as { status?: number; statusCode?: number }
  return (error.status ?? error.statusCode) === 401 ? '登录已失效，请重新登录。' : (error.status ?? error.statusCode) === 403 ? '当前账号没有后台权限。' : fallback
}
function validList(data: AdminListPayload<unknown>) {
  if (!data || !Array.isArray(data.items) || !Number.isInteger(data.total) || data.total < 0 || !Number.isInteger(data.page) || data.page < 1 || !Number.isInteger(data.perPage) || data.perPage < 1) throw new Error('Invalid list response')
}
async function replaceNormalized(patch: Record<string, string | undefined>) {
  replacing = true
  try { await router.replace({ query: { ...route.query, ...patch } }) } finally { replacing = false }
}
async function loadStudents() {
  const version = ++studentSequence, selected = { ...studentFilters.value }; begin(studentState)
  try {
    const params = new URLSearchParams({ q: selected.q, page: String(selected.page), perPage: String(selected.perPage) })
    const data = await request<AdminListPayload<AdminMistakeStudent>>(`/admin/mistakes/students?${params}`)
    if (!alive || version !== studentSequence) return
    validList(data); students.value = data.items; studentTotal.value = data.total; studentState.loaded = true
    if (data.page !== selected.page || data.perPage !== selected.perPage) await replaceNormalized({ studentPage: data.page === 1 ? undefined : String(data.page), studentPerPage: data.perPage === 20 ? undefined : String(data.perPage) })
  } catch (exception) { if (alive && version === studentSequence) studentState.error = errorMessage(exception, '考生列表加载失败，请重试。') }
  finally { if (alive && version === studentSequence) studentState.loading = false }
}
async function loadStats() {
  const version = ++statsSequence; begin(statsState)
  try { const data = await request<ReviewStat[]>('/admin/mistakes/review-stats'); if (!alive || version !== statsSequence) return; if (!Array.isArray(data)) throw new Error('Invalid stats'); stats.value = data; statsState.loaded = true }
  catch (exception) { if (alive && version === statsSequence) statsState.error = errorMessage(exception, '复习统计加载失败，请重试。') }
  finally { if (alive && version === statsSequence) statsState.loading = false }
}
async function loadItems() {
  const code = activeCode.value; if (!code) return
  const version = ++itemSequence, selected = { ...itemFilters.value }; begin(itemState)
  try {
    const params = new URLSearchParams({ module: selected.module, errorType: selected.errorType, page: String(selected.page), perPage: String(selected.perPage) })
    const data = await request<AdminListPayload<MistakeItem> & { code: string; name: string; filters: typeof facets.value }>(`/admin/mistakes/students/${encodeURIComponent(code)}/items?${params}`)
    if (!alive || version !== itemSequence || activeCode.value !== code) return
    validList(data); if (data.code !== code || !data.filters || !Array.isArray(data.filters.modules) || !Array.isArray(data.filters.errorTypes)) throw new Error('Invalid items')
    items.value = data.items; total.value = data.total; activeName.value = data.name; facets.value = data.filters; itemState.loaded = true
    if (data.page !== selected.page || data.perPage !== selected.perPage) await replaceNormalized({ page: data.page === 1 ? undefined : String(data.page), perPage: data.perPage === 20 ? undefined : String(data.perPage) })
  } catch (exception) { if (alive && version === itemSequence) itemState.error = errorMessage(exception, '错题条目加载失败，请重试。') }
  finally { if (alive && version === itemSequence) itemState.loading = false }
}
async function loadProfile() {
  const code = activeCode.value; if (!code || busy.value || dirty.value) return
  const version = ++profileSequence; begin(profileState); profile.value = null
  try {
    const data = await request<ProfilePayload>(`/admin/mistakes/students/${encodeURIComponent(code)}/profile`)
    if (!alive || version !== profileSequence || activeCode.value !== code) return
    if (!data || data.code !== code || typeof data.markdown !== 'string' || typeof data.isDefault !== 'boolean') throw new Error('Invalid profile')
    profile.value = data; markdown.value = data.markdown; sourceFile.value = data.sourceFile === 'default.md' ? '' : data.sourceFile; profileState.loaded = true
  } catch (exception) { if (alive && version === profileSequence) profileState.error = errorMessage(exception, '画像加载失败，请重试。') }
  finally { if (alive && version === profileSequence) profileState.loading = false }
}
async function navigate(patch: Record<string, string | undefined>, reload: () => Promise<void>) {
  if (busy.value) return
  const next = { ...route.query, ...patch }
  if (JSON.stringify(next) === JSON.stringify(route.query)) await reload()
  else await router.push({ query: next })
}
const searchStudents = () => navigate({ studentQ: studentDraft.q.trim() || undefined, studentPage: undefined, studentPerPage: studentDraft.perPage === 20 ? undefined : String(studentDraft.perPage) }, loadStudents)
const goStudents = (page: number) => navigate({ studentPage: page === 1 ? undefined : String(page) }, loadStudents)
const searchItems = () => navigate({ module: itemDraft.module || undefined, errorType: itemDraft.errorType || undefined, page: undefined, perPage: itemDraft.perPage === 20 ? undefined : String(itemDraft.perPage) }, loadItems)
const goItems = (page: number) => navigate({ page: page === 1 ? undefined : String(page) }, loadItems)
const selectStudent = (student: AdminMistakeStudent) => navigate({ code: student.code, module: undefined, errorType: undefined, page: undefined, perPage: undefined }, loadItems)
function discardConfirmed() { return !(dirty.value || editDirty.value) || window.confirm('有未保存的画像或错题修改，确定放弃并离开？') }
onBeforeRouteLeave(() => !busy.value && discardConfirmed())
onBeforeRouteUpdate(to => {
  if (replacing) return true
  if (busy.value) return false
  const switching = text(to.query.code) !== activeCode.value
  const itemChange = ['module','errorType','page','perPage'].some(key => text(to.query[key]) !== text(route.query[key]))
  if ((switching && (dirty.value || editDirty.value)) || (itemChange && editDirty.value)) {
    if (!window.confirm('有未保存的画像或错题修改，确定放弃并离开？')) return false
    editing.value = null
  }
  return true
})
watch(() => JSON.stringify(studentFilters.value), () => { ++studentSequence; Object.assign(studentDraft, studentFilters.value); if (started) void loadStudents() }, { flush: 'sync' })
watch(() => `${activeCode.value}:${JSON.stringify(itemFilters.value)}`, () => { ++itemSequence; Object.assign(itemDraft, itemFilters.value); if (started && activeCode.value) void loadItems() }, { flush: 'sync' })
watch(activeCode, () => {
  ++session; ++profileSequence; ++uploadSequence; profile.value = null; markdown.value = ''; sourceFile.value = ''; editing.value = null; items.value = []; total.value = 0; activeName.value = ''; facets.value = { modules: [], errorTypes: [] }; previewOpen.value = false; message.value = ''; Object.assign(profileState, { loading: false, loaded: false, error: '' })
  if (started && activeCode.value) void loadProfile()
}, { flush: 'sync' })
function beforeUnload(event: BeforeUnloadEvent) { if (dirty.value || editDirty.value || busy.value) { event.preventDefault(); event.returnValue = '' } }
onMounted(async () => { window.addEventListener('beforeunload', beforeUnload); await restore(); if (!alive) return; started = true; void loadStudents(); void loadStats(); if (activeCode.value) { void loadItems(); void loadProfile() } })
onBeforeUnmount(() => { alive = false; ++session; ++studentSequence; ++itemSequence; ++profileSequence; ++statsSequence; ++uploadSequence; window.removeEventListener('beforeunload', beforeUnload) })
function startEdit(item: MistakeItem) {
  if (busy.value || (editDirty.value && !window.confirm('放弃当前未保存的错题修改？'))) return
  editing.value = item; editForm.value = { action: item.action, errorType: item.errorType, module: item.module }
}
function cancelEdit() { if (!busy.value && (!editDirty.value || window.confirm('放弃当前未保存的错题修改？'))) editing.value = null }
async function saveEdit() {
  const item = editing.value, code = activeCode.value, current = session; if (!item || busy.value) return
  const patch = { ...editForm.value }; editSaving.value = true; message.value = ''
  try {
    await request<MistakeItem>(`/admin/mistakes/items/${item.id}`, { method: 'PATCH', body: patch })
    if (!alive || current !== session || code !== activeCode.value) return
    editing.value = null; message.value = `已更新错题 #${item.id}`
    await Promise.all([loadItems(), loadStudents(), loadStats()])
  } catch (exception) { if (alive && current === session) message.value = (exception as { data?: { message?: string } })?.data?.message || '更新失败' }
  finally { editSaving.value = false }
}
function onFileChange(event: Event) {
  const input = event.target as HTMLInputElement, file = input.files?.[0]; if (!file || busy.value) return
  if (!file.name.toLowerCase().endsWith('.md')) { message.value = '请选择 Markdown (.md) 文件'; input.value = ''; return }
  const code = activeCode.value, current = session, version = ++uploadSequence, reader = new FileReader()
  reader.onload = () => { if (!alive || current !== session || code !== activeCode.value || version !== uploadSequence || busy.value) return; markdown.value = String(reader.result ?? ''); sourceFile.value = file.name; message.value = `已载入 ${file.name}，确认无误后点保存。` }
  reader.onerror = () => { if (alive && current === session && version === uploadSequence) message.value = '文件读取失败，请重试。' }
  reader.readAsText(file); input.value = ''
}
async function save() {
  const code = activeCode.value, current = session; if (!code || !profile.value || busy.value || !dirty.value) return
  if (!markdown.value.trim()) { message.value = '内容不能为空'; return }
  const body = { markdown: markdown.value, sourceFile: sourceFile.value || 'admin-console.md' }; saving.value = true; message.value = ''
  try {
    const data = await request<ProfilePayload>(`/admin/mistakes/students/${encodeURIComponent(code)}/profile`, { method: 'PUT', body })
    if (!alive || current !== session || code !== activeCode.value) return
    if (data.code !== code) throw new Error('Invalid save response')
    profile.value = data; markdown.value = data.markdown; sourceFile.value = data.sourceFile === 'default.md' ? '' : data.sourceFile
    message.value = `已保存并发布到考生 ${code} 的详情页`
  } catch (exception) { if (alive && current === session) message.value = (exception as { data?: { message?: string } })?.data?.message || '保存失败' }
  finally { saving.value = false }
}
</script>

<template>
  <section class="admin-section">
    <div class="section-heading"><div><span class="section-kicker">错题后台</span><h2>考生与错题分析</h2></div></div>
    <p v-if="message" class="admin-meta">{{ message }}</p>

    <section data-testid="students">
    <form class="admin-form list-filters" role="search" @submit.prevent="searchStudents">
      <label><span>考生搜索</span><input v-model="studentDraft.q" aria-label="考生搜索" type="search" :disabled="busy" placeholder="编号、姓名或绑定邮箱" /></label>
      <label><span>考生每页条数</span><select v-model.number="studentDraft.perPage" aria-label="考生每页条数" :disabled="busy"><option v-for="size in sizes(studentDraft.perPage)" :key="size" :value="size">{{ size }} 条</option></select></label>
      <button class="primary-button" type="submit" :disabled="busy">筛选考生</button>
    </form>
    <AdminListState :loading="studentState.loading" :error="studentState.error" :empty="studentState.loaded && !students.length" @retry="loadStudents">暂无考生。</AdminListState>
    <div v-if="visible(studentState) && students.length" class="table-scroll">
    <table class="admin-table">
      <thead><tr><th>编号</th><th>名称</th><th>错题数</th><th>绑定账号</th><th>来源</th><th>操作</th></tr></thead>
      <tbody>
        <tr v-for="student in students" :key="student.code" :class="{ active: activeCode === student.code }">
          <td>{{ student.code }}</td>
          <td>{{ student.name }}</td>
          <td>{{ student.itemCount }}</td>
          <td>{{ student.ownerEmail || '—' }}</td>
          <td>{{ student.isDataset ? '数据集' : '注册生成' }}</td>
          <td class="admin-actions">
            <button type="button" class="ghost-button small" :disabled="busy" @click="selectStudent(student)">
              {{ activeCode === student.code ? '当前' : '管理' }}
            </button>
          </td>
        </tr>
      </tbody>
    </table>
    </div>
    <AdminPagination v-if="visible(studentState)" :page="studentFilters.page" :per-page="studentFilters.perPage" :total="studentTotal" :busy="busy || studentState.loading" @change="goStudents" />
    </section>

    <section data-testid="stats">
    <button class="ghost-button small" type="button" @click="loadStats">刷新统计</button>
    <AdminListState :loading="statsState.loading" :error="statsState.error" :empty="statsState.loaded && !stats.length" @retry="loadStats">暂无复习数据。</AdminListState>
    <div class="section-heading compact"><div><span class="section-kicker">复习数据看板</span><h2>按考生汇总</h2></div></div>
    <div v-if="visible(statsState) && stats.length" class="table-scroll">
    <table class="admin-table">
      <thead><tr><th>考生</th><th>错题数</th><th>学习者</th><th>复习次数</th><th>正确率</th><th>新题</th><th>复习中</th><th>已掌握</th><th>已暂停</th><th>逾期</th></tr></thead>
      <tbody>
        <tr v-for="stat in stats" :key="stat.code">
          <td>{{ stat.code }} · {{ stat.name }}</td>
          <td>{{ stat.itemCount }}</td>
          <td>{{ stat.learners }}</td>
          <td>{{ stat.reviewCount }}</td>
          <td>{{ stat.reviewCount ? Math.round(stat.accuracy * 100) + '%' : '—' }}</td>
          <td>{{ stat.new }}</td>
          <td>{{ stat.reviewing }}</td>
          <td>{{ stat.mastered }}</td>
          <td>{{ stat.snoozed }}</td>
          <td :class="{ 'overdue-cell': stat.overdue > 0 }">{{ stat.overdue }}</td>
        </tr>
      </tbody>
    </table>
    </div>
    </section>

    <template v-if="activeCode">
      <section data-testid="items">
      <div class="section-heading compact"><div><span class="section-kicker">错题条目<span v-if="visible(itemState)">（{{ total }}）</span></span><h2>考生 {{ activeCode }}<template v-if="activeName"> · {{ activeName }}</template></h2></div></div>
      <form class="admin-form list-filters" @submit.prevent="searchItems">
        <label><span>模块筛选</span><select v-model="itemDraft.module" aria-label="模块筛选" :disabled="busy"><option value="">全部模块</option><option v-if="itemDraft.module && !facets.modules.includes(itemDraft.module)" :value="itemDraft.module">{{ itemDraft.module }}</option><option v-for="option in facets.modules" :key="option" :value="option">{{ option }}</option></select></label>
        <label><span>错因筛选</span><select v-model="itemDraft.errorType" aria-label="错因筛选" :disabled="busy"><option value="">全部错因</option><option v-if="itemDraft.errorType && !facets.errorTypes.includes(itemDraft.errorType)" :value="itemDraft.errorType">{{ itemDraft.errorType }}</option><option v-for="option in facets.errorTypes" :key="option" :value="option">{{ option }}</option></select></label>
        <label><span>错题每页条数</span><select v-model.number="itemDraft.perPage" aria-label="错题每页条数" :disabled="busy"><option v-for="size in sizes(itemDraft.perPage)" :key="size" :value="size">{{ size }} 条</option></select></label>
        <button class="primary-button" type="submit" :disabled="busy">筛选错题</button>
      </form>
      <AdminListState :loading="itemState.loading" :error="itemState.error" :empty="itemState.loaded && !items.length" @retry="loadItems">暂无匹配的错题。</AdminListState>
      <div v-if="visible(itemState) && items.length" class="table-scroll">
      <table class="admin-table">
        <thead><tr><th>#</th><th>模块</th><th>错因</th><th>题干</th><th>正确答案</th><th>操作</th></tr></thead>
        <tbody>
          <tr v-for="item in items" :key="item.id">
            <td>{{ item.sourceNo }}</td>
            <td>{{ item.module }}</td>
            <td>{{ item.errorType }}</td>
            <td class="admin-stem">{{ item.stem }}</td>
            <td>{{ item.correctAnswer }}</td>
            <td class="admin-actions">
              <button type="button" class="ghost-button small" :disabled="busy" @click="startEdit(item)">编辑</button>
            </td>
          </tr>
        </tbody>
      </table>

      </div>
      <form v-if="editing" class="admin-form edit-form" @submit.prevent="saveEdit">
        <div class="section-heading compact"><div><span class="section-kicker">错题 #{{ editing.id }}</span><h2>编辑全局字段</h2></div></div>
        <label class="wide"><span>行动建议（全局）</span><textarea v-model="editForm.action" aria-label="行动建议（全局）" :disabled="busy" rows="3"></textarea></label>
        <label><span>错因</span><input v-model="editForm.errorType" aria-label="编辑错因" :disabled="busy" /></label>
        <label><span>模块</span><input v-model="editForm.module" aria-label="编辑模块" :disabled="busy" /></label>
        <div class="edit-actions">
          <button class="primary-button" type="submit" :disabled="busy">{{ editSaving ? '保存中…' : '保存错题' }}</button>
          <button class="ghost-button" type="button" :disabled="busy" @click="cancelEdit">取消</button>
        </div>
      </form>
      <AdminPagination v-if="visible(itemState)" :page="itemFilters.page" :per-page="itemFilters.perPage" :total="total" :busy="busy || itemState.loading" @change="goItems" />
      </section>
      <section data-testid="profile">
      <AdminListState :loading="profileState.loading" :error="profileState.error" :empty="false" @retry="loadProfile" />
      <template v-if="visible(profileState) && profile">
      <div class="section-heading compact"><div><span class="section-kicker">错题分析</span><h2>Markdown 上传</h2></div></div>
      <p class="admin-meta">
        <template v-if="profile && !profile.isDefault">
          当前版本来源：{{ profile.sourceFile }} · 更新于 {{ profile.updatedAt.slice(0, 16) }}
        </template>
        <template v-else-if="profile.isDefault">当前为默认占位内容，上传后考生详情页立即可见。</template>
      </p>
      <form class="admin-form" @submit.prevent="save">
        <label class="wide upload-row">
          <span>上传 .md 文件</span>
          <span class="upload-controls">
            <input type="file" aria-label="上传 .md 文件" :disabled="busy" accept=".md,text/markdown" @change="onFileChange" />
            <span class="ghost-button small upload-hint"><Upload :size="13" />或直接在下方编辑</span>
          </span>
        </label>
        <label class="wide"><span>来源文件名</span><input v-model="sourceFile" aria-label="来源文件名" :disabled="busy" placeholder="如 mistakes-A-2026-09.md" /></label>
        <label class="wide"><span>Markdown 内容</span><textarea v-model="markdown" aria-label="Markdown 内容" :disabled="busy" rows="12"></textarea></label>
        <div class="edit-actions">
          <button class="primary-button" type="submit" :disabled="busy || !dirty">
            {{ saving ? '保存中…' : '保存并发布' }}
          </button>
          <button class="ghost-button" type="button" @click="previewOpen = !previewOpen">
            {{ previewOpen ? '收起预览' : '预览已发布渲染' }}
          </button>
        </div>
      </form>

      <section v-if="previewOpen" class="preview-panel">
        <p class="admin-meta">
          {{ dirty ? '以下为最近一次保存的渲染结果，当前编辑未保存。' : '当前已发布内容的渲染结果。' }}
        </p>
        <div v-if="profile && !profile.isDefault" class="markdown-body" v-html="profile.html" />
        <p v-else class="empty-state">尚未发布过内容。</p>
      </section>
      </template>
      </section>
    </template>
  </section>
</template>

<style scoped>
.admin-section, [data-testid] { min-width: 0; }
.table-scroll { width: 100%; max-width: 100%; overflow-x: auto; }
.table-scroll .admin-table { min-width: 700px; }
.list-filters { margin-bottom: 12px; }
@media (max-width: 640px) {
  .admin-form { min-width: 0; }
  .admin-form label { min-width: 0; }
  .admin-form input, .admin-form select, .admin-form textarea { width: 100%; max-width: 100%; min-width: 0; }
  .upload-row .upload-controls, .edit-actions { flex-wrap: wrap; }
  .section-heading h2 { overflow-wrap: anywhere; }
}

tr.active {
  background: var(--primary-light, rgba(59, 130, 246, 0.08));
}

.overdue-cell {
  color: var(--error, #ef4444);
  font-weight: 600;
}

.edit-form {
  margin: 1rem 0;
  padding: 1rem;
  border: 1px solid var(--border);
  border-radius: 8px;
}

.edit-actions {
  display: flex;
  gap: 0.75rem;
  grid-column: 1 / -1;
}

.pagination-row {
  display: flex;
  gap: 1rem;
  align-items: center;
  justify-content: center;
  margin: 1rem 0;
}

.admin-stem {
  max-width: 24rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.upload-row .upload-controls {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.upload-hint {
  color: var(--text-muted);
}

.preview-panel {
  margin: 1rem 0 2rem;
  padding: 1.25rem;
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-radius: 8px;
}
</style>
