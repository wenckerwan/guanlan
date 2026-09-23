import type { AuthPayload, User } from '~/types/api'

const TOKEN_KEY = 'guanlan.token'
const USER_KEY = 'guanlan.user'

/**
 * 登录态：token 存 localStorage（客户端），并通过 useState 在 SSR/CSR 间共享。
 * 服务端渲染时没有 localStorage，因此 SSR 阶段按「未登录」渲染，客户端接管后刷新。
 */
export function useAuth() {
  const token = useState<string>('auth.token', () => '')
  const user = useState<User | null>('auth.user', () => null)
  const ready = useState<boolean>('auth.ready', () => false)

  function persist(nextToken: string, nextUser: User | null) {
    token.value = nextToken
    user.value = nextUser
    if (import.meta.client) {
      if (nextToken) {
        localStorage.setItem(TOKEN_KEY, nextToken)
      } else {
        localStorage.removeItem(TOKEN_KEY)
      }
      if (nextUser) {
        localStorage.setItem(USER_KEY, JSON.stringify(nextUser))
      } else {
        localStorage.removeItem(USER_KEY)
      }
    }
  }

  function restore() {
    if (!import.meta.client || ready.value) return
    const saved = localStorage.getItem(TOKEN_KEY) ?? ''
    token.value = saved
    const rawUser = localStorage.getItem(USER_KEY)
    if (rawUser) {
      try {
        user.value = JSON.parse(rawUser) as User
      } catch {
        user.value = null
      }
    }
    ready.value = true
  }

  /** 带鉴权的请求封装。 */
  async function request<T>(path: string, options: { method?: string; body?: unknown } = {}) {
    const config = useRuntimeConfig()
    const response = await $fetch<{ data: T }>(path, {
      baseURL: config.public.apiBase as string,
      method: (options.method ?? 'GET') as 'GET',
      body: options.body as undefined,
      headers: token.value ? { Authorization: `Bearer ${token.value}` } : {},
    })
    return response.data
  }

  async function login(email: string, password: string) {
    const config = useRuntimeConfig()
    const payload = await $fetch<{ data: AuthPayload }>('/auth/login', {
      baseURL: config.public.apiBase as string,
      method: 'POST',
      body: { email, password },
    })
    persist(payload.data.token, payload.data.user)
    return payload.data.user
  }

  async function register(email: string, password: string, displayName: string) {
    const config = useRuntimeConfig()
    const payload = await $fetch<{ data: AuthPayload }>('/auth/register', {
      baseURL: config.public.apiBase as string,
      method: 'POST',
      body: { email, password, displayName },
    })
    persist(payload.data.token, payload.data.user)
    return payload.data.user
  }

  async function logout() {
    try {
      if (token.value) {
        await request('/auth/logout', { method: 'POST' })
      }
    } catch {
      // 忽略：本地登出优先
    }
    persist('', null)
  }

  const isLoggedIn = computed(() => token.value !== '')
  const isAdmin = computed(() => user.value?.role === 'admin')

  return { token, user, ready, isLoggedIn, isAdmin, restore, login, register, logout, request }
}
