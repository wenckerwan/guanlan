import test from 'node:test'
import assert from 'node:assert/strict'
import {
  SEARCH_RESULT_FALLBACK,
  SEARCH_TYPES,
  groupCounts,
  isEmptyResult,
  normalizeKeyword,
  resolveHitUrl,
  typeLabel,
} from '../utils/search.mjs'

test('normalizeKeyword trims, collapses whitespace and caps at 60 chars', () => {
  assert.equal(normalizeKeyword('  遵义   会议  '), '遵义 会议')
  assert.equal(normalizeKeyword(null), '')
  assert.equal(normalizeKeyword('あ'.repeat(80)).length, 60)
})

test('typeLabel maps every backend type to a Chinese label', () => {
  for (const item of SEARCH_TYPES) {
    assert.equal(typeLabel(item.value), item.label)
  }
  assert.equal(typeLabel('question'), '真题')
  assert.equal(typeLabel('unknown'), 'unknown')
})

test('groupCounts recomputes counts from items and ignores blanks', () => {
  const counts = groupCounts({
    items: [
      { type: 'question' },
      { type: 'question' },
      { type: 'hotspot' },
      { type: '' },
      null,
    ],
  })
  assert.deepEqual(counts, { question: 2, hotspot: 1 })
})

test('groupCounts returns an empty object for malformed payloads', () => {
  assert.deepEqual(groupCounts(null), {})
  assert.deepEqual(groupCounts({ items: 'nope' }), {})
  assert.deepEqual(groupCounts(SEARCH_RESULT_FALLBACK), {})
})

test('isEmptyResult detects empty and malformed payloads', () => {
  assert.equal(isEmptyResult(SEARCH_RESULT_FALLBACK), true)
  assert.equal(isEmptyResult(null), true)
  assert.equal(isEmptyResult({ items: [{ type: 'paper' }] }), false)
})

test('resolveHitUrl keeps server routes and rejects anything off-site', () => {
  assert.equal(resolveHitUrl({ url: '/papers/2026-1' }), '/papers/2026-1')
  assert.equal(resolveHitUrl({ url: '//evil.example.com' }), '/search?q=')
  assert.equal(resolveHitUrl({ url: 'https://example.com', title: '十五五' }), '/search?q=%E5%8D%81%E4%BA%94%E4%BA%94')
  assert.equal(resolveHitUrl({ title: '遵义会议' }), '/search?q=%E9%81%B5%E4%B9%89%E4%BC%9A%E8%AE%AE')
  assert.equal(resolveHitUrl(null), '/search?q=')
})
const searchPage = await import('node:fs/promises').then(fs => fs.readFile(new URL('../pages/search.vue', import.meta.url), 'utf8'))
test('search uses a reactive URL and restores keyword and type from navigation', () => {
  assert.match(searchPage, /const requestPath = computed/)
  assert.match(searchPage, /useApiFetch<SearchResult>\(\s*requestPath/)
  assert.match(searchPage, /route.query.type/)
  assert.match(searchPage, /activeType.value = String\(route.query.type/)
})
test('search distinguishes loading and errors before the no-results state', () => {
  assert.match(searchPage, /v-if="pending"/)
  assert.match(searchPage, /v-else-if="error"/)
  assert.match(searchPage, /result.truncated/)
  assert.equal(typeLabel('concept'), '马原概念')
  assert.equal(typeLabel('history'), '史纲事件')
})

test('live search script updates requests for submit, filter and browser navigation', async () => {
  const { transform } = await import('esbuild')
  const { reactive, ref, computed, watch, nextTick } = await import('vue')
  const route = reactive({ query: {} })
  const history = []
  const router = { push(location) { history.push(location); route.query = location.query } }
  let requestPath
  const api = async (path, fallback) => {
    requestPath = path
    return { data: ref(fallback), pending: ref(false), error: ref(null), refresh() {} }
  }
  const script = searchPage.match(/<script setup lang="ts">([\s\S]*?)<\/script>/)[1].replace(/^import .*$/gm, '')
  const compiled = await transform(script, { loader: 'ts', target: 'es2022' })
  const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor
  const create = new AsyncFunction('computed', 'ref', 'watch', 'useRoute', 'useRouter', 'useApiFetch', 'useHead', 'withQuery', 'SEARCH_TYPES', 'normalizeKeyword', compiled.code + '\nreturn { keyword, activeType, submitted, submit, pickType }')
  const ui = await create(computed, ref, watch, () => route, () => router, api, () => {},
    (path, params) => path + '?' + new URLSearchParams(params), SEARCH_TYPES, normalizeKeyword)
  ui.keyword.value = ' 矛盾 '
  ui.submit(); await nextTick()
  assert.equal(new URL(requestPath.value, 'https://test').searchParams.get('q'), '矛盾')
  ui.pickType('concept'); await nextTick()
  assert.equal(new URL(requestPath.value, 'https://test').searchParams.get('type'), 'concept')
  // Router popstate restores both query fields, including removing an old filter.
  route.query = history[0].query; await nextTick()
  assert.equal(ui.activeType.value, '')
  assert.equal(new URL(requestPath.value, 'https://test').searchParams.get('type'), '')
  route.query = { q: '遵义会议', type: 'history' }; await nextTick()
  assert.equal(ui.keyword.value, '遵义会议')
  assert.equal(ui.activeType.value, 'history')
  assert.equal(new URL(requestPath.value, 'https://test').searchParams.get('q'), '遵义会议')
})
