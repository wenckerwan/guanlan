import test from 'node:test'
import assert from 'node:assert/strict'
import { isCommentsEnabled } from '../utils/comments.mjs'

test('comments are disabled unless the feature flag is explicitly true', () => {
  assert.equal(isCommentsEnabled(undefined), false)
  assert.equal(isCommentsEnabled('false'), false)
  assert.equal(isCommentsEnabled('1'), false)
  assert.equal(isCommentsEnabled(true), true)
  assert.equal(isCommentsEnabled('true'), true)
})
