<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Search, SearchX } from 'lucide-vue-next'
import type { SearchResult } from '~/types/api'
import { withQuery } from '~/composables/useApi'
import { SEARCH_TYPES, normalizeKeyword } from '~/utils/search.mjs'

const route = useRoute()
const router = useRouter()
const keyword = ref(String(route.query.q ?? ''))
const activeType = ref(String(route.query.type ?? ''))

const TYPES = [{ value: '', label: '全部' }, ...SEARCH_TYPES]

const submitted = ref(keyword.value.trim())
const enabled = computed(() => submitted.value.length > 0)

const empty: SearchResult = { items: [], total: 0, groups: {} }

const requestPath = computed(() => withQuery('/search', { q: submitted.value, type: activeType.value, limit: 60 }))
const { data, pending, error, refresh } = await useApiFetch<SearchResult>(
  requestPath,
  empty,
)

const result = computed<SearchResult>(() => data.value ?? empty)
const items = computed(() => result.value.items ?? [])
const groups = computed(() => result.value.groups ?? {})

const typeLabel = (type: string) => TYPES.find((t) => t.value === type)?.label ?? type

useHead(() => ({
  title: submitted.value ? `${submitted.value} 的搜索结果｜观澜` : '搜索｜观澜',
}))

function submit() {
  submitted.value = normalizeKeyword(keyword.value)
  router.push({ path: '/search', query: { q: submitted.value, ...(activeType.value ? { type: activeType.value } : {}) } })
}

function pickType(value: string) {
  activeType.value = value
  router.push({ path: '/search', query: { q: submitted.value, ...(value ? { type: value } : {}) } })
}

watch(() => [route.query.q, route.query.type], () => {
  keyword.value = String(route.query.q ?? '')
  submitted.value = normalizeKeyword(route.query.q)
  activeType.value = String(route.query.type ?? '')
})
const sourceNotice = computed(() => Object.entries(result.value.sources ?? {})
  .filter(([, state]) => state !== 'available')
  .map(([type, state]) => `${typeLabel(type)}${state === 'login_required' ? '需登录后检索' : state === 'unpublished' ? '暂无已发布资料' : '暂时无法检索'}`)
  .join('；'))
const matchLabel = (location?: string) => ({ title: '标题', alias: '别名', summary: '摘要', body: '正文' }[location ?? ''] ?? '正文')
</script>

<template>
  <div class="site-shell">
    <SiteHeader />
    <main class="page-wrap inner-page">
      <Breadcrumbs />
      <section class="page-hero">
        <div class="eyebrow"><Search :size="14" />站内搜索</div>
        <h1>搜索知识点、会议、年份</h1>
        <form class="search-box" @submit.prevent="submit">
          <Search :size="19" />
          <input v-model="keyword" type="search" aria-label="搜索" placeholder="例如：遵义会议、十五五、2026" />
          <button type="submit">搜索</button>
        </form>
      </section>

      <template v-if="enabled">
        <nav class="filter-row" aria-label="结果类型">
          <button
            v-for="item in TYPES"
            :key="item.value || 'all'"
            type="button"
            class="filter-chip"
            :class="{ active: activeType === item.value }"
            @click="pickType(item.value)"
          >
            {{ item.label }}
            <span v-if="item.value && groups[item.value]" class="chip-count">{{ groups[item.value] }}</span>
          </button>
        </nav>

        <div class="section-heading">
          <div>
            <span class="section-kicker">搜索结果</span>
            <h2 v-if="!pending && !error">{{ result.total }} 条命中</h2>
          </div>
        </div>

        <p v-if="sourceNotice" role="status">{{ sourceNotice }}</p>
        <div v-if="pending" class="empty-state" role="status">检索中…</div>
        <div v-else-if="error" class="empty-state" role="alert">检索暂时失败，请稍后重试。<button type="button" @click="refresh()">重试</button></div>
        <ul v-else-if="items.length" class="result-list">
          <li v-for="(item, index) in items" :key="`${item.type}-${index}-${item.url}`">
            <span class="record-type">{{ typeLabel(item.type) }}</span>
            <NuxtLink :to="item.url" :external="item.url.startsWith('/mayuan/')" class="record-title">{{ item.title }}</NuxtLink>
            <p v-if="item.snippet" class="record-snippet">{{ item.snippet }}</p>
            <span v-if="item.meta" class="record-tag">{{ item.subject || item.meta }} · 命中{{ matchLabel(item.matchLocation) }}</span>
          </li>
        </ul>
        <div v-else class="empty-state">
          <SearchX :size="18" />
          没有找到「{{ submitted }}」相关的资料，换个关键词试试。
        </div>
        <p v-if="!pending && !error && result.truncated">已显示前 {{ items.length }} 条，共 {{ result.total }} 条。请选择类型或补充关键词缩小范围。</p>
      </template>
      <div v-else class="empty-state">输入关键词开始搜索。</div>
      <NuxtLink class="back-link" to="/">返回首页</NuxtLink>
    </main>
    <SiteFooter />
  </div>
</template>
