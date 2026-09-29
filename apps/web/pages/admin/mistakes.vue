<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
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

const { request, restore } = useAuth()
restore()

const students = ref<AdminMistakeStudent[]>([])
const activeCode = ref('')
const message = ref('')

const perPage = 10
const page = ref(1)
const total = ref(0)
const items = ref<MistakeItem[]>([])

const profile = ref<ProfilePayload | null>(null)
const markdown = ref('')
const sourceFile = ref('')
const saving = ref(false)
const dirty = ref(false)

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

const stats = ref<ReviewStat[]>([])

async function loadStats() {
  try {
    stats.value = await request<ReviewStat[]>('/admin/mistakes/review-stats')
  } catch {
    stats.value = []
  }
}

// 条目编辑（B3）
const editing = ref<MistakeItem | null>(null)
const editForm = ref({ action: '', errorType: '', module: '' })
const editSaving = ref(false)

function startEdit(item: MistakeItem) {
  editing.value = item
  editForm.value = { action: item.action, errorType: item.errorType, module: item.module }
}

function cancelEdit() {
  editing.value = null
}

async function saveEdit() {
  const item = editing.value
  if (!item) return
  editSaving.value = true
  message.value = ''
  try {
    const updated = await request<MistakeItem>(`/admin/mistakes/items/${item.id}`, {
      method: 'PATCH',
      body: {
        action: editForm.value.action,
        errorType: editForm.value.errorType,
        module: editForm.value.module,
      },
    })
    items.value = items.value.map((row) => (row.id === updated.id ? updated : row))
    editing.value = null
    message.value = `已更新错题 #${updated.id}`
  } catch (exception) {
    message.value = (exception as { data?: { message?: string } })?.data?.message || '更新失败'
  } finally {
    editSaving.value = false
  }
}

async function loadStudents() {
  try {
    students.value = await request<AdminMistakeStudent[]>('/admin/mistakes/students')
  } catch (exception) {
    message.value = (exception as { data?: { message?: string } })?.data?.message || '考生列表加载失败'
  }
}

onMounted(() => {
  loadStudents()
  loadStats()
})

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage)))

async function loadItems() {
  if (!activeCode.value) return
  try {
    const data = await request<AdminListPayload<MistakeItem> & { code: string; name: string }>(
      `/admin/mistakes/students/${encodeURIComponent(activeCode.value)}/items?page=${page.value}&perPage=${perPage}`
    )
    items.value = data.items
    total.value = data.total
  } catch {
    items.value = []
    total.value = 0
  }
}

async function selectStudent(student: AdminMistakeStudent) {
  activeCode.value = student.code
  page.value = 1
  await loadItems()
  await loadProfile()
}

async function loadProfile() {
  profile.value = null
  dirty.value = false
  try {
    const data = await request<ProfilePayload>(`/admin/mistakes/students/${encodeURIComponent(activeCode.value)}/profile`)
    profile.value = data
    markdown.value = data.markdown
    sourceFile.value = data.sourceFile === 'default.md' ? '' : data.sourceFile
  } catch {
    profile.value = null
    markdown.value = ''
  }
}

watch(page, loadItems)

function onFileChange(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  if (!file.name.endsWith('.md')) {
    message.value = '请选择 Markdown (.md) 文件'
    return
  }
  const reader = new FileReader()
  reader.onload = () => {
    markdown.value = String(reader.result ?? '')
    sourceFile.value = file.name
    dirty.value = true
    message.value = `已载入 ${file.name}，确认无误后点保存。`
  }
  reader.readAsText(file)
  input.value = ''
}

async function save() {
  if (!activeCode.value) return
  if (!markdown.value.trim()) {
    message.value = '内容不能为空'
    return
  }
  saving.value = true
  message.value = ''
  try {
    const data = await request<ProfilePayload>(
      `/admin/mistakes/students/${encodeURIComponent(activeCode.value)}/profile`,
      { method: 'PUT', body: { markdown: markdown.value, sourceFile: sourceFile.value || 'admin-console.md' } }
    )
    profile.value = data
    dirty.value = false
    message.value = `已保存并发布到考生 ${data.code} 的详情页`
  } catch (exception) {
    message.value = (exception as { data?: { message?: string } })?.data?.message || '保存失败'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section class="admin-section">
    <div class="section-heading"><div><span class="section-kicker">错题后台</span><h2>考生与错题分析</h2></div></div>
    <p v-if="message" class="admin-meta">{{ message }}</p>

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
            <button type="button" class="ghost-button small" @click="selectStudent(student)">
              {{ activeCode === student.code ? '当前' : '管理' }}
            </button>
          </td>
        </tr>
      </tbody>
    </table>
    <div v-if="!students.length" class="empty-state">暂无考生。</div>

    <div class="section-heading compact"><div><span class="section-kicker">复习数据看板</span><h2>按考生汇总</h2></div></div>
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
    <div v-if="!stats.length" class="empty-state">暂无复习数据。</div>

    <template v-if="activeCode">
      <div class="section-heading compact"><div><span class="section-kicker">考生 {{ activeCode }}</span><h2>错题条目（{{ total }}）</h2></div></div>
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
              <button type="button" class="ghost-button small" @click="startEdit(item)">编辑</button>
            </td>
          </tr>
        </tbody>
      </table>

      <form v-if="editing" class="admin-form edit-form" @submit.prevent="saveEdit">
        <div class="section-heading compact"><div><span class="section-kicker">错题 #{{ editing.id }}</span><h2>编辑全局字段</h2></div></div>
        <label class="wide"><span>行动建议（全局）</span><textarea v-model="editForm.action" rows="3"></textarea></label>
        <label><span>错因</span><input v-model="editForm.errorType" /></label>
        <label><span>模块</span><input v-model="editForm.module" /></label>
        <div class="edit-actions">
          <button class="primary-button" type="submit" :disabled="editSaving">{{ editSaving ? '保存中…' : '保存' }}</button>
          <button class="ghost-button" type="button" @click="cancelEdit">取消</button>
        </div>
      </form>
      <div class="pagination-row">
        <button type="button" class="ghost-button small" :disabled="page <= 1" @click="page -= 1">上一页</button>
        <span class="admin-meta">第 {{ page }} / {{ totalPages }} 页</span>
        <button type="button" class="ghost-button small" :disabled="page >= totalPages" @click="page += 1">下一页</button>
      </div>

      <div class="section-heading compact"><div><span class="section-kicker">错题分析</span><h2>Markdown 上传</h2></div></div>
      <p class="admin-meta">
        <template v-if="profile && !profile.isDefault">
          当前版本来源：{{ profile.sourceFile }} · 更新于 {{ profile.updatedAt.slice(0, 16) }}
        </template>
        <template v-else>当前为默认占位内容，上传后考生详情页立即可见。</template>
      </p>
      <form class="admin-form" @submit.prevent="save">
        <label class="wide upload-row">
          <span>上传 .md 文件</span>
          <span class="upload-controls">
            <input type="file" accept=".md,text/markdown" @change="onFileChange" />
            <span class="ghost-button small upload-hint"><Upload :size="13" />或直接在下方编辑</span>
          </span>
        </label>
        <label class="wide"><span>来源文件名</span><input v-model="sourceFile" placeholder="如 mistakes-A-2026-09.md" /></label>
        <label class="wide"><span>Markdown 内容</span><textarea v-model="markdown" rows="12" @input="dirty = true"></textarea></label>
        <button class="primary-button" type="submit" :disabled="saving || !dirty">
          {{ saving ? '保存中…' : '保存并发布' }}
        </button>
      </form>
    </template>
  </section>
</template>

<style scoped>
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
</style>
