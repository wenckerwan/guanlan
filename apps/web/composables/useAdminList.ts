import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import type { AdminListPayload } from '~/types/api'

type ListFilters = { q: string; status: string; period: string; category: string; page: number; perPage: number }
type FilterOptions = { periods: string[]; categories: string[] }

/** URL is the committed list state; draft filters only apply on submit. */
export function useAdminList<T>(endpoint: string, keys: ('q' | 'status' | 'period' | 'category')[] = []) {
  const { request, restore } = useAuth()
  const route = useRoute()
  const router = useRouter()
  const scalar = (value: unknown) => typeof value === 'string' ? value : ''
  const positive = (value: unknown, fallback: number, max = Number.MAX_SAFE_INTEGER) => {
    const number = Number(scalar(value))
    return Number.isInteger(number) && number > 0 ? Math.min(number, max) : fallback
  }
  const filters = computed<ListFilters>(() => ({
    q: keys.includes('q') ? scalar(route.query.q) : '',
    status: keys.includes('status') && ['published', 'hidden'].includes(scalar(route.query.status)) ? scalar(route.query.status) : '',
    period: keys.includes('period') ? scalar(route.query.period) : '',
    category: keys.includes('category') ? scalar(route.query.category) : '',
    page: positive(route.query.page, 1), perPage: positive(route.query.perPage, 20, 100),
  }))
  const draft = reactive({ ...filters.value })
  const sizes = computed(() => [...new Set([20, 50, 100, draft.perPage])].sort((a, b) => a - b))
  const items = ref<T[]>([])
  const total = ref(0)
  const options = ref<FilterOptions>({ periods: [], categories: [] })
  const loading = ref(false), loaded = ref(false), loadError = ref('')
  const visible = computed(() => loaded.value && !loading.value && !loadError.value)
  let sequence = 0, started = false, destroyed = false

  function query(selected: ListFilters) {
    const result: Record<string, string> = {}
    for (const key of keys) if (selected[key]) result[key] = selected[key]
    if (selected.page !== 1) result.page = String(selected.page)
    if (selected.perPage !== 20) result.perPage = String(selected.perPage)
    return result
  }
  async function load() {
    const version = ++sequence, selected = { ...filters.value }
    loading.value = true; loaded.value = false; loadError.value = ''
    try {
      const params = new URLSearchParams({ ...query(selected), page: String(selected.page), perPage: String(selected.perPage) })
      const data = await request<AdminListPayload<T> & { filters?: FilterOptions }>(`${endpoint}?${params}`)
      if (version !== sequence || destroyed) return
      if (!Array.isArray(data.items) || !Number.isInteger(data.total) || data.total < 0 || !Number.isInteger(data.page) || data.page < 1 || !Number.isInteger(data.perPage) || data.perPage < 1) throw new Error('Invalid list response')
      items.value = data.items; total.value = data.total
      if (data.filters) options.value = data.filters
      loaded.value = true
      if (data.page !== selected.page || data.perPage !== selected.perPage) await router.replace({ query: { ...route.query, ...query({ ...selected, page: data.page, perPage: data.perPage }), page: data.page === 1 ? undefined : String(data.page), perPage: data.perPage === 20 ? undefined : String(data.perPage) } })
    } catch (exception) {
      if (version !== sequence || destroyed) return
      const error = exception as { status?: number; statusCode?: number }
      const status = error.status ?? error.statusCode
      loadError.value = status === 401 ? '登录已失效，请重新登录。' : status === 403 ? '当前账号没有后台权限。' : '列表加载失败，请重试。'
    } finally { if (version === sequence && !destroyed) loading.value = false }
  }
  async function select(next: ListFilters) {
    if (JSON.stringify(query(next)) === JSON.stringify(query(filters.value))) return load()
    const nextQuery = { ...route.query }
    for (const key of [...keys, 'page', 'perPage']) delete nextQuery[key]
    await router.push({ query: { ...nextQuery, ...query(next) } })
  }
  const search = () => select({ ...draft, q: draft.q.trim(), page: 1 })
  const resetFilters = () => select({ q: '', status: '', period: '', category: '', page: 1, perPage: 20 })
  const go = (page: number) => select({ ...filters.value, page })
  watch(filters, () => { ++sequence; Object.assign(draft, filters.value); if (started) void load() }, { flush: 'sync' })
  onMounted(async () => { await restore(); if (!destroyed) { started = true; await load() } })
  onBeforeUnmount(() => { destroyed = true; started = false; ++sequence })
  return { items, total, filters, draft, sizes, options, loading, loaded, loadError, visible, load, search, resetFilters, go }
}
