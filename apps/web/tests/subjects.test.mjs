import test from 'node:test'
import assert from 'node:assert/strict'
import { getSubjectBySlug, validateSubjects } from '../utils/subjects.mjs'

const fixture = [
  { slug: 'marxism', name: '马克思主义基本原理', hotspots: ['practice'] },
  { slug: 'world-politics', name: '当代世界经济与政治', hotspots: ['apec'] },
]

test('validates unique subject slugs and hotspot mappings', () => {
  assert.deepEqual(validateSubjects(fixture), { valid: true, errors: [] })
})

test('finds a subject by slug and returns undefined for an unknown slug', () => {
  assert.equal(getSubjectBySlug(fixture, 'marxism').name, '马克思主义基本原理')
  assert.equal(getSubjectBySlug(fixture, 'missing'), undefined)
})

test('reports duplicate slugs and missing hotspot mappings', () => {
  const result = validateSubjects([
    { slug: 'same', name: '甲', hotspots: ['known'] },
    { slug: 'same', name: '乙', hotspots: [''] },
  ])

  assert.equal(result.valid, false)
  assert.deepEqual(result.errors, ['重复的学科 slug：same', '学科乙存在空的热点映射'])
})
