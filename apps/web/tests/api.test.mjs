import test from 'node:test'
import assert from 'node:assert/strict'
import { unwrapEnvelope, toHomeSummary } from '../utils/api.mjs'
import { buildHomeSummary } from '../utils/home.mjs'
import { validateSubjects } from '../utils/subjects.mjs'

const subjectEnvelope = {
  data: [
    { slug: 'marxism', name: '马克思主义基本原理', hotspots: ['人工智能与科技自立自强'] },
    { slug: 'world-politics', name: '当代世界经济与政治', hotspots: [] },
  ],
}

const homeEnvelope = {
  data: {
    hotspots: [
      { title: 'A级热点', level: 'A', updatedAt: '2026-09-06' },
      { title: 'S级热点', level: 'S', updatedAt: '2026-09-01' },
    ],
    documents: [{ title: '资料卡', meta: '第一章', progress: 36, cover: '马原', tone: 'jade' }],
    subjects: [{ slug: 'marxism', name: '马原', count: 3 }],
  },
}

test('unwrapEnvelope returns the data payload', () => {
  const payload = { data: { foo: 'bar' } }
  assert.deepEqual(unwrapEnvelope(payload, null), { foo: 'bar' })
})

test('unwrapEnvelope returns the fallback for null or malformed payloads', () => {
  assert.equal(unwrapEnvelope(null, 'safe'), 'safe')
  assert.equal(unwrapEnvelope(undefined, 'safe'), 'safe')
  assert.equal(unwrapEnvelope({}, 'safe'), 'safe')
  assert.deepEqual(unwrapEnvelope({ data: null }, { ok: true }), { ok: true })
})

test('unwrapped subjects payload passes validation', () => {
  const subjects = unwrapEnvelope(subjectEnvelope, [])
  assert.equal(validateSubjects(subjects).valid, true)
})

test('toHomeSummary sorts S-level first and bounds the lists', () => {
  const summary = toHomeSummary(homeEnvelope)
  assert.equal(summary.hotspots[0].title, 'S级热点')
  assert.ok(summary.hotspots.length <= 4)
  assert.ok(summary.subjects.length <= 6)
  assert.equal(summary.documents.length, 1)
})

test('toHomeSummary returns empty collections for a dead API', () => {
  const summary = toHomeSummary(null)
  assert.deepEqual(summary, { hotspots: [], subjects: [], documents: [] })
})

test('buildHomeSummary still bounds a large payload', () => {
  const summary = buildHomeSummary({
    hotspots: Array.from({ length: 10 }, (_, i) => ({ title: `热点 ${i}`, level: 'S', updatedAt: '2026-09-06' })),
    subjects: Array.from({ length: 10 }, (_, i) => ({ slug: `s${i}`, name: `学科${i}`, count: i })),
  })
  assert.equal(summary.hotspots.length, 4)
  assert.equal(summary.subjects.length, 6)
})
