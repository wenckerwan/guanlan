/** Service Worker cache strategy constants. */
export const CACHE_STRATEGIES = {
  PRECACHE: 'precache',
  SWR: 'stale-while-revalidate',
  NETWORK_FIRST: 'network-first',
  NETWORK_ONLY: 'network-only',
}

/** Workbox runtimeCaching limits for stale-while-revalidate content. */
export const SWR_MAX_ENTRIES = 200
export const SWR_MAX_AGE_SECONDS = 7 * 24 * 3600

/** Public, read-only content APIs that may be served stale while offline. */
const SWR_API_PREFIXES = [
  '/api/v1/home',
  '/api/v1/subjects',
  '/api/v1/papers',
  '/api/v1/questions',
  '/api/v1/analysis',
  '/api/v1/hotspots',
  '/api/v1/predictions',
  '/api/v1/mocks',
  '/api/v1/history/events',
]

/** Credential-bearing, user-specific or quota-relevant APIs that must never be cached. */
const NETWORK_ONLY_API_PREFIXES = [
  '/api/v1/auth',
  '/api/v1/study',
  '/api/v1/mistakes',
  '/api/v1/comments',
  '/api/v1/stats',
  '/api/v1/search',
]

/** Prefix match with segment boundary: /api/v1/analysis must not hit /api/v1/analysis-foo. */
function matchesPrefix(pathname, prefix) {
  return pathname === prefix || pathname.startsWith(`${prefix}/`)
}

export function isApiPath(pathname) {
  return matchesPrefix(String(pathname || ''), '/api/v1')
}

/**
 * Classify a same-origin request URL for the Service Worker.
 * Defaults to network-only: skip caching rather than risk caching wrong data.
 */
export function cacheStrategy(pathname, method = 'GET') {
  const verb = String(method || 'GET').toUpperCase()
  const path = String(pathname || '')
  if (verb !== 'GET' && verb !== 'HEAD') {
    return CACHE_STRATEGIES.NETWORK_ONLY
  }
  if (!isApiPath(path)) {
    if (path.startsWith('/_nuxt/')) {
      return CACHE_STRATEGIES.PRECACHE
    }
    return CACHE_STRATEGIES.NETWORK_FIRST
  }
  for (const prefix of NETWORK_ONLY_API_PREFIXES) {
    if (matchesPrefix(path, prefix)) {
      return CACHE_STRATEGIES.NETWORK_ONLY
    }
  }
  for (const prefix of SWR_API_PREFIXES) {
    if (matchesPrefix(path, prefix)) {
      return CACHE_STRATEGIES.SWR
    }
  }
  return CACHE_STRATEGIES.NETWORK_ONLY
}
