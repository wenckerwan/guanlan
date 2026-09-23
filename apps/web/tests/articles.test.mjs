import test from 'node:test'
import assert from 'node:assert/strict'
import { groupBy, moduleTone, outlineOf, sortByPriority, truncate } from '../utils/articles.mjs'

test('sorts by priority then period, newest first', () => {
  const items = [
    { title: 'B 级', priority: 'B', period: '2026年1月' },
    { title: 'S 级旧', priority: 'S', period: '2026年1月' },
    { title: 'S 级新', priority: 'S', period: '2026年9月' },
  ]
  assert.deepEqual(sortByPriority(items).map((item) => item.title), ['S 级新', 'S 级旧', 'B 级'])
})

test('does not mutate the input array when sorting', () => {
  const items = [{ priority: 'B' }, { priority: 'S' }]
  sortByPriority(items)
  assert.equal(items[0].priority, 'B')
})

test('groups items by a key and falls back to 其他', () => {
  const groups = groupBy([
    { period: '2026年9月', title: 'a' },
    { period: '2026年9月', title: 'b' },
    { title: 'c' },
  ], 'period')
  assert.equal(groups.length, 2)
  assert.equal(groups.find((group) => group.name === '2026年9月').items.length, 2)
  assert.equal(groups.find((group) => group.name === '其他').items.length, 1)
})

test('truncates long summaries and leaves short ones intact', () => {
  assert.equal(truncate('短文本', 40), '短文本')
  assert.equal(truncate('x'.repeat(50), 10), `${'x'.repeat(10)}…`)
  assert.equal(truncate(null, 10), '')
})

test('extracts an outline from rendered html as a fallback', () => {
  const html = '<h2>第一章</h2><p>正文</p><h3>第一节</h3><h2>第二章</h2>'
  assert.deepEqual(outlineOf(html), [
    { level: 2, title: '第一章' },
    { level: 3, title: '第一节' },
    { level: 2, title: '第二章' },
  ])
})

test('maps modules to stable tones', () => {
  assert.equal(moduleTone('马原'), 'jade')
  assert.equal(moduleTone('毛中特'), 'red')
  assert.equal(moduleTone('未知模块'), 'jade')
})
test('sortByPriority puts the release version ahead of the working draft', () => {
  const items = [
    { slug: 'draft', priority: 'A', release: false },
    { slug: 'release', priority: 'A', release: true },
    { slug: 's-level', priority: 'S', release: false },
  ]
  const sorted = sortByPriority(items)
  assert.equal(sorted[0].slug, 's-level')
  assert.equal(sorted[1].slug, 'release')
  assert.equal(sorted[2].slug, 'draft')
})

test('sortByPriority does not mutate the input array', () => {
  const items = [
    { slug: 'b', priority: 'B' },
    { slug: 's', priority: 'S' },
  ]
  const sorted = sortByPriority(items)
  assert.equal(items[0].slug, 'b')
  assert.equal(sorted[0].slug, 's')
})
