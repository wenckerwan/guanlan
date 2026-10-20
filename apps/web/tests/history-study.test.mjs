import test from 'node:test'
import assert from 'node:assert/strict'
import vm from 'node:vm'
import { build } from 'esbuild'

const state = (id = '') => ({ version: 1, bookmarks: id ? [id] : [], notes: id ? { [id]: `${id} private note` } : {}, attempts: [], view: null })
async function setup() {
  const stores = new Map()
  const result = await build({ entryPoints: ['components/history/adapters/guanlan-study.ts'], bundle: true, write: false, platform: 'node', format: 'cjs', plugins: [{ name: 'memory-idb', setup(b) {
    b.onResolve({ filter: /indexed-db$/ }, () => ({ path: 'memory-idb', namespace: 'stub' }))
    b.onLoad({ filter: /.*/, namespace: 'stub' }, () => ({ contents: `export async function accessRecord(name,table,key,mode,fn){return fn(stores.get(name+':'+table+':'+key),{put(v,k){stores.set(name+':'+table+':'+k,structuredClone(v))},delete(k){stores.delete(name+':'+table+':'+k)}})}`, loader: 'js' }))
  } }] })
  const box = { module: { exports: {} }, exports: {}, stores, structuredClone, Headers, Response, AbortController }
  vm.runInNewContext(result.outputFiles[0].text, box)
  return { Repo: box.module.exports.GuanlanStudyRepository, stores }
}
function server() {
  const requests = [], notes = [], favorites = []; let offline = false, failWrites = false
  const fetch = async (url, init = {}) => {
    requests.push({ url, method: init.method || 'GET', body: init.body, auth: new Headers(init.headers).get('Authorization') })
    if (offline || (failWrites && init.method)) throw Error('offline')
    const body = init.body ? JSON.parse(init.body) : null
    if (init.method === 'PUT') favorites.push({ id: favorites.length + 1, ...body })
    if (init.method === 'POST' && url.endsWith('/notes')) notes.push({ id: notes.length + 1, ...body })
    return new Response(JSON.stringify({ data: url.includes('/notes') ? notes : url.includes('/favorites') ? favorites : [] }))
  }
  return { fetch, requests, set offline(v) { offline = v }, set failWrites(v) { failWrites = v } }
}

test('A pending notes and favorites never load or submit as B', async () => {
  const { Repo } = await setup(); const a = server(); a.offline = true
  await new Repo({ accountId: 1, fetch: a.fetch, authHeaders: () => ({ Authorization: 'Bearer A' }) }).save(state('A'))
  const b = server(); const loaded = await new Repo({ accountId: 2, fetch: b.fetch, authHeaders: () => ({ Authorization: 'Bearer B' }) }).load()
  assert.deepEqual(JSON.parse(JSON.stringify(loaded)), state())
  assert.equal(b.requests.filter(r => r.method !== 'GET').length, 0)
})
test('guest state is separate from a confirmed account', async () => {
  const { Repo } = await setup(); const guest = new Repo({ fetch: server().fetch })
  await guest.save(state('guest'))
  const loaded = await new Repo({ accountId: 1, fetch: server().fetch, authHeaders: () => ({ Authorization: 'Bearer A' }) }).load()
  assert.deepEqual(JSON.parse(JSON.stringify(loaded)), state())
  assert.deepEqual(JSON.parse(JSON.stringify(await guest.load())), state('guest'))
})
test('legacy shared records stay untouched and never migrate automatically', async () => {
  const { Repo, stores } = await setup()
  stores.set('history-study-guanlan-v1:study:state', state('legacy'))
  stores.set('history-study-guanlan-v1:study:pending-sync', state('legacy'))
  const s = server(); const loaded = await new Repo({ accountId: 1, fetch: s.fetch, authHeaders: () => ({ Authorization: 'Bearer A' }) }).load()
  assert.deepEqual(JSON.parse(JSON.stringify(loaded)), state())
  assert.equal(s.requests.filter(r => r.method !== 'GET').length, 0)
  assert.deepEqual(stores.get('history-study-guanlan-v1:study:state'), state('legacy'))
})
test('a disposed repository rejects queued work without network writes', async () => {
  const { Repo } = await setup(); const s = server()
  const repo = new Repo({ accountId: 1, fetch: s.fetch, authHeaders: () => ({ Authorization: 'Bearer A' }) })
  const pending = repo.save(state('A')); repo.dispose()
  await assert.rejects(pending); assert.equal(s.requests.length, 0)
})
test('credential changes interrupt old repository instead of using the new bearer', async () => {
  const { Repo } = await setup(); const s = server(); let token = 'A'
  const repo = new Repo({ accountId: 1, fetch: s.fetch, authHeaders: () => ({ Authorization: `Bearer ${token}` }), isCurrent: () => token === 'A' })
  await repo.load(); token = 'B'
  await assert.rejects(repo.save(state('A')))
  assert.equal(s.requests.some(r => r.auth === 'Bearer B'), false)
  assert.equal(s.requests.filter(r => r.method !== 'GET').length, 0)
})
test('failed replay preserves pending data until a successful reconnect', async () => {
  const { Repo } = await setup(); const s = server(); s.offline = true
  const config = { accountId: 1, fetch: s.fetch, authHeaders: () => ({ Authorization: 'Bearer A' }) }
  await new Repo(config).save(state('A')); s.offline = false; s.failWrites = true
  const failed = new Repo(config); await failed.load(); assert.equal(failed.status.pendingSync, true)
  s.failWrites = false
  const loaded = await new Repo(config).load()
  assert.equal(loaded.notes.A, 'A private note'); assert.ok(loaded.bookmarks.includes('A'))
})
test('401 stops replay and retains original account pending data', async () => {
  const { Repo } = await setup(); const s = server(); s.offline = true
  const config = { accountId: 1, fetch: s.fetch, authHeaders: () => ({ Authorization: 'Bearer A' }) }
  await new Repo(config).save(state('A'))
  const expired = new Repo({ ...config, fetch: async () => new Response('{}', { status: 401 }) })
  await expired.load(); assert.equal(expired.status.mode, 'auth'); assert.equal(expired.status.pendingSync, true)
  s.offline = false
  assert.equal((await new Repo(config).load()).notes.A, 'A private note')
})
test('unmount flush after disposal keeps edits in their original account partition', async () => {
  const { Repo } = await setup(); const s = server()
  const config = { accountId: 1, fetch: s.fetch, authHeaders: () => ({ Authorization: 'Bearer A' }) }
  const old = new Repo(config); old.dispose()
  await assert.rejects(old.save(state('A')))
  assert.equal(s.requests.length, 0)
  const loaded = await new Repo(config).load()
  assert.equal(loaded.notes.A, 'A private note')
})
