import type { ApiEnvelope } from '~/types/api'
import { unwrapEnvelope } from '~/utils/api.mjs'
import type { Ref } from 'vue'

/**
 * 统一的 API 取数封装（SSR + 客户端）。
 *
 * SSR 关键：服务端运行在 Nitro 容器内，相对路径 `/api/v1` 没有 origin，
 * localhost 又指向 web 容器自身，因此服务端必须走容器 DNS `http://api:9501/api/v1`。
 * 客户端走 `/api/v1`，由 nginx 反向代理到 api。
 *
 * API 宕机时 transform/default 回退到 fallback，页面渲染空态而非抛 500。
 */
export function useApiFetch<T>(path: string | Ref<string>, fallback: T, options: Record<string, unknown> = {}) {
  const config = useRuntimeConfig()
  const baseURL = import.meta.server
    ? (config.apiInternalBase as string)
    : (config.public.apiBase as string)

  return useFetch<T>(path, {
    baseURL,
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
