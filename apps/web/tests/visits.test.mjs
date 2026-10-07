import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'

function browser({ path = '/', hostname = 'example.test', fail = false, hidden = false, rejectPost = false } = {}) {
  const calls = [], listeners = {}, events = []
  const context = {
    location: { hostname, pathname: path, protocol: 'https:' },
    document: { visibilityState: hidden ? 'hidden' : 'visible', currentScript: { dataset: {} } },
    navigator: { onLine: true, locks: { request: async (_, fn) => fn() } },
    AbortSignal, URL, setTimeout, clearTimeout,
    CustomEvent: class { constructor(type, init) { this.type = type; this.detail = init.detail } },
    fetch: async (url, options) => {
      calls.push({ url, ...options })
      if (fail) throw new Error('offline')
      return { ok: !(rejectPost && options.method === 'POST'), json: async () => ({ data: { total: 1234, today: 12, date: '2026-10-07', startedAt: '2026-10-07' } }) }
    },
    addEventListener: (type, fn) => { listeners[type] = fn },
    dispatchEvent: event => { events.push(event) },
  }
  context.window = context
  vm.runInNewContext(readFileSync(new URL('../public/visit-tracker.js', import.meta.url), 'utf8'), context)
  return { context, calls, events, listeners }
}
const settle = () => new Promise(resolve => setTimeout(resolve, 15))

test('bootstraps cookie before POST and publishes counts without exposing identity', async () => {
  const b = browser(); await settle()
  assert.deepEqual(b.calls.map(c => [c.url, c.method]), [['/api/v1/stats/visits', 'GET'], ['/api/v1/stats/visit', 'POST']])
  assert.equal(b.calls.every(c => c.credentials === 'same-origin' && c.cache === 'no-store'), true)
  assert.equal(b.context.GuanlanVisits.total, 1234)
  assert.equal(b.events.at(-1).type, 'guanlan:visits')
})
test('route changes register activity, not dwell-time ticks', async () => {
  const b = browser(); await settle()
  b.context.location.pathname = '/history/'
  b.listeners['guanlan:navigation'](); await settle()
  assert.equal(b.calls.filter(c => c.method === 'POST').length, 2)
})
test('admin, offline, development and invisible pages never register', async () => {
  for (const options of [{ path: '/admin/users' }, { path: '/offline' }, { hostname: 'localhost' }, { hostname: '127.0.0.1' }]) {
    const b = browser(options); await settle(); assert.equal(b.calls.length, 0)
  }
})
test('bootstrap failure never posts or fabricates zeros', async () => {
  const b = browser({ fail: true }); await settle()
  assert.equal(b.calls.length, 1)
  assert.equal(b.context.GuanlanVisits, null)
})
test('concurrent events in one tab coalesce while a report is pending', async () => {
  const b = browser()
  b.listeners['guanlan:navigation'](); b.listeners['guanlan:navigation']()
  await settle(); assert.equal(b.calls.filter(c => c.method === 'POST').length, 1)
})
test('hidden initial page waits for visibility before registering', async () => {
  const b = browser({ hidden: true }); await settle()
  assert.equal(b.calls.length, 0)
  b.context.document.visibilityState = 'visible'
  b.listeners.visibilitychange(); await settle()
  assert.equal(b.calls.filter(c => c.method === 'POST').length, 1)
})
test('cookie rejection clears displayed data instead of suggesting successful counting', async () => {
  const b = browser({ rejectPost: true }); await settle()
  assert.equal(b.context.GuanlanVisits, null)
  assert.equal(b.calls.length, 2)
})
