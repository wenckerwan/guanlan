import test from 'node:test'
import assert from 'node:assert/strict'
import { conceptIdFromUrl, withConceptId } from '../app/deep-link.mjs'

test('reads a stable concept id from a Mayuan URL', () => {
  assert.equal(conceptIdFromUrl('/mayuan/?concept=contradiction'), 'contradiction')
  assert.equal(conceptIdFromUrl('/mayuan/?concept=missing%20space'), 'missing space')
  assert.equal(conceptIdFromUrl('/mayuan/'), null)
})

test('writes or clears only the concept query while preserving the rest', () => {
  assert.equal(
    withConceptId('/mayuan/?view=map#main', 'practice'),
    '/mayuan/?view=map&concept=practice#main',
  )
  assert.equal(withConceptId('/mayuan/?view=map&concept=practice#main', null), '/mayuan/?view=map#main')
})
