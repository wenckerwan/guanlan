import test from 'node:test'
import assert from 'node:assert/strict'
import { createServer } from 'node:http'
import { createRequire } from 'node:module'
import vm from 'node:vm'
import { buildSync } from 'esbuild'
import { createApp, toNodeListener } from 'h3'
import { assertSameOrigin, upstreamURL } from '../server/utils/session-policy.mjs'

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
