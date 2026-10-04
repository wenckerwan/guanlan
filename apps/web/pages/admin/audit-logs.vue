<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'

type AuditLog = {
  id: number
  adminId: number
  adminEmail: string
  action: string
  targetType: string
  targetId: string
  detail: Record<string, unknown> | null
  createdAt: string
}

const { request, restore } = useAuth()
restore()

const perPage = 20
const page = ref(1)
const total = ref(0)
const items = ref<AuditLog[]>([])
const action = ref('')
const message = ref('')

async function load() {
  try {
    const params = new URLSearchParams({ page: String(page.value), perPage: String(perPage) })
    if (action.value.trim()) params.set('action', action.value.trim())
    const data = await request<{ items: AuditLog[]; total: number }>(`/admin/audit-logs?${params}`)
    items.value = data.items
    total.value = data.total
  } catch (exception) {
    message.value = (exception as { data?: { message?: string } })?.data?.message || '加载失败'
  }
}

onMounted(load)

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage)))
watch(page, load)

function detailText(log: AuditLog): string {
  if (!log.detail || !Object.keys(log.detail).length) return ''
  return Object.entries(log.detail)
    .filter(([, v]) => v !== null && v !== '')
    .map(([k, v]) => `${k}=${String(v).slice(0, 40)}`)
    .join('　')
}

const actionLabels: Record<string, string> = {
  'user.create': '创建用户',
  'user.update': '更新用户',
  'user.reset_password': '重置密码',
  'hotspot.create': '新建热点',
  'hotspot.update': '更新热点',
  'hotspot.delete': '删除热点',
  'analysis.create': '新建分析',
  'analysis.update': '更新分析',
  'analysis.delete': '删除分析',
  'paper.create': '新建试卷',
  'paper.update': '更新试卷',
  'paper.delete': '删除试卷',
  'question.update': '编辑题目',
  'mistake.profile.replace': '替换错题画像',
  'mistake.item.update': '更新错题',
}
</script>

<template>
  <section class="admin-section">
    <div class="section-heading"><div><span class="section-kicker">审计日志</span><h2>共 {{ total }} 条操作记录</h2></div></div>

    <form class="filter-search" role="search" @submit.prevent="page = 1; load()">
      <input v-model="action" type="search" placeholder="按操作前缀筛选，如 user. / hotspot. / analysis." />
      <button class="ghost-button" type="submit">筛选</button>
    </form>

    <p v-if="message" class="admin-meta">{{ message }}</p>

    <table class="admin-table">
      <thead><tr><th>时间</th><th>操作人</th><th>操作</th><th>对象</th><th>详情</th></tr></thead>
      <tbody>
        <tr v-for="log in items" :key="log.id">
          <td class="admin-meta">{{ log.createdAt.replace('T', ' ').slice(0, 19) }}</td>
          <td>{{ log.adminEmail || `#${log.adminId}` }}</td>
          <td>{{ actionLabels[log.action] ?? log.action }}</td>
          <td class="admin-meta">{{ log.targetType }}#{{ log.targetId }}</td>
          <td class="admin-meta">{{ detailText(log) }}</td>
        </tr>
      </tbody>
    </table>
    <div v-if="!items.length" class="empty-state">没有匹配的记录。</div>

    <nav v-if="totalPages > 1" class="pager">
      <button class="ghost-button small" :disabled="page <= 1" @click="page -= 1">上一页</button>
      <span class="admin-meta">{{ page }} / {{ totalPages }}</span>
      <button class="ghost-button small" :disabled="page >= totalPages" @click="page += 1">下一页</button>
    </nav>
  </section>
</template>

<style scoped>
.pager { display: flex; align-items: center; gap: 12px; justify-content: center; margin-top: 12px; }
</style>
