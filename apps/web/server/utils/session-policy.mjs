export const SESSION_COOKIE = 'guanlan.session'
export const LEGACY_COOKIE = 'guanlan.token'
export const SESSION_MAX_AGE = 60 * 60 * 24 * 30

export function assertSameOrigin(method, origin, site, expectedOrigin) {
  if (['GET', 'HEAD', 'OPTIONS'].includes(method.toUpperCase())) return
  if (!origin || origin !== expectedOrigin || (site && site !== 'same-origin' && site !== 'none')) {
    throw Object.assign(new Error('Same-origin request required'), { statusCode: 403 })
  }
}

export function upstreamURL(base, path, search = '') {
  // Rebuild by segment, never let URL normalization interpret dots or separators.
  let parts
  try { parts = path.slice(1).split('/').map(part => decodeURIComponent(part)) } catch { parts = [] }
  if (!path.startsWith('/') || !parts.length || parts.some(part => !part || part === '.' || part === '..' || /[\\/\\\\%\u0000-\u0020\u007f?#]/u.test(part)) || (parts[0] === 'auth' && path.includes('%'))) {
    throw Object.assign(new Error('Invalid API path'), { statusCode: 400 })
  }
  const canonical = '/' + parts.map(part => encodeURIComponent(part)).join('/')
  const root = new URL(base)
  if (!['http:', 'https:'].includes(root.protocol) || root.username || root.password || root.search || root.hash) throw new Error('Invalid internal API base')
  return `${root.origin}${root.pathname.replace(/\/$/, '')}${canonical}${search}`
}
