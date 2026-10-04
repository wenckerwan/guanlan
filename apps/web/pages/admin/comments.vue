<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import UserGroupBadge from '~/components/UserGroupBadge.vue'

type CommentUser = { id: number; name: string; role: string; userGroup: string }
type AdminComment = {
  id: number
  articleType: string
  articleSlug: string
  floor: number
  content: string
  status: string
  pinned: boolean
  parentId: number | null
  createdAt: string
  user: CommentUser
}

const { request, restore } = useAuth()
restore()

const perPage = 20
const page = ref(1)
const total = ref(0)
const items = ref<AdminComment[]>([])
const status = ref('')
const articleType = ref('')
const message = ref('')

const TYPE_LABELS: Record<string, string> = {
  analysis: '真题分析',
  prediction: '时政预测',
}

async function load() {
  message.value = ''
  try {
    const params = new URLSearchParams({ page: String(page.value), perPage: String(perPage) })
    if (status.value) params.set('status', status.value)
    if (articleType.value) params.set('articleType', articleType.value)
    const data = await request<{ items: AdminComment[]; total: number }>(`/admin/comments?${params}`)
    items.value = data.items
    total.value = data.total
  } catch (exception) {
    message.value = (exception as { data?: { message?: string } })?.data?.message || '加载失败'
  }
}

onMounted(load)

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage)))
watch(page, load)

function setStatus(value: string) {
  status.value = value
  if (page.value === 1) load()
  else page.value = 1
}

function setType(event: Event) {
  articleType.value = (event.target as HTMLSelectElement).value
  if (page.value === 1) load()
  else page.value = 1
}

async function act(row: AdminComment, action: string) {
  message.value = ''
  try {
    await request(`/admin/comments/${row.id}`, { method: 'PATCH', body: { action } })
    await load()
  } catch (exception) {
    message.value = (exception as { data?: { message?: string } })?.data?.message || '操作失败'
  }
}

async function remove(row: AdminComment) {
  if (!window.confirm('确定删除这条评论？回复也会一并删除。')) return
  message.value = ''
  try {
    await request(`/admin/comments/${row.id}`, { method: 'DELETE' })
    await load()
  } catch (exception) {
    message.value = (exception as { data?: { message?: string } })?.data?.message || '删除失败'
  }
}
</script>

<template>
  <section class="admin-section">
    <div class="section-heading"><div><span class="section-kicker">评论管理</span><h2>共 {{ total }} 条评论</h2></div></div>

    <div class="comment-toolbar">
      <nav class="filter-chips">
        <button type="button" class="chip" :class="{ active: status === '' }" @click="setStatus('')">全部</button>
        <button type="button" class="chip" :class="{ active: status === 'pending' }" @click="setStatus('pending')">待审核</button>
        <button type="button" class="chip" :class="{ active: status === 'approved' }" @click="setStatus('approved')">已通过</button>
      </nav>
      <select class="admin-select" :value="articleType" @change="setType">
        <option value="">全部栏目</option>
        <option value="analysis">真题分析</option>
        <option value="prediction">时政预测</option>
      </select>
    </div>

    <p v-if="message" class="admin-meta">{{ message }}</p>

    <table class="admin-table">
      <thead><tr><th>时间</th><th>用户</th><th>栏目/文章</th><th>内容</th><th>状态</th><th>操作</th></tr></thead>
      <tbody>
        <tr v-for="row in items" :key="row.id">
          <td class="admin-meta">{{ row.createdAt.replace('T', ' ').slice(0, 16) }}</td>
          <td class="comment-user">
            <strong>{{ row.user.name }}</strong>
            <UserGroupBadge :group="row.user.userGroup" :role="row.user.role" />
          </td>
          <td class="admin-meta">
            {{ TYPE_LABELS[row.articleType] ?? row.articleType }}<br />
            <small>{{ row.articleSlug }}{{ row.parentId ? ` · 回复#${row.parentId}` : ` · #${row.floor}` }}</small>
          </td>
          <td class="comment-content-cell">{{ row.content }}</td>
          <td>
            <span class="status-chip" :class="row.status === 'pending' ? 'warn' : 'ok'">
              {{ row.status === 'pending' ? '待审核' : '已通过' }}
            </span>
            <span v-if="row.pinned" class="status-chip ok">置顶</span>
          </td>
          <td class="comment-actions-cell">
            <button v-if="row.status === 'pending'" class="ghost-button small" type="button" @click="act(row, 'approve')">通过</button>
            <button v-if="!row.parentId && !row.pinned" class="ghost-button small" type="button" @click="act(row, 'pin')">置顶</button>
            <button v-else-if="!row.parentId && row.pinned" class="ghost-button small" type="button" @click="act(row, 'unpin')">取消置顶</button>
            <button class="ghost-button small danger" type="button" @click="remove(row)">删除</button>
          </td>
        </tr>
      </tbody>
    </table>
    <div v-if="!items.length" class="empty-state">没有匹配的评论。</div>

    <nav v-if="totalPages > 1" class="pager">
      <button class="ghost-button small" :disabled="page <= 1" @click="page -= 1">上一页</button>
      <span class="admin-meta">{{ page }} / {{ totalPages }}</span>
      <button class="ghost-button small" :disabled="page >= totalPages" @click="page += 1">下一页</button>
    </nav>
  </section>
</template>

<style scoped>
.pager { display: flex; align-items: center; gap: 12px; justify-content: center; margin-top: 12px; }

.comment-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 12px;
  flex-wrap: wrap;
}

.admin-select {
  padding: 6px 10px;
  border: 1px solid var(--border);
  border-radius: 6px;
  background: var(--card-bg);
  color: var(--text-primary);
}

.comment-user strong {
  display: block;
  font-size: 0.875rem;
}

.comment-content-cell {
  max-width: 320px;
  white-space: pre-wrap;
  word-break: break-word;
  font-size: 0.875rem;
}

.comment-actions-cell {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.status-chip {
  display: inline-block;
  margin-right: 4px;
  padding: 1px 8px;
  border-radius: 999px;
  font-size: 0.75rem;
}

.status-chip.ok { background: #ecfdf5; color: #059669; }
.status-chip.warn { background: #fffbeb; color: #b45309; }

.ghost-button.danger { color: var(--error, #dc2626); }
</style>
