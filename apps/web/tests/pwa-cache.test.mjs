import test from 'node:test'
import assert from 'node:assert/strict'
import {
  CACHE_STRATEGIES,
  SWR_MAX_ENTRIES,
  SWR_MAX_AGE_SECONDS,
  cacheStrategy,
  isApiPath,
} from '../utils/sw-cache-rules.mjs'

const { PRECACHE, SWR, NETWORK_FIRST, NETWORK_ONLY } = CACHE_STRATEGIES

test('classifies public read-only content GETs as stale-while-revalidate', () => {
  assert.equal(cacheStrategy('/api/v1/home'), SWR)
  assert.equal(cacheStrategy('/api/v1/subjects'), SWR)
  assert.equal(cacheStrategy('/api/v1/subjects/mayuan'), SWR)
  assert.equal(cacheStrategy('/api/v1/papers'), SWR)
  assert.equal(cacheStrategy('/api/v1/papers/2024'), SWR)
  assert.equal(cacheStrategy('/api/v1/questions'), SWR)
  assert.equal(cacheStrategy('/api/v1/questions/1479'), SWR)
  assert.equal(cacheStrategy('/api/v1/analysis'), SWR)
  assert.equal(cacheStrategy('/api/v1/analysis/kaoyan-zhengzhi'), SWR)
  assert.equal(cacheStrategy('/api/v1/hotspots'), SWR)
  assert.equal(cacheStrategy('/api/v1/hotspots/2026-09'), SWR)
  assert.equal(cacheStrategy('/api/v1/predictions'), SWR)
  assert.equal(cacheStrategy('/api/v1/predictions/p1'), SWR)
  assert.equal(cacheStrategy('/api/v1/mocks'), SWR)
  assert.equal(cacheStrategy('/api/v1/mocks/mock-1'), SWR)
  assert.equal(cacheStrategy('/api/v1/history/events'), SWR)
})

test('never caches credential-bearing, user-specific or quota-relevant APIs', () => {
  assert.equal(cacheStrategy('/api/v1/auth/me'), NETWORK_ONLY)
  assert.equal(cacheStrategy('/api/v1/auth/login'), NETWORK_ONLY)
  assert.equal(cacheStrategy('/api/v1/study/favorites'), NETWORK_ONLY)
  assert.equal(cacheStrategy('/api/v1/study/progress'), NETWORK_ONLY)
  assert.equal(cacheStrategy('/api/v1/study/stats'), NETWORK_ONLY)
  assert.equal(cacheStrategy('/api/v1/mistakes/students'), NETWORK_ONLY)
  assert.equal(cacheStrategy('/api/v1/mistakes/students/A/items?page=1'), NETWORK_ONLY)
  assert.equal(cacheStrategy('/api/v1/mistakes/analysis-reports/12'), NETWORK_ONLY)
  assert.equal(cacheStrategy('/api/v1/mistakes/ai-config'), NETWORK_ONLY)
  assert.equal(cacheStrategy('/api/v1/comments'), NETWORK_ONLY)
  assert.equal(cacheStrategy('/api/v1/stats/heartbeat'), NETWORK_ONLY)
  assert.equal(cacheStrategy('/api/v1/stats/overview'), NETWORK_ONLY)
  assert.equal(cacheStrategy('/api/v1/search?q=%E7%9C%9F%E9%A2%98'), NETWORK_ONLY)
})

test('classifies every write method as network-only regardless of path', () => {
  assert.equal(cacheStrategy('/api/v1/papers', 'POST'), NETWORK_ONLY)
  assert.equal(cacheStrategy('/api/v1/study/attempts', 'POST'), NETWORK_ONLY)
  assert.equal(cacheStrategy('/api/v1/mistakes/items/3/review', 'PATCH'), NETWORK_ONLY)
  assert.equal(cacheStrategy('/api/v1/mistakes/items/3', 'DELETE'), NETWORK_ONLY)
  assert.equal(cacheStrategy('/api/v1/comments/9', 'DELETE'), NETWORK_ONLY)
})

test('treats HEAD like GET for read-only content', () => {
  assert.equal(cacheStrategy('/api/v1/papers', 'HEAD'), SWR)
  assert.equal(cacheStrategy('/api/v1/auth/me', 'HEAD'), NETWORK_ONLY)
})

test('requires a segment boundary when matching prefixes', () => {
  assert.notEqual(cacheStrategy('/api/v1/analysis-foo'), SWR)
  assert.equal(cacheStrategy('/api/v1/analysis-foo'), NETWORK_ONLY)
  assert.notEqual(cacheStrategy('/api/v1/notes'), SWR)
  assert.equal(cacheStrategy('/api/v1/subject-notes'), NETWORK_ONLY)
})

test('defaults unknown API paths to network-only', () => {
  assert.equal(cacheStrategy('/api/v1/totally-new-endpoint'), NETWORK_ONLY)
  assert.equal(cacheStrategy('/api/v1/history/other'), NETWORK_ONLY)
  assert.equal(cacheStrategy('/api/v1'), NETWORK_ONLY)
})

test('classifies non-API same-origin paths by asset type', () => {
  assert.equal(cacheStrategy('/_nuxt/entry.a1b2c3.js'), PRECACHE)
  assert.equal(cacheStrategy('/_nuxt/entry.a1b2c3.css'), PRECACHE)
  assert.equal(cacheStrategy('/'), NETWORK_FIRST)
  assert.equal(cacheStrategy('/papers'), NETWORK_FIRST)
  assert.equal(cacheStrategy('/mistakes/analyze/A'), NETWORK_FIRST)
  assert.equal(cacheStrategy('/favicon.ico'), NETWORK_FIRST)
})

test('isApiPath matches only versioned API roots', () => {
  assert.equal(isApiPath('/api/v1/questions'), true)
  assert.equal(isApiPath('/api/v1'), true)
  assert.equal(isApiPath('/api/v2/content'), false)
  assert.equal(isApiPath('/api'), false)
  assert.equal(isApiPath(''), false)
  assert.equal(isApiPath(undefined), false)
})

test('is robust against malformed input', () => {
  assert.equal(cacheStrategy('', 'GET'), NETWORK_FIRST)
  assert.equal(cacheStrategy(undefined, undefined), NETWORK_FIRST)
  assert.equal(cacheStrategy('/api/v1/papers', 'get'), SWR)
  assert.equal(cacheStrategy('/api/v1/papers', null), SWR)
  assert.equal(cacheStrategy('/api/v1/papers', 123), NETWORK_ONLY)
})

test('keeps runtime cache limits within plan budgets', () => {
  assert.equal(SWR_MAX_ENTRIES, 200)
  assert.equal(SWR_MAX_AGE_SECONDS, 604800)
})
