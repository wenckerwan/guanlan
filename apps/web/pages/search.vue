<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Search, SearchX } from 'lucide-vue-next'
import type { SearchResult } from '~/types/api'
import { withQuery } from '~/composables/useApi'

const route = useRoute()
const router = useRouter()
const keyword = ref(String(route.query.q ?? ''))
const activeType = ref(String(route.query.type ?? ''))

const TYPES = [
  { value: '', label: '全部' },
  { value: 'question', label: '真题' },
  { value: 'paper', label: '试卷' },
  { value: 'analysis', label: '真题分析' },
  { value: 'prediction', label: '时政预测' },
  { value: 'mock', label: '模拟押题' },
  { value: 'mistake', label: '错题' },
] as const

const submitted = ref(keyword.value.trim())
const enabled = computed(() => submitted.value.length > 0)

const empty: SearchResult = { items: [], total: 0, groups: {} }

const { data, pending } = await useApiFetch<SearchResult>(
  withQuery('/search', { q: submitted.value, type: activeType.value, limit: 60 }),
  empty,
  { watch: [submitted, activeType], immediate: enabled.value },
)

const result = computed<SearchResult>(() => data.value ?? empty)
const items = computed(() => result.value.items ?? [])
const groups = computed(() => result.value.groups ?? {})

const typeLabel = (type: string) => TYPES.find((t) => t.value === type)?.label ?? type

useHead(() => ({
  title: submitted.value ? `${submitted.value} 的搜索结果｜观澜` : '搜索｜观澜',
}))

function submit() {
  submitted.value = keyword.value.trim()
  router.replace({ path: '/search', query: { q: submitted.value, ...(activeType.value ? { type: activeType.value } : {}) } })
}

function pickType(value: string) {
  activeType.value = value
  router.replace({ path: '/search', query: { q: submitted.value, ...(value ? { type: value } : {}) } })
}

watch(() => route.query.q, (next) => {
  const value = String(next ?? '')
  if (value !== submitted.value) {
    keyword.value = value
    submitted.value = value.trim()
  }
})
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
            <h2>{{ items.length }} 条命中<span v-if="pending">（检索中…）</span></h2>
          </div>
        </div>

        <ul v-if="items.length" class="result-list">
          <li v-for="(item, index) in items" :key="`${item.type}-${index}-${item.url}`">
            <span class="record-type">{{ typeLabel(item.type) }}</span>
            <NuxtLink :to="item.url" class="record-title">{{ item.title }}</NuxtLink>
            <p v-if="item.snippet" class="record-snippet">{{ item.snippet }}</p>
            <span v-if="item.meta" class="record-tag">{{ item.meta }}</span>
          </li>
        </ul>
        <div v-else class="empty-state">
          <SearchX :size="18" />
          没有找到「{{ submitted }}」相关的资料，换个关键词试试。
        </div>
      </template>
      <div v-else class="empty-state">输入关键词开始搜索。</div>
      <NuxtLink class="back-link" to="/">返回首页</NuxtLink>
    </main>
    <SiteFooter />
  </div>
</template>