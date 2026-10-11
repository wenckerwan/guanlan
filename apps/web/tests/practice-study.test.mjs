import test from 'node:test'
import assert from 'node:assert/strict'
import { filterPracticeQuestions, pagePracticeQuestions, outboxKey, enqueueAttempt, removeAttempt, readOutbox, readBankOutbox, markAttemptConflict, isPracticeBank } from '../lib/practice-study.mjs'

const questions = [
  { questionRef: 'A', module: 'sx', type: 'single', stem: '市场', options: { A: '甲' } },
  { questionRef: 'B', module: 'xs', type: 'multi', stem: '生态', options: { A: '乙' } },
  { questionRef: 'C', module: 'sx', type: 'multi', stem: '教育', options: { A: '丙' } },
]

test('combined module, type, status and keyword filters retain source order', () => {
  const result = filterPracticeQuestions(questions, { module: 'sx', type: 'multi', status: 'wrong', keyword: '教育' }, { C: { lastIsRight: false } }, [])
  assert.deepEqual(result.map(q => q.questionRef), ['C'])
})

test('six-item pagination clamps after a shrinking filter', () => {
  assert.deepEqual(pagePracticeQuestions(Array.from({ length: 13 }, (_, i) => i), 3), { items: [12], page: 3, pages: 3, total: 13 })
  assert.deepEqual(pagePracticeQuestions([1, 2], 3), { items: [1, 2], page: 1, pages: 1, total: 2 })
})

test('outbox is isolated by user and content version; retry keeps event UUID', () => {
  const store = new Map()
  const storage = { get length() { return store.size }, key: i => [...store.keys()][i] ?? null, getItem: key => store.get(key) ?? null, setItem: (key, value) => store.set(key, value), removeItem: key => store.delete(key) }
  const key = outboxKey(7, 'bank', 'v1')
  const event = { bankId: 'bank', questionRef: 'A', questionVersion: 'q1', chosen: ['C'], eventId: 'uuid-1' }
  enqueueAttempt(storage, key, event)
  enqueueAttempt(storage, key, event)
  const other = { ...event, questionRef: 'B', eventId: 'uuid-2' }
  enqueueAttempt(storage, key, other)
  assert.deepEqual(readOutbox(storage, key), [event, other])
  assert.deepEqual(readOutbox(storage, outboxKey(8, 'bank', 'v1')), [])
  assert.deepEqual(readOutbox(storage, outboxKey(7, 'bank', 'v2')), [])
  removeAttempt(storage, key, 'uuid-1')
  assert.deepEqual(readOutbox(storage, key), [other])
  const historical = { ...event, eventId: 'uuid-old' }
  enqueueAttempt(storage, outboxKey(7, 'bank', 'v0'), historical)
  assert.deepEqual(readBankOutbox(storage, 7, 'bank').map(item => item.eventId), ['uuid-2', 'uuid-old'])
  assert.deepEqual(readBankOutbox(storage, 8, 'bank'), [])
  markAttemptConflict(storage, outboxKey(7, 'bank', 'v0'), 'uuid-old')
  assert.equal(readBankOutbox(storage, 7, 'bank').find(item => item.eventId === 'uuid-old').conflict, true)
})

test('redirected HTML or malformed API payload cannot become a question bank', () => {
  assert.equal(isPracticeBank('<html>login</html>'), false)
  assert.equal(isPracticeBank({ bankId: 'b', contentVersion: 'v', questionCount: 1, questions: [{}] }), false)
  assert.equal(isPracticeBank({ bankId: 'b', contentVersion: 'v', questionCount: 1, questions: [{ questionRef: 'A', questionVersion: 'q', type: 'multi', module: 'sx', stem: '题', options: { A: '甲', B: '乙', C: '丙', D: '丁' } }] }), true)
})

test('denied browser storage does not fabricate pending events', () => {
  const storage = { get length() { throw new Error('blocked') } }
  assert.deepEqual(readOutbox(storage, outboxKey(7, 'bank', 'v1')), [])
})
