<script setup lang="ts">
definePageMeta({ layout: 'admin' })
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { onBeforeRouteLeave, onBeforeRouteUpdate } from 'vue-router'
import type { AdminListPayload, AdminQuestion, Paper } from '~/types/api'
const { request } = useAuth()
const { items: papers, total, filters, loading, loaded, loadError, visible, load, go } = useAdminList<Paper>('/admin/papers')
const message = ref('')
const expandedPaper = ref<Paper | null>(null)
const questions = ref<AdminQuestion[]>([]), questionsLoading = ref(false), questionsLoaded = ref(false), questionsError = ref('')
const questionPage = ref(1), questionTotal = ref(0), nextQuestionNo = ref(1), questionPerPage = 20
const editor = ref<{ paper: Paper; questionId: number | null; key: number; initialNo: number } | null>(null)
const editorDirty = ref(false), editorBusy = ref(false), paperSaving = ref(false), deleting = ref<number | null>(null)
const busy = computed(() => editorBusy.value || paperSaving.value || deleting.value !== null)
let questionSequence = 0, editorSequence = 0, alive = true
const paperDraftOpen = ref(false), paperEditing = ref<Paper | null>(null)
const initialPaper = () => ({ year: new Date().getFullYear(), label: '', kind: '统考', pid: '', totalScore: 100, sortOrder: 0 })
const paperDraft = reactive(initialPaper()), paperBaseline = ref('')
const paperDirty = computed(() => paperDraftOpen.value && JSON.stringify(paperDraft) !== paperBaseline.value)
function allowDiscard() {
  if (busy.value) return false
  return !(editorDirty.value || paperDirty.value) || window.confirm('有未保存的试卷或题目修改，确定放弃？')
}
function closeEditor() { editor.value = null; editorDirty.value = false; editorBusy.value = false; ++editorSequence }
function clearForms() { closeEditor(); paperDraftOpen.value = false }
function closeQuestions() { ++questionSequence; expandedPaper.value = null; questions.value = []; questionsLoading.value = false; questionsLoaded.value = false; questionsError.value = ''; clearForms() }
async function loadQuestions(paper: Paper) {
  const version = ++questionSequence, selectedPage = questionPage.value
  questionsLoading.value = true; questionsLoaded.value = false; questionsError.value = ''
  try {
    const data = await request<AdminListPayload<AdminQuestion> & { nextNo?: number }>(`/admin/papers/${encodeURIComponent(paper.pid)}/questions?page=${selectedPage}&perPage=${questionPerPage}`)
    if (!alive || version !== questionSequence || expandedPaper.value?.id !== paper.id) return
    if (!Array.isArray(data.items) || !Number.isInteger(data.total) || data.total < 0 || !Number.isInteger(data.page) || data.page < 1) throw new Error('Invalid question response')
    if (data.nextNo !== undefined && (!Number.isInteger(data.nextNo) || data.nextNo < 1)) throw new Error('Invalid next question number')
    nextQuestionNo.value = data.nextNo ?? data.total + 1
    questions.value = data.items; questionTotal.value = data.total; questionPage.value = data.page; questionsLoaded.value = true
  } catch { if (alive && version === questionSequence) questionsError.value = '题目加载失败，请重试。' }
  finally { if (alive && version === questionSequence) questionsLoading.value = false }
}
function retryQuestions() { if (!busy.value && expandedPaper.value) return loadQuestions(expandedPaper.value) }
function goQuestions(page: number) { if (questionsLoading.value || !allowDiscard() || !expandedPaper.value) return; clearForms(); questionPage.value = page; return loadQuestions(expandedPaper.value) }
async function toggle(paper: Paper) {
  if (!allowDiscard()) return
  if (expandedPaper.value?.id === paper.id) { closeQuestions(); return }
  clearForms(); ++questionSequence; expandedPaper.value = paper; questions.value = []; questionPage.value = 1; questionTotal.value = 0; message.value = ''
  await loadQuestions(paper)
}
function goPapers(page: number) { if (!busy.value) return go(page) }
onBeforeRouteUpdate((to, from) => {
  if (to.fullPath === from.fullPath) return true
  // A successful mutation may remove the last paper page. Allow the list
  // composable to canonicalize that page while keeping the save context locked.
  const lastPage = Math.max(1, Math.ceil(total.value / filters.value.perPage))
  const onlyPageChanged = Object.keys({ ...to.query, ...from.query }).filter(key => key !== 'page').every(key => JSON.stringify(to.query[key]) === JSON.stringify(from.query[key]))
  if (loaded.value && !loadError.value && to.path === from.path && onlyPageChanged && filters.value.page > lastPage && Number(to.query.page ?? 1) === lastPage) return true
  if (!allowDiscard()) return false
  closeQuestions(); return true
})
onBeforeRouteLeave(() => allowDiscard())
function beforeUnload(event: BeforeUnloadEvent) { if (busy.value || editorDirty.value || paperDirty.value) { event.preventDefault(); event.returnValue = '' } }
onMounted(() => window.addEventListener('beforeunload', beforeUnload))
onBeforeUnmount(() => { alive = false; ++questionSequence; ++editorSequence; window.removeEventListener('beforeunload', beforeUnload) })
function openCreatePaper() {
  if (!allowDiscard()) return
  clearForms(); paperEditing.value = null; Object.assign(paperDraft, initialPaper()); paperBaseline.value = JSON.stringify(paperDraft); paperDraftOpen.value = true
}
function openEditPaper(paper: Paper) {
  if (!allowDiscard()) return
  clearForms(); paperEditing.value = paper
  Object.assign(paperDraft, { year: paper.year, label: paper.label, kind: paper.kind, pid: paper.pid, totalScore: paper.totalScore, sortOrder: paper.sortOrder ?? 0 })
  paperBaseline.value = JSON.stringify(paperDraft); paperDraftOpen.value = true
}
function cancelPaper() { if (!busy.value && (!paperDirty.value || window.confirm('放弃未保存的试卷修改？'))) paperDraftOpen.value = false }
async function savePaper() {
  if (busy.value) return
  const target = paperEditing.value, body = { ...paperDraft }
  if (!Number.isInteger(body.sortOrder) || body.sortOrder < -2147483648 || body.sortOrder > 2147483647) { message.value = '试卷排序必须是 32 位有符号整数'; return }
  paperSaving.value = true; message.value = ''
  try {
    if (target) { const { pid: _pid, ...patch } = body; await request(`/admin/papers/${target.id}`, { method: 'PATCH', body: patch }) }
    else await request('/admin/papers', { method: 'POST', body })
    if (!alive) return
    paperDraftOpen.value = false; await load()
    if (!alive) return
    if (expandedPaper.value?.id === target?.id) expandedPaper.value = papers.value.find(item => item.id === target?.id) ?? expandedPaper.value
    message.value = target ? '试卷已更新' : '试卷已创建（可在题目区继续编辑）'
  } catch (exception) { if (alive) message.value = (exception as { data?: { message?: string } })?.data?.message || '试卷保存失败' }
  finally { if (alive) paperSaving.value = false }
}
async function deletePaper(paper: Paper) {
  if (!allowDiscard() || !window.confirm(`确定删除「${paper.year} ${paper.label || paper.pid}」？`)) return
  clearForms(); deleting.value = paper.id; message.value = ''
  try {
    try { await request(`/admin/papers/${paper.id}?force=0`, { method: 'DELETE' }) }
    catch (exception) {
      const failure = exception as { status?: number; statusCode?: number; data?: { message?: string } }
      if ((failure.status ?? failure.statusCode) !== 409 && !failure.data?.message?.includes('题目')) throw exception
      if (!window.confirm(`${failure.data?.message || '该卷还有题目。'}\n确定连同全部题目一起删除？此操作不可恢复。`)) return
      await request(`/admin/papers/${paper.id}?force=1`, { method: 'DELETE' })
    }
    if (!alive) return
    if (expandedPaper.value?.id === paper.id) closeQuestions()
    await load(); if (alive) message.value = '试卷已删除'
  } catch (exception) { if (alive) message.value = (exception as { data?: { message?: string } })?.data?.message || '删除失败' }
  finally { if (alive) deleting.value = null }
}
function openQuestion(questionId: number | null) {
  if (!expandedPaper.value || (questionId === null && (!questionsLoaded.value || questionsLoading.value || !!questionsError.value)) || !allowDiscard()) return
  clearForms(); message.value = ''; editor.value = { paper: { ...expandedPaper.value }, questionId, key: ++editorSequence, initialNo: nextQuestionNo.value }
}
function cancelQuestion() { if (!busy.value && (!editorDirty.value || window.confirm('放弃未保存的题目修改？'))) closeEditor() }
async function refreshQuestion() {
  const paper = editor.value?.paper; if (!paper) return
  await Promise.all([loadQuestions(paper), load()])
  if (alive && expandedPaper.value?.id === paper.id) expandedPaper.value = papers.value.find(item => item.id === paper.id) ?? expandedPaper.value
}
function questionSaved(result: string) { closeEditor(); message.value = result }
</script>
<template>
  <section class="admin-section">
    <div class="section-heading"><div><span class="section-kicker">真题管理</span><h2>真题库<span v-if="visible">（{{ total }} 卷）</span></h2></div><button type="button" class="primary-button" :disabled="busy" @click="openCreatePaper">新建试卷</button></div>
    <p v-if="message" class="admin-meta" role="status">{{ message }}</p>
    <form v-if="paperDraftOpen" class="admin-form" @submit.prevent="savePaper">
      <p class="admin-meta">{{ paperEditing ? `编辑试卷：${paperEditing.year} ${paperEditing.label || paperEditing.pid}` : '新建试卷' }}</p>
      <label><span>年份</span><input v-model.number="paperDraft.year" aria-label="试卷年份" type="number" required min="1991" max="2100" :disabled="busy" /></label>
      <label><span>卷名</span><input v-model="paperDraft.label" aria-label="试卷卷名" :disabled="busy" /></label>
      <label><span>类型</span><input v-model="paperDraft.kind" aria-label="试卷类型" :disabled="busy" /></label>
      <label><span>试卷编号（留空自动生成）</span><input v-model="paperDraft.pid" aria-label="试卷编号" :disabled="busy || !!paperEditing" /></label>
      <label><span>满分</span><input v-model.number="paperDraft.totalScore" aria-label="试卷满分" type="number" min="0" :disabled="busy" /></label>
      <label><span>排序</span><input v-model.number="paperDraft.sortOrder" aria-label="试卷排序" type="number" min="-2147483648" max="2147483647" :disabled="busy" /></label>
      <button class="primary-button" type="submit" :disabled="busy">{{ paperSaving ? '保存中…' : '保存试卷' }}</button><button class="ghost-button" type="button" :disabled="busy" @click="cancelPaper">取消试卷编辑</button>
    </form>
    <AdminListState :loading="loading" :error="loadError" :empty="loaded && !papers.length" @retry="load">暂无试卷。</AdminListState>
    <div v-if="visible && papers.length" class="table-scroll" data-testid="paper-list">
      <table class="admin-table"><thead><tr><th>年份</th><th>卷名</th><th>类型</th><th>题数</th><th>满分</th><th>排序</th><th>操作</th></tr></thead><tbody>
        <tr v-for="paper in papers" :key="paper.pid"><td>{{ paper.year }}</td><td>{{ paper.label || '统考真题' }}</td><td>{{ paper.kind }}</td><td>{{ paper.questionCount }}</td><td>{{ paper.totalScore }}</td><td>{{ paper.sortOrder ?? 0 }}</td><td class="admin-actions"><button type="button" class="ghost-button small" :disabled="busy" @click="toggle(paper)">{{ expandedPaper?.id === paper.id ? '收起题目' : '查看题目' }}</button><button type="button" class="ghost-button small" :disabled="busy" @click="openEditPaper(paper)">编辑</button><button type="button" class="ghost-button small" :disabled="busy" @click="deletePaper(paper)">删除</button></td></tr>
      </tbody></table>
    </div>
    <div v-if="expandedPaper" class="admin-detail-panel" data-testid="question-panel">
      <div class="section-heading"><div><span class="section-kicker">{{ expandedPaper.pid }} · {{ expandedPaper.year }} {{ expandedPaper.label }}</span><h3>题目维护</h3></div><button type="button" class="primary-button" :disabled="busy || questionsLoading || !questionsLoaded || !!questionsError" @click="openQuestion(null)">新增题目</button><button type="button" class="ghost-button" :disabled="busy || questionsLoading" @click="retryQuestions">刷新题目</button></div>
      <p v-if="questionsLoaded && !questionsLoading && !questionsError" class="admin-meta">共 {{ questionTotal }} 道题</p>
      <AdminListState :loading="questionsLoading" :error="questionsError" :empty="questionsLoaded && !questions.length" @retry="retryQuestions">暂无题目。</AdminListState>
      <div v-if="questionsLoaded && !questionsLoading && !questionsError && questions.length" class="table-scroll" data-testid="question-list">
        <table class="admin-table"><thead><tr><th>题号</th><th>排序</th><th>题型</th><th>模块</th><th>题干</th><th>答案</th><th>操作</th></tr></thead><tbody>
          <tr v-for="question in questions" :key="question.id"><td>{{ question.no }}</td><td>{{ question.sortOrder }}</td><td>{{ question.typeCn || question.type }}</td><td>{{ question.moduleName || question.module }}</td><td class="admin-stem">{{ question.stem }}</td><td>{{ question.answer ?? '—' }}</td><td><button type="button" class="ghost-button small" :disabled="busy" @click="openQuestion(question.id)">编辑</button></td></tr>
        </tbody></table>
      </div>
      <AdminPagination v-if="questionsLoaded && !questionsLoading && !questionsError" :page="questionPage" :per-page="questionPerPage" :total="questionTotal" :busy="busy || questionsLoading" @change="goQuestions" />
    </div>
    <AdminQuestionEditor v-if="editor" :key="editor.key" :paper="editor.paper" :question-id="editor.questionId" :initial-no="editor.initialNo" :refresh="refreshQuestion" @dirty="editorDirty = $event" @busy="editorBusy = $event" @close="cancelQuestion" @saved="questionSaved" />
    <AdminPagination v-if="visible" :page="filters.page" :per-page="filters.perPage" :total="total" :busy="busy || loading" @change="goPapers" />
  </section>
</template>
<style scoped>
.admin-section { min-width: 0; }
.table-scroll { width: 100%; max-width: 100%; overflow-x: auto; }
.table-scroll .admin-table { min-width: 760px; }
.admin-detail-panel { margin: 1rem 0; padding: 1rem; min-width: 0; border: 1px solid var(--line); border-radius: 8px; }
.admin-stem { max-width: 28rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.section-heading { flex-wrap: wrap; }
.section-kicker { overflow-wrap: anywhere; }
@media (max-width: 640px) { .admin-form { grid-template-columns: minmax(0, 1fr); } .admin-form label { min-width: 0; } .admin-form input { width: 100%; min-width: 0; max-width: 100%; } }
</style>
