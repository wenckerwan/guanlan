<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import type { AdminListPayload, Paper, Question } from '~/types/api'

const { request, restore } = useAuth()
restore()

const perPage = 20
const page = ref(1)
const total = ref(0)
const papers = ref<Paper[]>([])
const message = ref('')

const expandedPid = ref('')
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

async function toggle(paper: Paper) {
  if (expandedPid.value === paper.pid) {
    expandedPid.value = ''
    questions.value = []
    return
  }
  expandedPid.value = paper.pid
  questionsLoading.value = true
  try {
    const data = await request<AdminListPayload<Question>>(
      `/admin/papers/${encodeURIComponent(paper.pid)}/questions?page=1&perPage=20`
    )
    questions.value = data.items
  } catch {
    questions.value = []
  } finally {
    questionsLoading.value = false
  }
}
</script>

<template>
  <section class="admin-section">
    <div class="section-heading"><div><span class="section-kicker">只读 · 数据集内容</span><h2>真题回顾（{{ total }} 卷）</h2></div></div>
    <p class="admin-meta">真题由离线数据集导入，后台仅查看；要改内容请更新源数据并重新导入。</p>

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
              {{ expandedPid === paper.pid ? '收起题目' : '查看题目' }}
            </button>
          </td>
        </tr>
      </tbody>
    </table>

    <div v-if="expandedPid" class="admin-detail-panel">
      <p v-if="questionsLoading" class="admin-meta">题目加载中…</p>
      <table v-else class="admin-table">
        <thead><tr><th>#</th><th>模块</th><th>题干</th><th>答案</th></tr></thead>
        <tbody>
          <tr v-for="question in questions" :key="question.id">
            <td>{{ question.no }}</td>
            <td>{{ question.moduleName || question.module }}</td>
            <td class="admin-stem">{{ question.stem }}</td>
            <td>{{ question.answer ?? '—' }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <p v-if="message" class="admin-meta">{{ message }}</p>
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
</style>
