import test from 'node:test'
import assert from 'node:assert/strict'
import { unwrapEnvelope, toHomeSummary } from '../utils/api.mjs'
import { buildHomeSummary } from '../utils/home.mjs'
import { validateSubjects } from '../utils/subjects.mjs'

const subjectEnvelope = {
  data: [
    { slug: 'marxism', name: '马克思主义基本原理' },
    { slug: 'world-politics', name: '当代世界经济与政治' },
  ],
}

const homeEnvelope = {
  data: {
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

test('toHomeSummary bounds the lists', () => {
  const summary = toHomeSummary(homeEnvelope)
  assert.ok(summary.subjects.length <= 6)
  assert.equal(summary.documents.length, 1)
})

test('toHomeSummary returns empty collections for a dead API', () => {
  const summary = toHomeSummary(null)
  assert.deepEqual(summary, { subjects: [], documents: [] })
})

test('buildHomeSummary still bounds a large payload', () => {
  const summary = buildHomeSummary({
    subjects: Array.from({ length: 10 }, (_, i) => ({ slug: `s${i}`, name: `学科${i}`, count: i })),
  })
  assert.equal(summary.subjects.length, 6)
})
