import test from 'node:test'
import assert from 'node:assert/strict'
import vm from 'node:vm'
import { buildSync } from 'esbuild'

function deferred() { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b }); return { promise, resolve, reject } }
function setup() {
  const pending = [], values = new Map(), requests = []
  const box = {
    module: { exports: {} }, exports: {},
    localStorage: { removeItem() { throw Error('denied') } },
    computed: fn => ({ get value() { return fn() } }),
    useState: (key, init) => { if (!values.has(key)) values.set(key, { value: init() }); return values.get(key) },
    $fetch: (url, options = {}) => {
      requests.push({ url, options })
      if (url === '/api/v1/auth/me' || url === '/api/v1/auth/logout') { const d = deferred(); pending.push(d); return d.promise }
      return Promise.resolve({ data: { user: { id: 2, role: 'user' } } })
    },
  }
  const code = buildSync({ entryPoints: ['composables/useAuth.ts'], bundle: true, write: false, platform: 'node', format: 'cjs', define: { 'import.meta.client': 'true', 'import.meta.server': 'false' } }).outputFiles[0].text
  vm.runInNewContext(code, box)
  return { auth: box.module.exports.useAuth(), pending, values, requests }
}

for (const outcome of ['success', 401, 500]) test(`old ${outcome} response cannot overwrite new login`, async () => {
  const { auth, pending } = setup(); const old = auth.fetchUser()
  await auth.login('b@example.invalid', 'unused')
  if (outcome === 'success') pending[0].resolve({ data: { user: { id: 1 } } })
  else pending[0].reject({ statusCode: outcome })
  await old
  assert.equal(auth.user.value.id, 2)
})
for (const status of [500, undefined]) test(`current ${status ?? 'network'} failure preserves verified identity`, async () => {
  const { auth, pending } = setup(); await auth.login('b@example.invalid', 'unused'); const request = auth.fetchUser()
  pending[0].reject({ statusCode: status }); await request
  assert.equal(auth.user.value.id, 2)
})
test('current 401 clears public identity', async () => {
  const { auth, pending } = setup(); await auth.login('b@example.invalid', 'unused'); const request = auth.fetchUser()
  pending[0].reject({ statusCode: 401 }); await request
  assert.equal(auth.user.value, null)
})
test('no credential state, cookie access, auth headers or credential cache keys', async () => {
  const { auth, values, requests } = setup()
  await auth.login('b@example.invalid', 'unused'); await auth.restore()
  assert.equal(auth.token, undefined)
  assert.equal(values.has('auth.token'), false)
  assert.equal(requests.some(item => item.options.headers?.Authorization), false)
  assert.equal(auth.user.value.id, 2)
})
test('late logout cannot clear a subsequent login', async () => {
  const { auth, pending } = setup(); const old = auth.logout()
  await auth.login('b@example.invalid', 'unused'); pending[0].resolve({ data: {} }); await old
  assert.equal(auth.user.value.id, 2)
})
test('SSR uses request-scoped local fetch without serializing credentials', async () => {
  const values = new Map(), calls = []
  const box = { module: { exports: {} }, exports: {}, computed: fn => ({ get value() { return fn() } }),
    useState: (key, init) => { if (!values.has(key)) values.set(key, { value: init() }); return values.get(key) },
    useRequestFetch: () => async (url, options) => { calls.push({ url, options }); return { data: { user: { id: 7 } } } },
  }
  const code = buildSync({ entryPoints: ['composables/useAuth.ts'], bundle: true, write: false, platform: 'node', format: 'cjs', define: { 'import.meta.client': 'false', 'import.meta.server': 'true' } }).outputFiles[0].text
  vm.runInNewContext(code, box)
  const auth = box.module.exports.useAuth(); await auth.restore()
  assert.equal(calls[0].url, '/api/v1/auth/me'); assert.equal(calls[0].options, undefined)
  assert.equal(auth.user.value.id, 7); assert.equal(values.has('auth.token'), false)
})
test('failed logout does not claim server cookie was cleared', async () => {
  const { auth, pending } = setup(); await auth.login('b@example.invalid', 'unused')
  const old = auth.logout(); pending[0].reject(Error('offline')); await assert.rejects(old)
  assert.equal(auth.user.value.id, 2)
})
