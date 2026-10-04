import test from 'node:test'
import assert from 'node:assert/strict'
import { buildHomeSummary, sortHotspots } from '../utils/home.mjs'

test('sorts hotspots by priority, then freshness', () => {
  const items = [
    { title: '旧重点', level: 'A', updatedAt: '2026-09-06' },
    { title: 'S级旧热点', level: 'S', updatedAt: '2026-09-01' },
    { title: 'S级新热点', level: 'S', updatedAt: '2026-09-06' },
  ]

  assert.deepEqual(sortHotspots(items).map((item) => item.title), [
    'S级新热点',
    'S级旧热点',
    '旧重点',
  ])
})

test('builds a bounded home summary for the first viewport', () => {
  const summary = buildHomeSummary({
    hotspots: Array.from({ length: 8 }, (_, index) => ({
      title: `热点 ${index}`,
      level: 'A',
      updatedAt: '2026-09-06',
    })),
    subjects: [{ name: '马原', count: 3 }],
  })

  assert.equal(summary.hotspots.length, 4)
  assert.deepEqual(summary.subjects, [{ name: '马原', count: 3 }])
})
