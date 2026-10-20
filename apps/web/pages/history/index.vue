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
  meta: [{ name: 'description', content: '1839—2026 近现代史交互时间轴：时间探索、事件对照与同期观察。' }],
})
// 嵌入组件独立挂载、档案馆视觉令牌独立于观澜全局样式。
definePageMeta({ layout: false })

const config = useRuntimeConfig()
const route = useRoute()
const apiBase = (config.public.apiBase as string) || '/api/v1'

const container = ref<HTMLElement | null>(null)
const mountError = ref('')
const legacyMessage = ref('')
const auth = useAuth()
let app: { unmount: () => void } | null = null
let studySession: { dispose: () => void } | null = null
let generation = 0
let disposed = false

function stopHistory() {
  studySession?.dispose()
  studySession = null
  app?.unmount()
  app = null
}

function syncCookie() {
  const current = readAuthToken()
  if (auth.token.value !== current) {
    stopHistory()
    auth.user.value = null
    auth.token.value = current
  }
}

async function exportLegacy() {
  try {
    const { exportLegacyHistory } = await import('~/components/history/adapters/guanlan-study')
    const text = await exportLegacyHistory()
    if (!text) { legacyMessage.value = '没有找到旧版本地记录。'; return }
    const url = URL.createObjectURL(new Blob([text], { type: 'application/json' }))
    const link = document.createElement('a')
    link.href = url
    link.download = '史纲旧版本地记录.json'
    link.click()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
    legacyMessage.value = '已导出。确认记录属于自己后，可在时间轴中导入；原始记录仍保留。'
  } catch { legacyMessage.value = '旧记录暂时无法读取，原始数据仍保留，请重试。' }
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

async function mountHistory() {
  const target = container.value
  if (!target || disposed) return
  const currentGeneration = ++generation
  stopHistory()
  const sessionToken = readAuthToken()
  const isCurrent = () => !disposed && currentGeneration === generation && readAuthToken() === sessionToken
  mountError.value = ''
  try {
  if (auth.token.value !== sessionToken) { auth.user.value = null; auth.token.value = sessionToken }
  // 缓存资料不能决定分库归属，必须先向服务端确认当前账号。
  const verifiedUser = sessionToken ? await auth.fetchUser() : null
  if (!isCurrent()) return
  if (sessionToken && !verifiedUser) throw new Error('暂时无法确认登录身份，请重试；本地记录保持原样')
  const [{ createGuanlanHistoryApp }, { GuanlanHistoryRepository }, { GuanlanStudyRepository }] = await Promise.all([
    import('~/components/history/ui/guanlan-app'),
    import('~/components/history/adapters/history-http'),
    import('~/components/history/adapters/guanlan-study'),
  ])
  if (!isCurrent() || container.value !== target) return
  const authHeaders = (): HeadersInit => sessionToken ? { Authorization: `Bearer ${sessionToken}` } : {}
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
    accountId: verifiedUser?.id ?? null,
    isCurrent,
    onAuthRequired: () => {
      if (isCurrent()) void navigateTo({ path: '/login', query: { redirect: route.fullPath } })
    },
  })
  studySession = studyRepository
  app = createGuanlanHistoryApp({
    mount: target,
    repository,
    studyRepository,
    route: routeAdapter,
    basePath: '/history/',
  })
  } catch (error) {
    if (isCurrent()) mountError.value = error instanceof Error ? error.message : '时间轴初始化失败'
  }
}

// ClientOnly creates its slot after the parent has mounted; wait for the actual element.
watch(container, () => { void mountHistory() }, { flush: 'post' })
watch(auth.token, () => { void mountHistory() }, { flush: 'post' })
onMounted(() => {
  window.addEventListener('focus', syncCookie)
  window.addEventListener('pageshow', syncCookie)
  window.addEventListener('storage', syncCookie)
  document.addEventListener('visibilitychange', syncCookie)
  void mountHistory()
})

onBeforeUnmount(() => {
  disposed = true
  ++generation
  stopHistory()
  window.removeEventListener('focus', syncCookie)
  window.removeEventListener('pageshow', syncCookie)
  window.removeEventListener('storage', syncCookie)
  document.removeEventListener('visibilitychange', syncCookie)
})
</script>

<template>
  <div class="history-host">
    <a class="history-host__skip" href="/">返回观澜首页</a>
    <ClientOnly>
      <details class="history-host__legacy">
        <summary>旧版本地记录恢复</summary>
        <p>旧记录不会自动并入当前账号。仅在确认属于自己时，导出后通过时间轴导入备份。</p>
        <button type="button" @click="exportLegacy">导出旧记录</button>
        <p v-if="legacyMessage" role="status">{{ legacyMessage }}</p>
      </details>
      <div ref="container" class="history-host__mount" />
      <div v-if="mountError" class="history-host__loading" role="alert">
        <p>时间轴暂时无法打开：{{ mountError }}</p>
        <button type="button" @click="mountHistory">重新加载</button>
      </div>
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
.history-host__legacy { align-self: flex-end; margin: 12px 16px 0; max-width: min(460px, 80vw); font-size: 13px; color: #6b6455; }
.history-host__loading { flex: 1; display: flex; align-items: center; justify-content: center; gap: 8px; color: #6b6455; }
</style>
