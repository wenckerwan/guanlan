import type { AuthPayload, User } from '~/types/api'

/** Browser state contains only public identity, never credentials. SSR uses the local BFF. */
export function useAuth() {
  const user = useState<User | null>('auth.user', () => null)
  const ready = useState<boolean>('auth.ready', () => false)
  const generation = useState<number>('auth.generation', () => 0)
  const transport = import.meta.server ? useRequestFetch() : $fetch

  function apply(next: User | null) {
    if (user.value?.id !== next?.id) ++generation.value
    user.value = next
    ready.value = true
  }

  async function fetchUser() {
    const epoch = generation.value
    try {
      const response = await transport<{ data: { user: User } }>('/api/v1/auth/me')
      if (epoch !== generation.value) return null
      apply(response.data.user)
      return user.value
    } catch (error: any) {
      if (epoch === generation.value && (error?.statusCode ?? error?.status ?? error?.response?.status) === 401) apply(null)
      return null
    }
  }

  async function restore() {
    if (!ready.value) { await fetchUser(); ready.value = true }
    if (import.meta.client) {
      // Never restore the former unverified browser identity cache.
      try { localStorage.removeItem('guanlan.user'); localStorage.removeItem('guanlan.token') } catch { /* optional cleanup */ }
    }
    return user.value
  }

  async function request<T>(path: string, options: { method?: string; body?: unknown } = {}) {
    if (!path.startsWith('/') || path.startsWith('//')) throw new Error('Invalid API path')
    const response = await transport<{ data: T }>(`/api/v1${path}`, {
      method: (options.method ?? 'GET') as 'GET', body: options.body as undefined,
      // Non-secret account binding prevents queued work from crossing accounts after a tab switch.
      headers: user.value?.id ? { 'X-Guanlan-Account': String(user.value.id) } : {},
    })
    return response.data
  }

  async function authenticate(path: string, body: unknown) {
    ++generation.value
    const epoch = generation.value
    const response = await transport<{ data: AuthPayload }>(`/api/v1/auth/${path}`, { method: 'POST', body: body as undefined })
    if (epoch === generation.value) apply(response.data.user)
    return response.data.user
  }
  const login = (email: string, password: string) => authenticate('login', { email, password })
  const register = (email: string, password: string, displayName: string, code?: string) => authenticate('register', { email, password, displayName, code })

  async function logout() {
    const epoch = ++generation.value
    // Do not claim logout succeeded if the server could not clear its HttpOnly cookie.
    await request('/auth/logout', { method: 'POST' })
    if (epoch === generation.value) apply(null)
  }
  function invalidateSession(expectedGeneration: number) {
    if (generation.value !== expectedGeneration) return false
    apply(null)
    return true
  }
  const isLoggedIn = computed(() => Boolean(user.value))
  const isAdmin = computed(() => user.value?.role === 'admin')
  return { user, ready, generation, isLoggedIn, isAdmin, fetchUser, restore, login, register, logout, request, invalidateSession }
}
