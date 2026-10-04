import test from 'node:test'
import assert from 'node:assert/strict'
import { buildHomeSummary } from '../utils/home.mjs'

test('builds a bounded home summary for the first viewport', () => {
  const summary = buildHomeSummary({
    subjects: [{ name: '马原', count: 3 }],
  })

  assert.deepEqual(summary.subjects, [{ name: '马原', count: 3 }])
})
