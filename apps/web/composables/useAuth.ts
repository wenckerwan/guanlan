import type { AuthPayload, User } from '~/types/api'

const TOKEN_KEY = 'guanlan.token'
const USER_KEY = 'guanlan.user'
const TOKEN_MAX_AGE = 60 * 60 * 24 * 30

function readCookieValue(cookie: string, name: string) {
  for (const part of cookie.split(';')) {
    const trimmed = part.trim()
    const eq = trimmed.indexOf('=')
    if (eq < 0) continue
    if (trimmed.slice(0, eq) === name) return decodeURIComponent(trimmed.slice(eq + 1))
  }
  return ''
}

/**
 * 同步读取登录 token。
 * 服务端从请求 Cookie 取，客户端从 document.cookie 取——SSR 首帧就能拿到登录态。
 */
export function readAuthToken(): string {
  if (import.meta.server) {
    return readCookieValue(useRequestHeaders(['cookie']).cookie ?? '', TOKEN_KEY)
  }
  return readCookieValue(document.cookie, TOKEN_KEY)
}

function writeCookie(name: string, value: string, maxAge: number) {
  if (import.meta.server) return
  const secure = window.location.protocol === 'https:' ? '; Secure' : ''
  document.cookie = `${name}=${encodeURIComponent(value)}; Path=/; Max-Age=${maxAge}; SameSite=Lax${secure}`
}

export function useAuth() {
  const config = useRuntimeConfig()
  const token = useState<string>('auth.token', () => readAuthToken())
  const user = useState<User | null>('auth.user', () => null)
  const ready = useState<boolean>('auth.ready', () => false)
  const generation = useState<number>('auth.generation', () => 0)

  function cacheUser(value: User | null) {
    if (!import.meta.client) return
    try {
      if (value) localStorage.setItem(USER_KEY, JSON.stringify(value))
      else localStorage.removeItem(USER_KEY)
    } catch { /* 资料缓存不可用不影响认证或退出。 */ }
  }

  function persist(nextToken: string, nextUser: User | null) {
    ++generation.value
    token.value = nextToken
    user.value = nextUser
    if (import.meta.client) {
      writeCookie(TOKEN_KEY, nextToken, nextToken ? TOKEN_MAX_AGE : 0)
      cacheUser(nextUser)
    }
  }

  async function fetchUser() {
    const requestedToken = token.value
    const requestedGeneration = generation.value
    if (!requestedToken) return null
    const isCurrent = () => token.value === requestedToken && generation.value === requestedGeneration
      && (!import.meta.client || readAuthToken() === requestedToken)
    try {
      const payload = await $fetch<{ data: { user: User } }>('/auth/me', {
        baseURL: (import.meta.server ? config.apiInternalBase : config.public.apiBase) as string,
        headers: { Authorization: `Bearer ${requestedToken}` },
      })
      if (!isCurrent()) return null
      user.value = payload.data.user
      cacheUser(user.value)
      return user.value
    } catch (error: any) {
      if (isCurrent() && (error?.statusCode ?? error?.status ?? error?.response?.status) === 401) invalidateToken(requestedToken)
      return null
    }
  }

  /**
   * 解析登录态：token 已在 useState 默认值里从 Cookie 取到。
   * 用户资料服务端回源拉取，客户端优先复用 localStorage 缓存。
   */
  async function restore() {
    if (!token.value) token.value = readAuthToken()
    if (import.meta.client && !user.value) {
      try {
        const raw = localStorage.getItem(USER_KEY)
        if (raw) {
          user.value = JSON.parse(raw) as User
        }
      } catch { user.value = null }
    }
    if (token.value && !user.value) await fetchUser()
    ready.value = true
    return user.value
  }

  async function request<T>(path: string, options: { method?: string; body?: unknown } = {}) {
    const response = await $fetch<{ data: T }>(path, {
      baseURL: (import.meta.server ? config.apiInternalBase : config.public.apiBase) as string,
      method: (options.method ?? 'GET') as 'GET',
      body: options.body as undefined,
      headers: token.value ? { Authorization: `Bearer ${token.value}` } : {},
    })
    return response.data
  }

  async function login(email: string, password: string) {
    const payload = await $fetch<{ data: AuthPayload }>('/auth/login', {
      baseURL: config.public.apiBase as string,
      method: 'POST',
      body: { email, password },
    })
    persist(payload.data.token, payload.data.user)
    return payload.data.user
  }

  async function register(email: string, password: string, displayName: string, code?: string) {
    const payload = await $fetch<{ data: AuthPayload }>('/auth/register', {
      baseURL: config.public.apiBase as string,
      method: 'POST',
      body: { email, password, displayName, code },
    })
    persist(payload.data.token, payload.data.user)
    return payload.data.user
  }

  async function logout() {
    const requestedToken = token.value
    const requestedGeneration = generation.value
    try {
      if (token.value) await request('/auth/logout', { method: 'POST' })
    } catch {
      // 忽略：本地登出优先
    }
    if (token.value === requestedToken && generation.value === requestedGeneration
      && (!import.meta.client || readAuthToken() === requestedToken)) persist('', null)
  }

  function invalidateToken(expectedToken: string) {
    if (token.value !== expectedToken) return false
    if (import.meta.client && readAuthToken() !== expectedToken) return false
    persist('', null)
    return true
  }

  const isLoggedIn = computed(() => token.value !== '')
  const isAdmin = computed(() => user.value?.role === 'admin')

  return { token, user, ready, isLoggedIn, isAdmin, fetchUser, restore, login, register, logout, request, invalidateToken }
}
