<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { ArrowLeft, ChevronRight, CircleAlert, LoaderCircle, RotateCw, Search, SearchX } from 'lucide-vue-next'
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
    <main class="page-wrap inner-page search-page">
      <Breadcrumbs />
      <section class="search-intro">
        <h1>站内搜索</h1>
        <form class="search-box" role="search" aria-label="资料搜索" @submit.prevent="submit">
          <Search :size="19" aria-hidden="true" />
          <input v-model="keyword" type="search" aria-label="搜索资料" placeholder="搜索知识点、会议或年份" />
          <button type="submit"><Search :size="16" aria-hidden="true" />搜索</button>
        </form>
      </section>

      <template v-if="enabled">
        <div class="filter-row" role="group" aria-label="结果类型">
          <button
            v-for="item in TYPES"
            :key="item.value || 'all'"
            type="button"
            class="filter-chip"
            :class="{ active: activeType === item.value }"
            :aria-pressed="activeType === item.value"
            @click="pickType(item.value)"
          >
            {{ item.label }}
            <span v-if="!pending && !error && item.value && groups[item.value]" class="chip-count">{{ groups[item.value] }}</span>
          </button>
        </div>

        <section class="search-results" aria-labelledby="search-results-title">
          <div class="search-results-heading">
            <h2 id="search-results-title">搜索结果</h2>
            <p v-if="!pending && !error" role="status">{{ result.total }} 条命中</p>
          </div>

          <p v-if="!pending && !error && sourceNotice" class="search-notice" role="status">{{ sourceNotice }}</p>
          <div v-if="pending" class="search-state" role="status"><LoaderCircle :size="20" aria-hidden="true" />检索中…</div>
          <div v-else-if="error" class="search-state">
            <p role="alert"><CircleAlert :size="20" aria-hidden="true" />检索暂时失败。</p>
            <button class="ghost-button" type="button" @click="refresh()"><RotateCw :size="16" aria-hidden="true" />重试</button>
          </div>
          <ul v-else-if="items.length" class="search-result-list">
            <li v-for="(item, index) in items" :key="`${item.type}-${index}-${item.url}`">
              <NuxtLink :to="item.url" :external="item.url.startsWith('/mayuan/')" class="search-result-link">
                <div class="search-result-copy">
                  <div class="search-result-meta">
                    <span class="record-type">{{ typeLabel(item.type) }}</span>
                    <span v-if="item.subject || item.meta">{{ item.subject || item.meta }}</span>
                    <span v-if="item.matchLocation">命中{{ matchLabel(item.matchLocation) }}</span>
                  </div>
                  <h3>{{ item.title }}</h3>
                  <p v-if="item.snippet">{{ item.snippet }}</p>
                </div>
                <ChevronRight :size="17" aria-hidden="true" />
              </NuxtLink>
            </li>
          </ul>
          <div v-else class="search-state" role="status">
            <SearchX :size="20" aria-hidden="true" />
            <span>没有找到「{{ submitted }}」相关的资料。</span>
          </div>
          <p v-if="!pending && !error && result.truncated" class="search-notice">已显示前 {{ items.length }} 条，共 {{ result.total }} 条。</p>
        </section>
      </template>
      <NuxtLink class="back-link" to="/"><ArrowLeft :size="15" aria-hidden="true" />返回首页</NuxtLink>
    </main>
    <SiteFooter />
  </div>
</template>

<style scoped>
.search-page { max-width: 960px; }
.search-intro { margin: 22px 0 28px; }
.search-intro h1 { margin: 0; font-size: 28px; font-weight: 650; }
.search-intro .search-box { margin-top: 14px; }
.search-page .filter-row { margin-bottom: 24px; }
.search-page .filter-chip { min-height: 44px; background: var(--gl-surface); }
.search-page .filter-chip.active { background: var(--brand-soft); font-weight: 650; }
.search-page .chip-count { background: var(--gl-secondary); color: var(--muted); }
.search-results-heading { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
.search-results-heading h2 { margin: 0; font-size: 18px; font-weight: 650; }
.search-results-heading p, .search-notice { margin: 0; color: var(--muted); font-size: 13px; line-height: 1.6; }
.search-notice { margin-block: 12px; }
.search-result-list { list-style: none; margin: 0; padding: 0; border-block: 1px solid var(--line); }
.search-result-list li + li { border-top: 1px solid var(--line); }
.search-result-link { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; gap: 14px; min-height: 44px; padding: 16px 12px; background: var(--gl-surface); }
.search-result-link:hover h3, .search-result-link:focus-visible h3 { color: var(--brand); text-decoration: underline; }
.search-result-link > svg { color: var(--muted); }
.search-result-copy { overflow-wrap: anywhere; }
.search-result-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 10px; color: var(--muted); font-size: 12px; }
.search-result-link h3 { margin: 6px 0 0; font-size: 16px; line-height: 1.45; }
.search-result-link p { margin: 6px 0 0; color: var(--muted); font-size: 14px; line-height: 1.65; }
.search-state { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; min-height: 76px; padding: 14px 12px; border-block: 1px solid var(--line); color: var(--muted); overflow-wrap: anywhere; }
.search-state p { display: flex; align-items: center; gap: 10px; margin: 0; }
.search-state .ghost-button { min-height: 44px; }
@media (max-width: 480px) {
  .search-intro h1 { font-size: 24px; }
  .search-intro .search-box { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; gap: 8px; padding: 8px 8px 8px 12px; }
  .search-intro .search-box input { height: 44px; }
}
</style>
