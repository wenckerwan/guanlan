<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import type { AdminListPayload, Paper, Question } from '~/types/api'

const { request, restore } = useAuth()
restore()

const perPage = 20
const page = ref(1)
const total = ref(0)
const papers = ref<Paper[]>([])
const message = ref('')

const expandedPid = ref<number | null>(null)
const questions = ref<Question[]>([])
const questionsLoading = ref(false)

async function load() {
  try {
    const data = await request<AdminListPayload<Paper>>(`/admin/papers?page=${page.value}&perPage=${perPage}`)
    papers.value = data.items
    total.value = data.total
  } catch (exception) {
    message.value = (exception as { data?: { message?: string } })?.data?.message || '加载失败'
  }
}

onMounted(load)

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage)))

function go(next: number) {
  page.value = Math.min(Math.max(1, next), totalPages.value)
}

watch(page, load)

async function loadQuestions(paper: Paper) {
  questionsLoading.value = true
  try {
    const data = await request<AdminListPayload<Question>>(
      `/admin/papers/${encodeURIComponent(paper.pid)}/questions?page=1&perPage=100`
    )
    questions.value = data.items
  } catch {
    questions.value = []
  } finally {
    questionsLoading.value = false
  }
}

async function toggle(paper: Paper) {
  if (expandedPid.value === paper.id) {
    expandedPid.value = null
    questions.value = []
    return
  }
  expandedPid.value = paper.id
  await loadQuestions(paper)
}

/* ---------- 试卷增删改 ---------- */

const paperDraftOpen = ref(false)
const paperEditing = ref<Paper | null>(null)
const paperDraft = reactive({ year: new Date().getFullYear(), label: '', kind: '统考', pid: '', totalScore: 100, sortOrder: 0 })

function openCreatePaper() {
  paperEditing.value = null
  Object.assign(paperDraft, { year: new Date().getFullYear(), label: '', kind: '统考', pid: '', totalScore: 100, sortOrder: 0 })
  paperDraftOpen.value = true
}

function openEditPaper(paper: Paper) {
  paperEditing.value = paper
  Object.assign(paperDraft, { year: paper.year, label: paper.label, kind: paper.kind, pid: paper.pid, totalScore: paper.totalScore, sortOrder: 0 })
  paperDraftOpen.value = true
}

async function savePaper() {
  message.value = ''
  try {
    if (paperEditing.value) {
      await request(`/admin/papers/${paperEditing.value.id}`, { method: 'PATCH', body: { ...paperDraft } })
      message.value = '试卷已更新'
    } else {
      await request('/admin/papers', { method: 'POST', body: { ...paperDraft } })
      message.value = '试卷已创建（可在题目区继续编辑）'
    }
    paperDraftOpen.value = false
    await load()
  } catch (exception) {
    message.value = (exception as { data?: { message?: string } })?.data?.message || '保存失败'
  }
}

async function deletePaper(paper: Paper, force = false) {
  const tip = force
    ? `确定删除「${paper.year} ${paper.label || paper.pid}」及其全部题目？此操作不可恢复。`
    : `确定删除「${paper.year} ${paper.label || paper.pid}」？`
  if (!window.confirm(tip)) return
  message.value = ''
  try {
    await request(`/admin/papers/${paper.id}?force=${force ? 1 : 0}`, { method: 'DELETE' })
    message.value = '试卷已删除'
    await load()
  } catch (exception) {
    const data = (exception as { data?: { message?: string } })?.data
    if ((exception as { status?: number })?.status === 409 || data?.message?.includes('题目')) {
      if (window.confirm(data?.message ?? '该卷还有题目，连同题目一起删除？')) await deletePaper(paper, true)
      return
    }
    message.value = data?.message || '删除失败'
  }
}

/* ---------- 题目编辑 ---------- */

const editingQuestion = ref<Question | null>(null)
const questionDraft = reactive({ stem: '', material: '', answer: '', answerText: '', analysis: '', kaodian: '', moduleName: '', score: 0, optionsText: '' })

function optionsToText(options: Question['options']): string {
  return Object.entries(options ?? {})
    .map(([k, v]) => `${k}=${v}`)
    .join('\n')
}

function textToOptions(text: string): Record<string, string> {
  const out: Record<string, string> = {}
  for (const line of text.split('\n')) {
    const idx = line.indexOf('=')
    if (idx <= 0) continue
    const key = line.slice(0, idx).trim()
    const value = line.slice(idx + 1).trim()
    if (key && value) out[key] = value
  }
  return out
}

function openEditQuestion(question: Question) {
  editingQuestion.value = question
  Object.assign(questionDraft, {
    stem: question.stem,
    material: question.material,
    answer: question.answer ?? '',
    answerText: question.answerText ?? '',
    analysis: question.analysis ?? '',
    kaodian: question.kaodian,
    moduleName: question.moduleName,
    score: question.score,
    optionsText: optionsToText(question.options),
  })
}

async function saveQuestion() {
  const question = editingQuestion.value
  if (!question) return
  message.value = ''
  try {
    await request(`/admin/questions/${question.id}`, {
      method: 'PATCH',
      body: {
        stem: questionDraft.stem,
        material: questionDraft.material,
        answer: questionDraft.answer,
        answerText: questionDraft.answerText,
        analysis: questionDraft.analysis,
        kaodian: questionDraft.kaodian,
        moduleName: questionDraft.moduleName,
        score: questionDraft.score,
        options: textToOptions(questionDraft.optionsText),
      },
    })
    message.value = `第 ${question.no} 题已更新`
    editingQuestion.value = null
    const paper = papers.value.find((item) => item.id === expandedPid.value)
    if (paper) await loadQuestions(paper)
  } catch (exception) {
    message.value = (exception as { data?: { message?: string } })?.data?.message || '保存失败'
  }
}
</script>

<template>
  <section class="admin-section">
    <div class="section-heading">
      <div><span class="section-kicker">真题管理</span><h2>真题库（{{ total }} 卷）</h2></div>
      <button type="button" class="primary-button" @click="openCreatePaper">新建试卷</button>
    </div>

    <p v-if="message" class="admin-meta">{{ message }}</p>

    <form v-if="paperDraftOpen" class="admin-form" @submit.prevent="savePaper">
      <p class="admin-meta">{{ paperEditing ? `编辑试卷：${paperEditing.year} ${paperEditing.label || paperEditing.pid}` : '新建试卷' }}</p>
      <label><span>年份</span><input v-model.number="paperDraft.year" type="number" required min="1991" max="2100" /></label>
      <label><span>卷名</span><input v-model="paperDraft.label" type="text" placeholder="统考真题 / 文科 / 军职" /></label>
      <label><span>类型</span><input v-model="paperDraft.kind" type="text" placeholder="统考 / 自命题" /></label>
      <label><span>试卷编号（留空自动生成）</span><input v-model="paperDraft.pid" type="text" :disabled="!!paperEditing" /></label>
      <label><span>满分</span><input v-model.number="paperDraft.totalScore" type="number" min="0" /></label>
      <label><span>排序</span><input v-model.number="paperDraft.sortOrder" type="number" /></label>
      <button class="primary-button" type="submit">保存</button>
      <button class="ghost-button" type="button" @click="paperDraftOpen = false">取消</button>
    </form>

    <table class="admin-table">
      <thead><tr><th>年份</th><th>卷名</th><th>类型</th><th>题数</th><th>满分</th><th>操作</th></tr></thead>
      <tbody>
        <tr v-for="paper in papers" :key="paper.pid">
          <td>{{ paper.year }}</td>
          <td>{{ paper.label || '统考真题' }}</td>
          <td>{{ paper.kind }}</td>
          <td>{{ paper.questionCount }}</td>
          <td>{{ paper.totalScore }}</td>
          <td class="admin-actions">
            <button type="button" class="ghost-button small" @click="toggle(paper)">
              {{ expandedPid === paper.id ? '收起题目' : '查看题目' }}
            </button>
            <button type="button" class="ghost-button small" @click="openEditPaper(paper)">编辑</button>
            <button type="button" class="ghost-button small" @click="deletePaper(paper)">删除</button>
          </td>
        </tr>
      </tbody>
    </table>

    <div v-if="expandedPid" class="admin-detail-panel">
      <p v-if="questionsLoading" class="admin-meta">题目加载中…</p>
      <table v-else class="admin-table">
        <thead><tr><th>#</th><th>模块</th><th>题干</th><th>答案</th><th>操作</th></tr></thead>
        <tbody>
          <tr v-for="question in questions" :key="question.id">
            <td>{{ question.no }}</td>
            <td>{{ question.moduleName || question.module }}</td>
            <td class="admin-stem">{{ question.stem }}</td>
            <td>{{ question.answer ?? '—' }}</td>
            <td class="admin-actions">
              <button type="button" class="ghost-button small" @click="openEditQuestion(question)">编辑</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <form v-if="editingQuestion" class="admin-form question-editor" @submit.prevent="saveQuestion">
      <p class="admin-meta">编辑第 {{ editingQuestion.no }} 题（{{ editingQuestion.typeCn || editingQuestion.type }}）</p>
      <label class="full"><span>题干</span><textarea v-model="questionDraft.stem" rows="4" /></label>
      <label class="full"><span>材料（选填）</span><textarea v-model="questionDraft.material" rows="3" /></label>
      <label class="full"><span>选项（每行「A=选项内容」）</span><textarea v-model="questionDraft.optionsText" rows="5" /></label>
      <label><span>参考答案</span><input v-model="questionDraft.answer" type="text" placeholder="如 A / ABC" /></label>
      <label><span>分值</span><input v-model.number="questionDraft.score" type="number" step="0.5" min="0" /></label>
      <label><span>模块名称</span><input v-model="questionDraft.moduleName" type="text" /></label>
      <label><span>考点</span><input v-model="questionDraft.kaodian" type="text" /></label>
      <label class="full"><span>答案解析（Markdown）</span><textarea v-model="questionDraft.analysis" rows="6" /></label>
      <label class="full"><span>主观题答案要点（选填）</span><textarea v-model="questionDraft.answerText" rows="3" /></label>
      <button class="primary-button" type="submit">保存题目</button>
      <button class="ghost-button" type="button" @click="editingQuestion = null">取消</button>
    </form>

    <div class="pagination-row">
      <button type="button" class="ghost-button small" :disabled="page <= 1" @click="go(page - 1)">上一页</button>
      <span class="admin-meta">第 {{ page }} / {{ totalPages }} 页</span>
      <button type="button" class="ghost-button small" :disabled="page >= totalPages" @click="go(page + 1)">下一页</button>
    </div>
  </section>
</template>

<style scoped>
.pagination-row {
  display: flex;
  gap: 1rem;
  align-items: center;
  justify-content: center;
  margin-top: 1rem;
}

.admin-detail-panel {
  margin: 1rem 0;
  padding: 1rem;
  border: 1px solid var(--border);
  border-radius: 8px;
}

.admin-stem {
  max-width: 28rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.question-editor { margin-top: 1rem; }
.question-editor .full { grid-column: 1 / -1; }
</style>
