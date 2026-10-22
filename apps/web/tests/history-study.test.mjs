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
  const box = { module: { exports: {} }, exports: {}, stores, structuredClone, Headers, Response, AbortController, TextEncoder }
  vm.runInNewContext(result.outputFiles[0].text, box)
  return { Repo: box.module.exports.GuanlanStudyRepository, stores }
}
function server() {
  const requests = [], notes = [], favorites = []; let offline = false, failWrites = false
  const fetch = async (url, init = {}) => {
    requests.push({ url, method: init.method || 'GET', body: init.body, auth: new Headers(init.headers).get('Authorization') })
    if (offline || (failWrites && init.method)) throw Error('offline')
    const body = init.body ? JSON.parse(init.body) : null
    if (init.method === 'PUT') { const i=favorites.findIndex(x=>x.targetId===body.targetId); if(i>=0) favorites.splice(i,1); if(body.favorited) favorites.push({ id: favorites.length + 1, ...body }) }
    if (init.method === 'POST' && url.endsWith('/notes')) notes.push({ id: notes.length + 1, ...body })
    if (init.method === 'PATCH') Object.assign(notes.find(x => String(x.id) === url.split('/').at(-1)), body)
    if (init.method === 'DELETE') { const i=notes.findIndex(x => String(x.id) === url.split('/').at(-1)); if(i>=0) notes.splice(i,1) }
    return new Response(JSON.stringify({ data: url.includes('/notes') ? notes : url.includes('/favorites') ? favorites : [] }))
  }
  return { fetch, requests, notes, favorites, set offline(v) { offline = v }, set failWrites(v) { failWrites = v } }
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

test('offline edits and removals replay against their saved baseline', async () => {
  const { Repo } = await setup(); const s = server()
  s.favorites.push({ id: 1, targetType: 'history_event', targetId: 'A' })
  s.notes.push({ id: 1, targetType: 'history_event', targetId: 'A', content: 'original' })
  const config = { accountId: 1, fetch: s.fetch, authHeaders: () => ({ Authorization: 'Bearer A' }) }
  const repo = new Repo(config); const original = await repo.load(); s.offline = true
  await repo.save({ ...original, bookmarks: [], notes: { A: 'offline edit' } }); assert.equal(repo.status.pendingSync, true); s.offline = false
  const loaded = await new Repo(config).load()
  assert.equal(loaded.notes.A, 'offline edit'); assert.deepEqual(Array.from(loaded.bookmarks), [])
  const next = new Repo(config); const saved = await next.load(); s.offline = true
  await next.save({ ...saved, notes: {} }); assert.equal(next.status.pendingSync, true); s.offline = false
  assert.deepEqual(Object.keys((await new Repo(config).load()).notes), [])
})
test('local read failures cannot become empty successful restores', async () => {
  const { Repo, stores } = await setup(); stores.get = () => { throw Error('storage unavailable') }
  const repo = new Repo({ accountId: 1, fetch: server().fetch, authHeaders: () => ({ Authorization: 'Bearer A' }) })
  await assert.rejects(repo.load(), /storage unavailable/)
})

test('partial remote success followed by newer offline edits keeps the newest note', async () => {
  const { Repo } = await setup(); const s = server()
  s.notes.push({ id: 1, targetType: 'history_event', targetId: 'A', content: 'original' })
  let failProgress = false
  const config = { accountId: 1, authHeaders: () => ({ Authorization: 'Bearer A' }), fetch: (url, init) => {
    if (failProgress && url.endsWith('/progress') && init.method === 'POST') throw Error('progress unavailable')
    return s.fetch(url, init)
  } }
  const repo = new Repo(config); const original = await repo.load()
  const view = { start: 1839, end: 2027, selectedId: null, topicIds: [], query: '', compareIds: [], archive: false }
  failProgress = true; await repo.save({ ...original, notes: { A: 'first edit' }, view }).catch(() => {})
  s.offline = true; await repo.save({ ...original, notes: { A: 'newest edit' }, view }).catch(() => {})
  failProgress = false; s.offline = false
  assert.equal((await new Repo(config).load()).notes.A, 'newest edit')
  assert.equal(s.notes[0].content, 'newest edit')
})
test('remote conflicts keep the local draft pending and exportable', async () => {
  const { Repo, stores } = await setup(); const s = server()
  s.notes.push({ id: 1, targetType: 'history_event', targetId: 'A', content: 'original' })
  const config = { accountId: 1, fetch: s.fetch, authHeaders: () => ({ Authorization: 'Bearer A' }) }
  const repo = new Repo(config); const original = await repo.load(); s.offline = true
  await repo.save({ ...original, notes: { A: 'offline draft' } }).catch(() => {})
  s.notes[0].content = 'other device'; s.offline = false
  const recovered = new Repo(config); const local = await recovered.load()
  assert.equal(local.notes.A, 'offline draft'); assert.equal(recovered.status.pendingSync, true)
  assert.match(recovered.status.warning, /冲突/)
  assert.ok(stores.get('history-study-guanlan-v2:user:1:study:pending-sync'))
  await recovered.save({ ...local, bookmarks: ['new bookmark'] }).catch(() => {})
  assert.equal(s.notes[0].content, 'other device')
  assert.equal(JSON.parse(await recovered.exportBackup()).state.notes.A, 'offline draft')
})


test('oversized notes remain intact locally and are never silently truncated remotely', async () => {
  const { Repo, stores } = await setup(); const s = server();
  const repo = new Repo({ accountId: 1, fetch: s.fetch, authHeaders: () => ({ Authorization: 'Bearer A' }) });
  await repo.load(); const draft=state(); draft.notes.A='x'.repeat(20001);
  const saved=await repo.save(draft);
  assert.equal(saved.notes.A.length,20001);
  assert.equal(repo.status.pendingSync,true);
  assert.equal(s.notes.length,0);
  assert.equal(stores.get('history-study-guanlan-v2:user:1:study:state').notes.A.length,20001);
});

for (const change of ['note', 'attempt']) test(`an older replay cannot clear another tab pending ${change}`, async () => {
  const { Repo, stores } = await setup(); const s = server();
  const config = { accountId: 1, authHeaders: () => ({ Authorization: 'Bearer A' }) };
  const online = new Repo({ ...config, fetch: s.fetch }); await online.load();
  s.offline = true; await online.save(state('A')); s.offline = false;
  let release, entered;
  const paused = new Promise(resolve => { entered = resolve });
  const gate = new Promise(resolve => { release = resolve });
  const replay = new Repo({ ...config, fetch: async (url, init) => {
    if (url.endsWith('/notes') && init.method === 'POST') { entered(); await gate; }
    return s.fetch(url, init);
  } });
  const older = replay.load(); await paused;
  const newer = state('A');
  if (change === 'note') newer.notes.A = 'newer draft from another tab';
  else newer.attempts = [{ id: 'new-attempt', mode: 'recall', eventIds: ['A'], correct: true, answer: 'A', createdAt: '2026-10-22T00:00:00Z' }];
  await new Repo({ ...config, fetch: async () => { throw Error('offline tab') } }).save(newer);
  release(); await older;
  const key = 'history-study-guanlan-v2:user:1:study:';
  assert.equal(stores.get(key + 'state').notes.A, newer.notes.A);
  assert.equal(stores.get(key + 'pending-sync').notes.A, newer.notes.A);
  assert.deepEqual(JSON.parse(JSON.stringify(stores.get(key + 'state').attempts)), newer.attempts);
  assert.equal(replay.status.pendingSync, true);
  assert.equal((await new Repo({ ...config, fetch: s.fetch }).load()).notes.A, newer.notes.A);
});

test('a stale tab note save preserves attempts added by another tab', async () => {
  const { Repo } = await setup();
  const config = { accountId: 1, authHeaders: () => ({ Authorization: 'Bearer A' }), fetch: async () => { throw Error('offline') } };
  const a = new Repo(config), b = new Repo(config);
  const first = { id: 'one', mode: 'recall', eventIds: ['A'], correct: true, answer: 'A', createdAt: '2026-10-22T00:00:00Z' };
  const second = { ...first, id: 'two' };
  await a.save({ ...state(), attempts: [first] });
  const stale = await a.load();
  await b.save({ ...stale, attempts: [...stale.attempts, second] });
  await a.save({ ...stale, notes: { A: 'another edit' } });
  assert.deepEqual(Array.from((await b.load()).attempts, item => item.id), ['one', 'two']);
});
