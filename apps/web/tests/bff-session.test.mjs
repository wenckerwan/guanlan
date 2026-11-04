import test from 'node:test'
import assert from 'node:assert/strict'
import { createServer } from 'node:http'
import { createRequire } from 'node:module'
import vm from 'node:vm'
import { buildSync } from 'esbuild'
import { createApp, toNodeListener } from 'h3'
import { assertSameOrigin, upstreamURL } from '../server/utils/session-policy.mjs'

async function visitBff(t, upstream, environment = 'production') {
  const calls = []
  const code = buildSync({ entryPoints: ['server/api/v1/[...path].ts'], bundle: true, write: false, platform: 'node', format: 'cjs', external: ['h3'] }).outputFiles[0].text
  const box = { module: { exports: {} }, exports: {}, require: createRequire(import.meta.url), Headers, URL, process: { env: { NODE_ENV: environment } },
    useRuntimeConfig: () => ({ apiInternalBase: 'http://api:9501/api/v1', sessionOrigin: 'https://app.test' }),
    fetch: async (url, options) => { calls.push({ url, options: { ...options, headers: new Headers(options.headers) } }); return upstream(url, options) },
  }
  vm.runInNewContext(code, box)
  const app = createApp(); app.use(box.module.exports.default)
  const server = createServer(toNodeListener(app)); await new Promise(resolve => server.listen(0, '127.0.0.1', resolve))
  t.after(() => new Promise(resolve => server.close(resolve)))
  return { calls, request: (path, options) => fetch(`http://127.0.0.1:${server.address().port}/api/v1${path}`, options) }
}

for (const path of ['/auth/login/', '//auth/login', '/auth/%6cogin', '/auth/login%2f', '/../auth/login', '/auth/./login', '/auth\\login']) {
  test(`reject noncanonical path ${path}`, () => assert.throws(() => upstreamURL('http://api:9501/api/v1', path)))
}
test('unicode and dotted slugs are safely rebuilt without changing origin', () => {
  assert.equal(upstreamURL('http://api:9501/api/v1', '/subjects/中国.v1'), 'http://api:9501/api/v1/subjects/%E4%B8%AD%E5%9B%BD.v1')
})
test('CSRF blocks absent and cross-origin Origin but permits same-origin writes and public GET', () => {
  assert.throws(() => assertSameOrigin('POST', undefined, undefined, 'https://app.test'))
  assert.throws(() => assertSameOrigin('POST', 'https://evil.test', 'cross-site', 'https://app.test'))
  assert.throws(() => assertSameOrigin('POST', 'https://app.test', 'same-site', 'https://app.test'))
  assertSameOrigin('POST', 'https://app.test', 'same-origin', 'https://app.test')
  assertSameOrigin('GET', undefined, undefined, 'https://app.test')
})

const visitor = `${'b'.repeat(64)}.${'c'.repeat(64)}`
const session = 'd'.repeat(64)

test('real BFF initializes and reuses visitor without exposing or overwriting session cookies', async t => {
  let visits = 0
  const { calls, request } = await visitBff(t, (url, options) => {
    if (url.endsWith('/auth/me')) return Response.json({ data: { user: { id: 7 } } })
    const browser = options.headers.get('Cookie')
    if (url.endsWith('/stats/visit')) {
      if (browser !== `guanlan_browser=${visitor}`) return Response.json({ message: 'Initialize first' }, { status: 428 })
      return Response.json({ data: { visitors: ++visits } })
    }
    const headers = new Headers()
    if (!browser) headers.append('Set-Cookie', `guanlan_browser=${visitor}; Domain=evil.test; Path=/evil; Max-Age=1; SameSite=None`)
    headers.append('Set-Cookie', `guanlan.session=${'e'.repeat(64)}; Path=/`)
    headers.append('Set-Cookie', 'evil=attacker; Path=/')
    return Response.json({ data: { visitors: visits } }, { headers })
  })
  const beforeInit = await request('/stats/visit', { method: 'POST', headers: { Origin: 'https://app.test' } })
  assert.equal(beforeInit.status, 428); await beforeInit.text()
  const initialized = await request('/stats/visits?scope=all', { headers: { Cookie: `guanlan.session=${session}; unrelated=secret` } })
  assert.equal(initialized.status, 200); assert.equal((await initialized.json()).data.visitors, 0)
  const cookies = initialized.headers.getSetCookie()
  assert.equal(cookies.length, 1)
  assert.match(cookies[0], new RegExp(`^guanlan_browser=${visitor.replace('.', '\\.')};`))
  assert.match(cookies[0], /HttpOnly/); assert.match(cookies[0], /Secure/); assert.match(cookies[0], /SameSite=Lax/)
  assert.match(cookies[0], /Path=\/(?:;|$)/); assert.match(cookies[0], /Max-Age=31536000(?:;|$)/)
  assert.doesNotMatch(cookies[0], /Domain=|guanlan.session|evil=|SameSite=None/)
  assert.equal(calls.at(-1).options.headers.get('Cookie'), null)
  const browserJar = `${cookies[0].split(';')[0]}; guanlan.session=${session}; unrelated=secret`
  const reused = await request('/stats/visits', { headers: { Cookie: browserJar } })
  await reused.text(); assert.equal(reused.headers.get('set-cookie'), null)
  assert.equal(calls.at(-1).options.headers.get('Cookie'), `guanlan_browser=${visitor}`)
  assert.equal(calls.at(-1).options.headers.get('Authorization'), `Bearer ${session}`)
  const recorded = await request('/stats/visit', { method: 'POST', headers: { Origin: 'https://app.test', Cookie: browserJar, 'X-Guanlan-Account': '7' } })
  assert.equal(recorded.status, 200); assert.equal((await recorded.json()).data.visitors, 1)
  assert.equal(recorded.headers.get('set-cookie'), null)
  assert.equal(calls.at(-2).url.endsWith('/auth/me'), true)
  assert.equal(calls.at(-2).options.headers.get('Cookie'), null)
  assert.equal(calls.at(-1).options.headers.get('Cookie'), `guanlan_browser=${visitor}`)
})

test('real BFF only forwards well-formed visitor cookies on exact stats methods and routes', async t => {
  const { calls, request } = await visitBff(t, () => Response.json({ data: {} }))
  for (const value of ['attacker', visitor.toUpperCase(), `${visitor}x`, `${visitor}%0A`, `${'b'.repeat(63)}.${'c'.repeat(64)}`, `${visitor}%3Bsession%3Dstolen`]) {
    const response = await request('/stats/visits', { headers: { Cookie: `guanlan_browser=${value}; guanlan.session=${session}; evil=secret` } })
    await response.text(); assert.equal(calls.at(-1).options.headers.get('Cookie'), null, value)
  }
  for (const [path, method] of [['/stats/visits', 'POST'], ['/stats/visits', 'HEAD'], ['/stats/visit', 'GET'], ['/stats/visits/extra', 'GET'], ['/stats/%76isits', 'GET'], ['/auth/me', 'GET'], ['/subjects', 'GET']]) {
    const response = await request(path, { method, headers: { Origin: 'https://app.test', Cookie: `guanlan_browser=${visitor}; evil=secret` } })
    await response.text(); assert.equal(calls.at(-1).options.headers.get('Cookie'), null, `${method} ${path}`)
  }
})

test('real BFF rejects arbitrary or malformed upstream cookies and scopes visitor issuance', async t => {
  let issued = `guanlan_browser=${visitor}`, status = 200
  const { request } = await visitBff(t, () => Response.json({ data: {} }, { status, headers: { 'Set-Cookie': issued } }))
  for (const cookie of ['evil=1', `guanlan.session=${session}`, 'guanlan_browser=bad', `guanlan_browser=${visitor}x`, `guanlan_browser=${visitor.toUpperCase()}`, `guanlan_browser=${visitor}%0A`, `guanlan_browser=${visitor}, guanlan.session=attacker`]) {
    issued = cookie
    const response = await request('/stats/visits'); await response.text()
    assert.equal(response.headers.get('set-cookie'), null, cookie)
  }
  issued = `guanlan_browser=${visitor}`
  for (const [path, method] of [['/subjects', 'GET'], ['/stats/visits', 'POST'], ['/stats/visit', 'GET'], ['/stats/%76isits', 'GET']]) {
    const response = await request(path, { method, headers: { Origin: 'https://app.test' } }); await response.text()
    assert.equal(response.headers.get('set-cookie'), null, `${method} ${path}`)
  }
  status = 503
  const failed = await request('/stats/visits'); await failed.text(); assert.equal(failed.headers.get('set-cookie'), null)
  status = 200
  const posted = await request('/stats/visit', { method: 'POST', headers: { Origin: 'https://app.test' } }); await posted.text()
  assert.match(posted.headers.get('set-cookie'), /^guanlan_browser=/)
})

test('real BFF visitor cookie remains HttpOnly and SameSite=Lax outside production without Secure', async t => {
  const { request } = await visitBff(t, () => Response.json({ data: {} }, { headers: { 'Set-Cookie': `guanlan_browser=${visitor}; Secure; SameSite=None` } }), 'development')
  const response = await request('/stats/visits'); await response.text()
  const cookie = response.headers.get('set-cookie')
  assert.match(cookie, /HttpOnly/); assert.match(cookie, /SameSite=Lax/); assert.doesNotMatch(cookie, /Secure/)
})

test('real BFF login strips token, sets HttpOnly cookie and only forwards the server cookie', async t => {
  const issued = 'a'.repeat(64), calls = []
  let failAuthentication = false
  const code = buildSync({ entryPoints: ['server/api/v1/[...path].ts'], bundle: true, write: false, platform: 'node', format: 'cjs', external: ['h3'] }).outputFiles[0].text
  const box = { module: { exports: {} }, exports: {}, require: createRequire(import.meta.url), Headers, URL, process: { env: { NODE_ENV: 'production' } },
    useRuntimeConfig: () => ({ apiInternalBase: 'http://api:9501/api/v1', sessionOrigin: 'https://app.test' }),
    fetch: async (url, options) => {
      calls.push({ url, options })
      return Response.json({ data: /\/auth\/(login|register)$/.test(url) ? { token: issued, user: { id: 7 } } : { user: { id: 7 } } }, { status: failAuthentication ? 401 : 200, headers: { 'Set-Cookie': 'evil=1', 'Access-Control-Allow-Origin': '*' } })
    },
  }
  vm.runInNewContext(code, box)
  const app = createApp(); app.use(box.module.exports.default)
  const server = createServer(toNodeListener(app)); await new Promise(resolve => server.listen(0, '127.0.0.1', resolve))
  t.after(() => new Promise(resolve => server.close(resolve)))
  const base = `http://127.0.0.1:${server.address().port}`
  const login = await fetch(`${base}/api/v1/auth/login`, { method: 'POST', headers: { Origin: 'https://app.test', 'Content-Type': 'application/json', Cookie: 'guanlan.token=legacy' }, body: '{}' })
  assert.equal(login.status, 200)
  const text = await login.text(); assert.equal(text.includes(issued), false); assert.equal(JSON.parse(text).data.token, undefined)
  const cookies = login.headers.getSetCookie().join('; ')
  assert.match(cookies, /guanlan.session=/); assert.match(cookies, /HttpOnly/); assert.match(cookies, /Secure/); assert.match(cookies, /SameSite=Lax/)
  assert.match(cookies, /guanlan.token=;/)
  assert.equal(cookies.includes('evil='), false); assert.equal(login.headers.get('access-control-allow-origin'), null)
  assert.match(login.headers.get('cache-control'), /no-store/)
  assert.equal(calls[0].options.headers.get('Authorization'), null)
  const me = await fetch(`${base}/api/v1/auth/me`, { headers: { Cookie: `guanlan.session=${issued}; unrelated=secret`, Authorization: 'Bearer attacker' } })
  await me.text()
  assert.equal(calls.at(-1).options.headers.get('Authorization'), `Bearer ${issued}`)
  assert.equal(calls.at(-1).options.headers.get('Cookie'), null)
  const register = await fetch(`${base}/api/v1/auth/register`, { method: 'POST', headers: { Origin: 'https://app.test', 'Content-Type': 'application/json' }, body: '{}' })
  assert.equal(register.status, 200); assert.equal((await register.text()).includes(issued), false)
  assert.match(register.headers.get('set-cookie'), /HttpOnly/)
  failAuthentication = true
  const failedLogin = await fetch(`${base}/api/v1/auth/login`, { method: 'POST', headers: { Origin: 'https://app.test' } })
  assert.equal(failedLogin.status, 401); assert.equal((await failedLogin.text()).includes(issued), false)
  assert.equal(failedLogin.headers.get('set-cookie'), null)
  failAuthentication = false
  const mismatch = await fetch(`${base}/api/v1/auth/logout`, { method: 'POST', headers: { Origin: 'https://app.test', Cookie: `guanlan.session=${issued}`, 'X-Guanlan-Account': '8' } })
  assert.equal(mismatch.status, 409); await mismatch.text(); assert.equal(mismatch.headers.get('set-cookie'), null)
  const logout = await fetch(`${base}/api/v1/auth/logout`, { method: 'POST', headers: { Origin: 'https://app.test', Cookie: `guanlan.session=${issued}`, 'X-Guanlan-Account': '7' } })
  assert.equal(logout.status, 200); await logout.text(); assert.match(logout.headers.get('set-cookie'), /guanlan.session=;/)
  const blocked = await fetch(`${base}/api/v1/auth/logout`, { method: 'POST', headers: { Origin: 'https://evil.test' } })
  assert.equal(blocked.status, 403)
  const codeResponse = await fetch(`${base}/api/v1/auth/email/code`, { method: 'POST', headers: { Origin: 'https://app.test', 'Content-Type': 'application/json' }, body: '{}' })
  assert.equal(codeResponse.status, 200); await codeResponse.text()
  const alias = await fetch(`${base}/api/v1/auth/login/`, { method: 'POST', headers: { Origin: 'https://app.test' } })
  assert.equal(alias.status, 400)
})
