<script setup lang="ts">
import type { AdminAttempt, AdminListPayload } from '~/types/api'

definePageMeta({ layout: 'admin' })
useHead({ title: '作答记录｜观澜管理后台' })
const route = useRoute()
const router = useRouter()
const { request, restore } = useAuth()
const data = ref<AdminListPayload<AdminAttempt> | null>(null)
const loading = ref(false)
const error = ref('')
const source = ref('')
let generation = 0
const page = computed(() => Math.max(1, Number(route.query.page) || 1))
const sourceNames: Record<string, string> = { paper: '真题', mock: '模拟卷', mistake: '错题', question: '单题', practice: '时政练习' }

async function load() {
  const current = ++generation
  loading.value = true
  error.value = ''
  try {
    await restore()
    const params = new URLSearchParams({ page: String(page.value), perPage: '20' })
    if (typeof route.query.source === 'string' && route.query.source) params.set('source', route.query.source)
    const result = await request<AdminListPayload<AdminAttempt>>(`/admin/attempts?${params}`)
    if (current === generation) data.value = result
  } catch (cause) {
    if (current === generation) error.value = (cause as Error).message || '作答记录加载失败'
  } finally {
    if (current === generation) loading.value = false
  }
}
function apply() { router.push({ query: { source: source.value || undefined, page: undefined } }) }
function atBeijing(value: string) {
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat('zh-CN', { timeZone: 'Asia/Shanghai', dateStyle: 'medium', timeStyle: 'medium' }).format(date)
}
onMounted(() => { source.value = String(route.query.source ?? ''); load() })
watch(() => [route.query.source, route.query.page], () => { source.value = String(route.query.source ?? ''); load() })
onBeforeUnmount(() => { generation++ })
</script>

<template>
  <section class="admin-section" :aria-busy="loading">
    <div class="section-heading"><div><span class="section-kicker">学习记录</span><h1>作答记录</h1></div></div>
    <form class="attempt-filters" @submit.prevent="apply">
      <label>来源 <select v-model="source"><option value="">全部</option><option value="practice">时政练习</option><option value="paper">真题</option><option value="mock">模拟卷</option><option value="mistake">错题</option><option value="question">单题</option></select></label>
      <button class="ghost-button" type="submit">筛选</button>
    </form>
    <p v-if="loading" role="status">正在加载作答记录…</p>
    <p v-else-if="error" role="alert">{{ error }} <button class="ghost-button" @click="load">重试</button></p>
    <template v-else-if="data">
      <p class="admin-meta">共 {{ data.total }} 次有效提交。答案为提交当时保存的快照；时间按北京时间显示。</p>
      <div class="table-scroll"><table class="admin-table"><thead><tr><th>时间</th><th>用户</th><th>来源</th><th>题目</th><th>模块</th><th>所选</th><th>标准答案</th><th>结果</th></tr></thead><tbody>
        <tr v-for="item in data.items" :key="item.id"><td>{{ atBeijing(item.createdAt) }}</td><td>{{ item.userName || item.userEmail || `#${item.userId}` }}<small v-if="item.userName && item.userEmail">{{ item.userEmail }}</small></td><td>{{ sourceNames[item.source] ?? item.source }}<small>{{ item.sourceRef }}</small></td><td>{{ item.questionRef }}</td><td>{{ item.module }}</td><td>{{ item.chosen }}</td><td>{{ item.correct }}</td><td>{{ item.isRight ? '正确' : '错误' }}</td></tr>
      </tbody></table></div>
      <p v-if="!data.items.length" class="empty-state">没有符合条件的作答记录。</p>
      <nav class="attempt-pages" aria-label="作答记录分页"><NuxtLink v-if="data.page > 1" :to="{ query: { source: route.query.source || undefined, page: data.page - 1 } }">上一页</NuxtLink><span>第 {{ data.page }} 页</span><NuxtLink v-if="data.page * data.perPage < data.total" :to="{ query: { source: route.query.source || undefined, page: data.page + 1 } }">下一页</NuxtLink></nav>
    </template>
  </section>
</template>

<style scoped>
.attempt-filters,.attempt-pages{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:18px 0}.attempt-filters select{min-height:40px;margin-left:8px;padding:6px 10px;border:1px solid var(--line);border-radius:8px;background:var(--surface);color:inherit}.table-scroll{overflow-x:auto}.admin-table{min-width:760px}.admin-table small{display:block;overflow-wrap:anywhere}.attempt-pages a{padding:6px 10px;border:1px solid var(--line);border-radius:8px}
</style>
