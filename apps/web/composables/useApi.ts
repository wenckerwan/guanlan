import type { ApiEnvelope } from '~/types/api'
import { unwrapEnvelope } from '~/utils/api.mjs'
import type { Ref } from 'vue'

/** Same-origin BFF for both SSR and browsers; cache keys never contain credentials. */
export function useApiFetch<T>(path: string | Ref<string>, fallback: T, options: Record<string, unknown> = {}) {
  const auth = useAuth()
  const cacheKey = computed(() => `${typeof path === 'string' ? path : path.value}::${auth.user.value?.id ?? 'guest'}::${auth.generation.value}`)
  return useFetch<T>(path, {
    baseURL: '/api/v1', key: cacheKey,
    transform: (payload: ApiEnvelope<T>) => unwrapEnvelope(payload, fallback) as T,
    default: () => fallback,
    ...options,
  })
}

/** 动态路径用：拼 query string，跳过空值。 */
export function withQuery(path: string, params: Record<string, unknown>): string {
  const search = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value === null || value === undefined || value === '') continue
    search.set(key, String(value))
  }
  const qs = search.toString()
  return qs ? `${path}?${qs}` : path
}
