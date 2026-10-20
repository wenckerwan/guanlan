import test from 'node:test'
import assert from 'node:assert/strict'
import vm from 'node:vm'
import { buildSync } from 'esbuild'

function deferred() { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b }); return { promise, resolve, reject } }
function setup({ storageFails = false } = {}) {
  const pending = [], values = new Map(); let cookie = 'guanlan.token=A'
  const box = {
    module: { exports: {} }, exports: {},
    document: { get cookie() { return cookie }, set cookie(value) { cookie = value.split(';')[0] } },
    window: { location: { protocol: 'https:' } },
    localStorage: { getItem() { if (storageFails) throw Error('denied'); return null }, setItem() { if (storageFails) throw Error('quota') }, removeItem() { if (storageFails) throw Error('denied') } },
    computed: fn => ({ get value() { return fn() } }), useRuntimeConfig: () => ({ public: { apiBase: '/api/v1' } }),
    useState: (key, init) => { if (!values.has(key)) values.set(key, { value: init() }); return values.get(key) },
    $fetch: url => {
      if (url === '/auth/me' || url === '/auth/logout') { const d = deferred(); pending.push(d); return d.promise }
      return Promise.resolve({ data: { token: 'B', user: { id: 2, role: 'user' } } })
    },
  }
  const code = buildSync({ entryPoints: ['composables/useAuth.ts'], bundle: true, write: false, platform: 'node', format: 'cjs', define: { 'import.meta.client': 'true', 'import.meta.server': 'false' } }).outputFiles[0].text
  vm.runInNewContext(code, box)
  return { auth: box.module.exports.useAuth(), pending, setCookie: value => { cookie = value } }
}

for (const outcome of ['success', 401, 500]) test(`old ${outcome} response cannot overwrite new login`, async () => {
  const { auth, pending } = setup(); const old = auth.fetchUser()
  await auth.login('b@example.invalid', 'unused')
  if (outcome === 'success') pending[0].resolve({ data: { user: { id: 1 } } })
  else pending[0].reject({ statusCode: outcome })
  await old
  assert.equal(auth.token.value, 'B'); assert.equal(auth.user.value.id, 2)
})
for (const status of [500, undefined]) test(`current ${status ?? 'network'} failure preserves credentials`, async () => {
  const { auth, pending } = setup(); const request = auth.fetchUser()
  pending[0].reject({ statusCode: status }); await request
  assert.equal(auth.token.value, 'A')
})
test('current 401 clears current credentials', async () => {
  const { auth, pending } = setup(); const request = auth.fetchUser()
  pending[0].reject({ statusCode: 401 }); await request
  assert.equal(auth.token.value, ''); assert.equal(auth.user.value, null)
})
test('optional user cache cannot break login or restore', async () => {
  const { auth } = setup({ storageFails: true })
  await auth.login('b@example.invalid', 'unused'); await auth.restore()
  assert.equal(auth.token.value, 'B'); assert.equal(auth.user.value.id, 2)
})
test('late logout cannot clear a subsequent login', async () => {
  const { auth, pending } = setup(); const old = auth.logout()
  await auth.login('b@example.invalid', 'unused'); pending[0].resolve({ data: {} }); await old
  assert.equal(auth.token.value, 'B'); assert.equal(auth.user.value.id, 2)
})
test('invalidation from another tab cannot clear a new cookie session', () => {
  const { auth, setCookie } = setup()
  setCookie('guanlan.token=B')
  assert.equal(auth.invalidateToken('A'), false)
  assert.equal(auth.token.value, 'A')
})
