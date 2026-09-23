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