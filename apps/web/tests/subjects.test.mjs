import test from 'node:test'
import assert from 'node:assert/strict'
import { getSubjectBySlug, validateSubjects } from '../utils/subjects.mjs'

const fixture = [
  { slug: 'marxism', name: '马克思主义基本原理' },
  { slug: 'world-politics', name: '当代世界经济与政治' },
]

test('validates unique subject slugs', () => {
  assert.deepEqual(validateSubjects(fixture), { valid: true, errors: [] })
})

test('finds a subject by slug and returns undefined for an unknown slug', () => {
  assert.equal(getSubjectBySlug(fixture, 'marxism').name, '马克思主义基本原理')
  assert.equal(getSubjectBySlug(fixture, 'missing'), undefined)
})

test('reports duplicate slugs', () => {
  const result = validateSubjects([
    { slug: 'same', name: '甲' },
    { slug: 'same', name: '乙' },
  ])

  assert.equal(result.valid, false)
  assert.deepEqual(result.errors, ['重复的学科 slug：same'])
})
