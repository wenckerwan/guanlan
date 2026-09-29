import { test } from 'node:test'
import assert from 'node:assert/strict'
import { lastNDays, fillTrend, trendTotal } from '../utils/trend.mjs'

test('lastNDays returns n consecutive dates ending today', () => {
  const days = lastNDays(new Date('2026-09-29T12:00:00'), 14)
  assert.equal(days.length, 14)
  assert.equal(days[13], '2026-09-29')
  assert.equal(days[0], '2026-09-16')
  // 跨月边界
  const cross = lastNDays(new Date('2026-03-02T08:00:00'), 3)
  assert.deepEqual(cross, ['2026-02-28', '2026-03-01', '2026-03-02'])
})

test('lastNDays zero-pads month and day', () => {
  const days = lastNDays(new Date('2026-01-03T00:00:00'), 2)
  assert.deepEqual(days, ['2026-01-02', '2026-01-03'])
})

test('fillTrend fills missing days with zero and ignores out-of-window keys', () => {
  const days = ['2026-09-28', '2026-09-29']
  const series = fillTrend({ '2026-09-29': 3, '2026-09-20': 99 }, days)
  assert.deepEqual(series, [
    { day: '2026-09-28', count: 0 },
    { day: '2026-09-29', count: 3 },
  ])
})

test('fillTrend tolerates null/undefined input', () => {
  assert.deepEqual(fillTrend(null, ['2026-09-29']), [{ day: '2026-09-29', count: 0 }])
  assert.deepEqual(fillTrend(undefined, ['2026-09-29']), [{ day: '2026-09-29', count: 0 }])
})

test('trendTotal sums counts and handles bad values', () => {
  assert.equal(trendTotal([{ count: 2 }, { count: '3' }, { count: null }, {}]), 5)
  assert.equal(trendTotal(null), 0)
  assert.equal(trendTotal([]), 0)
})
