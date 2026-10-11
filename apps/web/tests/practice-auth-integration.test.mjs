import test from 'node:test'
import assert from 'node:assert/strict'
import vm from 'node:vm'
import { buildSync } from 'esbuild'
import { webcrypto } from 'node:crypto'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import { enqueueAttempt, outboxKey } from '../lib/practice-study.mjs'

const webRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')

function loadComposables({ conflictOld = false } = {}) {
  const callbacks = new Map()
  const hooks = []
  const values = new Map()
  const rows = new Map()
  const localStorage = {
    get length() { return rows.size }, key: index => [...rows.keys()][index] ?? null,
    getItem: key => rows.get(key) ?? null, setItem: (key, value) => rows.set(key, value), removeItem: key => rows.delete(key),
  }
  const requests = []
  const users = { A: { id: 7, role: 'user' }, B: { id: 8, role: 'user' } }
  const question = { questionRef: 'SZ08-1', questionVersion: 'q2', type: 'single', module: 'sx', stem: '题', options: { A: '甲', B: '乙', C: '丙', D: '丁' }, answer: 'C' }
  const sandbox = {
    module: { exports: {} }, exports: {}, document: { cookie: 'guanlan.token=A' }, localStorage,
    window: { location: { protocol: 'https:' }, addEventListener: (name, fn) => callbacks.set(name, fn), removeEventListener: () => {} },
    crypto: webcrypto, console,
    ref: value => ({ value }), shallowRef: value => ({ value }),
    computed: fn => ({ get value() { return fn() } }), watch: () => {}, onMounted: fn => hooks.push(fn), onBeforeUnmount: () => {},
    useRuntimeConfig: () => ({ public: { apiBase: '/api/v1' }, apiInternalBase: '/api/v1' }),
    useState: (name, create) => { if (!values.has(name)) values.set(name, { value: create() }); return values.get(name) },
    $fetch: async (url, options = {}) => {
      const bearer = options.headers?.Authorization?.replace('Bearer ', '') ?? ''
      requests.push({ url, bearer, body: options.body })
      if (url === '/auth/me') return { data: { user: users[bearer] } }
      if (url === '/practice/banks/current-affairs-2026') return { data: { bankId: 'current-affairs-2026', contentVersion: 'v2', questionCount: 1, questions: [question] } }
      if (url === '/study/practice/banks/current-affairs-2026/state') return { data: { questions: {} } }
      if (url.startsWith('/study/favorites')) return { data: [] }
      if (url === '/study/practice/attempts') {
        if (conflictOld && options.body.questionVersion === 'q1') throw Object.assign(new Error('conflict'), { statusCode: 409 })
        return { data: { attemptId: 1, isRight: true, correct: ['C'], duplicate: true, questionState: { attemptCount: 1, lastIsRight: true, lastChosen: ['C'], lastCorrect: ['C'] } } }
      }
      throw new Error(`Unexpected request ${url}`)
    },
  }
  const bundles = buildSync({ entryPoints: [path.join(webRoot, 'composables/useAuth.ts'), path.join(webRoot, 'composables/usePracticeStudy.ts')], bundle: true, write: false, outdir: 'out', platform: 'node', format: 'cjs', define: { 'import.meta.client': 'true', 'import.meta.server': 'false' }, alias: { '~': webRoot }, logLevel: 'silent' }).outputFiles
  for (const bundle of bundles) {
    sandbox.module = { exports: {} }; sandbox.exports = sandbox.module.exports
    vm.runInNewContext(bundle.text, sandbox, { filename: bundle.path })
    if (bundle.path.endsWith('useAuth.js')) sandbox.useAuth = sandbox.module.exports.useAuth
    if (bundle.path.endsWith('usePracticeStudy.js')) sandbox.usePracticeStudy = sandbox.module.exports.usePracticeStudy
  }
  return { sandbox, callbacks, hooks, localStorage, requests }
}

test('real useAuth interface revalidates account before retrying a versioned queued event', async () => {
  const { sandbox, callbacks, hooks, localStorage, requests } = loadComposables()
  const oldEvent = { bankId: 'current-affairs-2026', questionRef: 'SZ08-1', questionVersion: 'q1', chosen: ['C'], eventId: '00000000-0000-4000-8000-000000000007' }
  enqueueAttempt(localStorage, outboxKey(7, 'current-affairs-2026', 'v1'), oldEvent)
  const study = sandbox.usePracticeStudy()
  for (const hook of hooks) hook()
  await study.load()
  assert.equal(study.pending.value.length, 1)
  sandbox.document.cookie = 'guanlan.token=B'
  await study.submit(study.bank.value.questions[0], ['C'])
  assert.equal(study.identity.value, '8')
  assert.equal(study.pending.value.length, 0)
  await study.retryPending()
  assert.equal(requests.filter(item => item.url === '/study/practice/attempts').length, 0)
  sandbox.document.cookie = 'guanlan.token=A'
  await callbacks.get('focus')()
  await study.retryPending()
  const post = requests.find(item => item.url === '/study/practice/attempts')
  assert.equal(post.bearer, 'A')
  assert.equal(post.body.eventId, oldEvent.eventId)
  assert.equal(post.body.questionVersion, 'q1')
  assert.equal(study.pending.value.length, 0)
})

test('a stale version conflict stays visible until explicitly discarded', async () => {
  const { sandbox, hooks, localStorage, requests } = loadComposables({ conflictOld: true })
  const oldEvent = { bankId: 'current-affairs-2026', questionRef: 'SZ08-1', questionVersion: 'q1', chosen: ['A'], eventId: '00000000-0000-4000-8000-000000000008' }
  enqueueAttempt(localStorage, outboxKey(7, 'current-affairs-2026', 'v1'), oldEvent)
  const study = sandbox.usePracticeStudy()
  for (const hook of hooks) hook()
  await study.load()
  await study.retryPending()
  assert.equal(study.pending.value[0].conflict, true)
  await study.retryPending()
  assert.equal(requests.filter(item => item.url === '/study/practice/attempts').length, 1)
  study.discardPending(oldEvent.eventId)
  assert.equal(study.pending.value.length, 0)
  await study.submit(study.bank.value.questions[0], ['C'])
  const posts = requests.filter(item => item.url === '/study/practice/attempts')
  assert.equal(posts.length, 2)
  assert.equal(posts[1].body.questionVersion, 'q2')
})
