<script setup lang="ts">
import { History as HistoryIcon } from 'lucide-vue-next'

/**
 * 史纲时间轴（近现代史时间实验室）嵌入承载页。
 * 组件本体与视觉令牌独立（components/history/），此处只提供容器、注入观澜仓库与 Nuxt 路由薄封装。
 * 数据集：GET /api/v1/history/events（{data: HistoryDataset}，公开只读）。
 * 学习：观澜 /api/v1/study/*（history_event / history_comparison，未登录只读、写操作跳登录）。
 */
useHead({
  title: '史纲时间轴｜观澜考研政治知识库',
  meta: [{ name: 'description', content: '1839—2009 近现代史交互时间轴：双来源日期对照、同期观察与主动回忆。' }],
})
// 嵌入组件独立挂载、档案馆视觉令牌独立于观澜全局样式。
definePageMeta({ layout: false })

const config = useRuntimeConfig()
const route = useRoute()
const apiBase = (config.public.apiBase as string) || '/api/v1'

// 同一 key 强制重挂载：Nuxt watch route.query 变化时重建组件，挂载时经 parse() 自恢复（前进/回退由 Nuxt 路由负责）。
const historyKey = computed(() => `history:${route.fullPath}`)

const container = ref<HTMLElement | null>(null)
let app: { unmount: () => void } | null = null

function authHeaders(): HeadersInit {
  const token = readAuthToken()
  return token ? { Authorization: `Bearer ${token}` } : {}
}

function resolveSourceUrl(sourceId: string, page?: number | null): string | null {
  if (!sourceId) return null
  return `/documents/${sourceId}.pdf${page == null ? '' : `#page=${page}`}`
}

// Nuxt 路由薄封装：query 用 getter 保证 parse() 每次读到最新值；push/replace 写回 query。
const routeAdapter = {
  get query() {
    return route.query as Record<string, unknown>
  },
  push(q: Record<string, string>) {
    void navigateTo({ path: route.path, query: { ...route.query, ...q } }, { replace: false })
  },
  replace(q: Record<string, string>) {
    void navigateTo({ path: route.path, query: { ...route.query, ...q } }, { replace: true })
  },
}

onMounted(async () => {
  if (!container.value) return
  const [{ createGuanlanHistoryApp }, { GuanlanHistoryRepository }, { GuanlanStudyRepository }] = await Promise.all([
    import('~/components/history/ui/guanlan-app'),
    import('~/components/history/adapters/history-http'),
    import('~/components/history/adapters/guanlan-study'),
  ])
  // history-events 端点含 apiBase 前缀；study 路径在上游已写死 /api/v1/study/*，故其 apiBase 传空。
  const repository = new GuanlanHistoryRepository({
    fetch: globalThis.fetch.bind(globalThis),
    contentEndpoint: `${apiBase}/history/events`,
    authHeaders,
    resolveSourceUrl,
  })
  const studyRepository = new GuanlanStudyRepository({
    fetch: globalThis.fetch.bind(globalThis),
    apiBase: '',
    authHeaders,
    onAuthRequired: () => {
      void navigateTo({ path: '/login', query: { redirect: route.fullPath } })
    },
  })
  app = createGuanlanHistoryApp({
    mount: container.value,
    repository,
    studyRepository,
    route: routeAdapter,
    basePath: '/history/',
  })
})

onBeforeUnmount(() => {
  app?.unmount()
  app = null
})
</script>

<template>
  <div class="history-host">
    <a class="history-host__skip" href="/">返回观澜首页</a>
    <ClientOnly>
      <div :key="historyKey" ref="container" class="history-host__mount" />
      <template #fallback>
        <div class="history-host__loading">
          <HistoryIcon :size="18" /> 正在载入史纲时间轴…
        </div>
      </template>
    </ClientOnly>
  </div>
</template>

<style scoped>
.history-host { min-height: 100vh; display: flex; flex-direction: column; background: #f3f0e8; }
.history-host__skip { position: absolute; left: 12px; top: 12px; z-index: 10; font-size: 13px; color: #6b6455; text-decoration: none; }
.history-host__skip:hover { text-decoration: underline; }
.history-host__mount { flex: 1; min-height: 0; }
.history-host__loading { flex: 1; display: flex; align-items: center; justify-content: center; gap: 8px; color: #6b6455; }
</style>
