import { defineEventHandler, getRequestHeader, getRequestURL, getCookie, setCookie, deleteCookie, readRawBody, setResponseHeader, setResponseStatus, sendStream, createError, getRequestIP } from 'h3'
import { SESSION_COOKIE, LEGACY_COOKIE, SESSION_MAX_AGE, assertSameOrigin, upstreamURL } from '../../utils/session-policy.mjs'

export default defineEventHandler(async event => {
  const config = useRuntimeConfig(event)
  const url = getRequestURL(event, { xForwardedHost: false, xForwardedProto: false })
  const rawPath = event.path.split('?')[0]
  if (rawPath !== url.pathname) throw createError({ statusCode: 400, statusMessage: 'Noncanonical API path' })
  const path = rawPath.slice('/api/v1'.length)
  const method = event.method.toUpperCase()
  // Production must configure the external origin; never trust arbitrary forwarded headers.
  const origin = config.sessionOrigin || url.origin
  assertSameOrigin(method, getRequestHeader(event, 'origin'), getRequestHeader(event, 'sec-fetch-site'), origin)
  setResponseHeader(event, 'Cache-Control', 'private, no-store')
  setResponseHeader(event, 'Vary', 'Cookie')
  const cookieOptions = { httpOnly: true, secure: process.env.NODE_ENV === 'production', sameSite: 'lax' as const, path: '/' }
  if (getCookie(event, LEGACY_COOKIE)) deleteCookie(event, LEGACY_COOKIE, { path: '/' })
  // Old JS-readable cookies are deliberately never accepted or upgraded: sign in again.
  const token = getCookie(event, SESSION_COOKIE) || ''
  const headers = new Headers({ Accept: getRequestHeader(event, 'accept') || 'application/json' })
  const contentType = getRequestHeader(event, 'content-type')
  if (contentType) headers.set('Content-Type', contentType)
  const agent = getRequestHeader(event, 'user-agent')
  if (agent) headers.set('User-Agent', agent)
  if (token) headers.set('Authorization', `Bearer ${token}`)
  // Forward only the actual peer, not an attacker-supplied forwarding chain. The API
  // should trust this BFF address; the edge must perform rate limiting as well.
  const peer = getRequestIP(event)
  if (peer) headers.set('X-Forwarded-For', peer)
  const target = upstreamURL(config.apiInternalBase, path, url.search)
  if (path.startsWith('/auth/')) {
    const allowed: Record<string, string> = { '/auth/login': 'POST', '/auth/register': 'POST', '/auth/email/code': 'POST', '/auth/me': 'GET', '/auth/logout': 'POST' }
    if (allowed[path] !== method) throw createError({ statusCode: 404, statusMessage: 'Unknown authentication endpoint' })
  }
  // Bind sensitive requests/outbox writes to the identity confirmed in this tab.
  const account = getRequestHeader(event, 'x-guanlan-account')
  if (account && token && (!path.startsWith('/auth/') || path === '/auth/logout')) {
    const check = await fetch(upstreamURL(config.apiInternalBase, '/auth/me'), { headers, redirect: 'error' })
    if (!check.ok) throw createError({ statusCode: check.status, statusMessage: 'Session verification failed' })
    const identity = await check.json()
    if (String(identity?.data?.user?.id) !== account) throw createError({ statusCode: 409, statusMessage: 'Session account changed; reload required' })
  }
  const body = ['GET', 'HEAD'].includes(method) ? undefined : await readRawBody(event, false)
  const response = await fetch(target, { method, headers, body, redirect: 'error' })
  setResponseStatus(event, response.status)
  const type = response.headers.get('content-type')
  if (type) setResponseHeader(event, 'Content-Type', type)
  // No upstream Set-Cookie, Location, CORS, or arbitrary headers are relayed.
  if (['/auth/login', '/auth/register'].includes(path)) {
    const payload = await response.json()
    if (response.ok) {
      const issued = payload?.data?.token
      if (typeof issued !== 'string' || !/^[a-f0-9]{64}$/.test(issued)) throw createError({ statusCode: 502, statusMessage: 'Invalid authentication response' })
      setCookie(event, SESSION_COOKIE, issued, { ...cookieOptions, maxAge: SESSION_MAX_AGE })
    }
    // Never expose credentials, even on an unexpected unsuccessful upstream envelope.
    if (payload?.data) delete payload.data.token
    return payload
  }
  if (path === '/auth/logout' && response.ok) deleteCookie(event, SESSION_COOKIE, cookieOptions)
  if (method === 'HEAD' || response.status === 204 || !response.body) return null
  return sendStream(event, response.body)
})
