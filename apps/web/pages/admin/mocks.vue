<script setup lang="ts">
definePageMeta({ layout: 'admin' })
import type { Mock, MockQuestion } from '~/types/api'
const { items: mocks, total, filters, loading, loaded, loadError, visible, load, go } = useAdminList<Mock>('/admin/mocks')
const route = useRoute(), router = useRouter(), { request, restore } = useAuth()
type Detail = { mock: Mock & { sourceFile: string }; questions: { items: MockQuestion[]; total: number; page: number; perPage: number } }
const selectedSlug = computed(() => typeof route.query.mock === 'string' ? route.query.mock : '')
const questionPage = computed(() => { const value = Number(route.query.mockPage); return Number.isSafeInteger(value) && value > 0 ? value : 1 })
const detail = ref<Detail | null>(null), detailLoading = ref(false), detailError = ref('')
let generation = 0, started = false, alive = true
async function loadDetail() {
  const version = ++generation, slug = selectedSlug.value, page = questionPage.value
  detail.value = null; detailError.value = ''; detailLoading.value = !!slug
  if (!slug) return
  try {
    const result = await request<Detail>(`/admin/mocks/${encodeURIComponent(slug)}?page=${page}&perPage=20`)
    if (!alive || version !== generation || selectedSlug.value !== slug) return
    if (!result?.mock || !Array.isArray(result.questions?.items) || !Number.isInteger(result.questions.total) || result.questions.total < 0 || !Number.isInteger(result.questions.page) || result.questions.page < 1 || !Number.isInteger(result.questions.perPage) || result.questions.perPage < 1) throw new Error('Invalid mock response')
    detail.value = result
    if (result.questions.page !== page) await router.replace({ query: { ...route.query, mockPage: result.questions.page === 1 ? undefined : String(result.questions.page) } })
  } catch (exception) { if (alive && version === generation) detailError.value = (exception as { data?: { message?: string } }).data?.message || '模拟卷详情加载失败，请重试。' }
  finally { if (alive && version === generation) detailLoading.value = false }
}
async function choose(slug: string) {
  if (selectedSlug.value === slug && questionPage.value === 1) return loadDetail()
  await router.push({ query: { ...route.query, mock: slug, mockPage: undefined } })
}
const turnQuestionPage = (page: number) => router.push({ query: { ...route.query, mockPage: page === 1 ? undefined : String(page) } })
const closeDetail = () => router.push({ query: { ...route.query, mock: undefined, mockPage: undefined } })
watch(() => [selectedSlug.value, questionPage.value], () => { generation++; if (started) void loadDetail() }, { flush: 'sync' })
onMounted(async () => { await restore(); if (alive) { started = true; await loadDetail() } })
onBeforeUnmount(() => { alive = false; started = false; generation++ })
</script>

<template>
  <section class="admin-section">
    <div class="mock-list">
      <div class="section-heading"><div><span class="section-kicker">只读 · 数据集内容</span><h2>模拟押题<span v-if="visible">（{{ total }} 套）</span></h2></div></div>
      <p class="admin-meta">模拟卷由离线数据集导入，后台仅查看。修改内容前请先比较源数据与当前内容、确认差异，并保留既有导入保护。</p>
      <AdminListState :loading="loading" :error="loadError" :empty="loaded && !mocks.length" @retry="load">暂无模拟卷。</AdminListState>
      <div v-if="visible && mocks.length" class="table-scroll" tabindex="0" role="region" aria-label="模拟卷列表，可横向滚动">
        <table class="admin-table"><thead><tr><th scope="col">标题</th><th scope="col">题数</th><th scope="col">满分</th><th scope="col">时长（分钟）</th><th scope="col">操作</th></tr></thead><tbody><tr v-for="mock in mocks" :key="mock.slug"><td>{{ mock.title }}</td><td>{{ mock.questionCount }}</td><td>{{ mock.totalScore }}</td><td>{{ mock.durationMinutes }}</td><td><button class="ghost-button small" @click="choose(mock.slug)">查看题目</button></td></tr></tbody></table>
      </div>
      <AdminPagination v-if="visible" :page="filters.page" :per-page="filters.perPage" :total="total" :busy="loading" @change="go" />
    </div>
    <section v-if="selectedSlug" class="mock-detail" :aria-busy="detailLoading">
      <div class="detail-actions"><button class="ghost-button" :disabled="detailLoading" @click="loadDetail">刷新详情</button><button class="ghost-button" @click="closeDetail">关闭详情</button></div>
      <p v-if="detailLoading" role="status">正在加载模拟卷 {{ selectedSlug }}…</p>
      <div v-else-if="detailError" role="alert"><p>{{ detailError }}</p><button class="ghost-button" @click="loadDetail">重试详情</button></div>
      <template v-else-if="detail">
        <h3>模拟卷详情：{{ detail.mock.title }}</h3><p class="raw-text">{{ detail.mock.summary }}</p>
        <p class="admin-meta">来源文件：{{ detail.mock.sourceFile || '未记录' }} · 共 {{ detail.questions.total }} 道题 · 只读</p>
        <p v-if="!detail.questions.items.length" class="empty-state">此模拟卷暂无题目。</p>
        <article v-for="question in detail.questions.items" :key="question.no" class="mock-question">
          <h4>第 {{ question.no }} 题 · {{ question.typeCn }} · {{ question.score }} 分</h4><p class="admin-meta">{{ question.moduleName }} · {{ question.kaodian }}</p>
          <p class="raw-text">{{ question.stem }}</p><ul class="options"><li v-for="(option, letter) in question.options" :key="letter" class="raw-text">{{ letter }}. {{ option }}</li></ul>
          <p class="raw-text"><strong>答案：</strong>{{ question.answer ?? '未记录' }}</p><h5>解析</h5><p class="raw-text">{{ question.analysis || '未记录' }}</p>
        </article>
        <AdminPagination :page="detail.questions.page" :per-page="detail.questions.perPage" :total="detail.questions.total" :busy="detailLoading" @change="turnQuestionPage" />
      </template>
    </section>
  </section>
</template>

<style scoped>
.mock-list { min-width: 0; }
.table-scroll { max-width: 100%; overflow-x: auto; }
.admin-table { min-width: 580px; }
.mock-detail { margin-top: 24px; padding: 20px; border: 1px solid var(--border, #ddd); border-radius: 12px; min-width: 0; }
.detail-actions { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; }
.mock-question { padding: 16px 0; border-top: 1px solid var(--border, #ddd); }
.raw-text { white-space: pre-wrap; overflow-wrap: anywhere; }
.options { list-style: none; padding-left: 0; }
</style>
